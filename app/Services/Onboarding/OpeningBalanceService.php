<?php

namespace App\Services\Onboarding;

use App\Enums\BalanceSide;
use App\Enums\OpeningRowKind;
use App\Enums\PartyRole;
use App\Enums\SystemAccount;
use App\Enums\TransactionSource;
use App\Enums\TransactionType;
use App\Exceptions\ApiException;
use App\Exceptions\Onboarding\OpeningBalanceException;
use App\Models\ChartOfAccount;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemVariant;
use App\Models\OpeningImport;
use App\Models\Party;
use App\Models\Transaction;
use App\Models\User;
use App\Repositories\Contracts\ChartOfAccountRepositoryInterface;
use App\Repositories\Contracts\ItemCategoryRepositoryInterface;
use App\Repositories\Contracts\ItemRepositoryInterface;
use App\Repositories\Contracts\ItemVariantRepositoryInterface;
use App\Repositories\Contracts\OpeningImportRepositoryInterface;
use App\Repositories\Contracts\PartyRepositoryInterface;
use App\Repositories\Contracts\TenantRepositoryInterface;
use App\Repositories\Contracts\TransactionRepositoryInterface;
use App\Services\Accounting\LedgerService;
use App\Services\Accounting\PartyService;
use App\Services\Accounting\PostingEngine;
use App\Services\Inventory\ItemService;
use App\Services\Inventory\ItemVariantService;
use App\Support\Money;
use App\Support\Quantity;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Getting a running workshop's existing position into the books.
 *
 * A workshop that has been trading for eleven years does not open at zero, and
 * every figure this product reports is wrong by whatever was already there until
 * somebody says what that was. This is the one screen in the application whose
 * job is to be used once.
 *
 * ## Preview and commit run the same code
 *
 * {@see plan()} resolves a file without writing anything; {@see import()} runs
 * the identical resolution and then posts it. That is not tidiness — a preview
 * assembled by different code from the commit can be right about something the
 * commit gets wrong, and an owner who agreed to one set of figures would find
 * another in their books. The only thing that differs between the two runs is
 * whether the records a row needs are invented or merely counted.
 *
 * ## Re-importing cannot double a balance
 *
 * Two guards, and the *second* one is the real protection.
 *
 * A **fingerprint** over the canonical rows catches the common case — a refresh,
 * a double click, a retry after a timeout — and gives it an explanation rather
 * than a silent "0 rows imported". But it is defeated by any edit at all, and a
 * workshop that splits its opening position across three overlapping files never
 * trips it.
 *
 * So the guard that actually holds is **per target**: a variant that already
 * carries opening stock, a party that already has an opening balance, an account
 * that already has an opening entry, is skipped whatever file it arrives in and
 * however it has been edited since. That is a property of the ledger rather than
 * of the file, which is why it cannot be got round.
 *
 * ## Nothing is posted in part
 *
 * A plan with any unresolvable row is refused whole. The alternative — post what
 * resolves, report the rest — sounds helpful and is the worst possible outcome
 * here: the only way to find out what landed is to reconcile the entire go-live
 * by hand, which is the job the import existed to avoid.
 */
class OpeningBalanceService
{
    /**
     * Records created during one run, so that two rows naming the same new item
     * produce one item rather than two.
     *
     * Reset at the start of every resolution. In {@see plan()} the ids are
     * negative placeholders and nothing is written; in {@see import()} they are
     * real. The resolution code cannot tell the difference, which is the point.
     *
     * @var array<string, Item>
     */
    private array $newItems = [];

    /** @var array<string, ItemVariant> */
    private array $newVariants = [];

    /** @var array<string, Party> */
    private array $newParties = [];

    /** Descending, so a placeholder is never mistaken for a real id. */
    private int $placeholder = 0;

    /** @var Collection<int, Item>|null */
    private ?Collection $itemCache = null;

    /**
     * The workshop's categories, fetched once per import.
     *
     * A file of four hundred rows names the same handful of categories over and
     * over, and each one has to be resolved from the text somebody typed.
     *
     * @var Collection<int, ItemCategory>|null
     */
    private ?Collection $categoryCache = null;

    /** @var Collection<int, Party>|null */
    private ?Collection $partyCache = null;

    /** @var Collection<int, ChartOfAccount>|null */
    private ?Collection $accountCache = null;

    /**
     * Whether a given target already carries an opening balance, memoised.
     *
     * Asked per row rather than prefetched for the whole catalogue, because a
     * file naming six items should not sweep every variant a workshop has ever
     * created — and the answer for one id is one indexed lookup.
     *
     * @var array<string, bool>
     */
    private array $alreadyDeclared = [];

    public function __construct(
        private readonly OpeningCsvParser $parser,
        private readonly NameMatcher $matcher,
        private readonly PostingEngine $engine,
        private readonly LedgerService $ledger,
        private readonly ItemService $items,
        private readonly ItemVariantService $variants,
        private readonly PartyService $parties,
        private readonly ItemRepositoryInterface $itemRepository,
        private readonly ItemVariantRepositoryInterface $variantRepository,
        private readonly ItemCategoryRepositoryInterface $categoryRepository,
        private readonly PartyRepositoryInterface $partyRepository,
        private readonly ChartOfAccountRepositoryInterface $accounts,
        private readonly TransactionRepositoryInterface $transactions,
        private readonly OpeningImportRepositoryInterface $imports,
        private readonly TenantRepositoryInterface $tenants,
        private readonly TenantContext $context,
    ) {}

    /* ---------------------------------------------------------------------
     | Planning
     |-------------------------------------------------------------------- */

    /**
     * Resolve a CSV file without writing anything.
     *
     * @throws OpeningBalanceException
     */
    public function planCsv(string $csv, ?string $date = null, ?string $filename = null): OpeningPlan
    {
        return $this->plan($this->parser->parse($csv), $date, $filename);
    }

    /**
     * Resolve rows without writing anything.
     *
     * @param  array<int, array{0: int, 1: OpeningRow}>  $rows  Line number, then row.
     *
     * @throws OpeningBalanceException
     */
    public function plan(array $rows, ?string $date = null, ?string $filename = null): OpeningPlan
    {
        if ($rows === []) {
            throw OpeningBalanceException::nothingToImport();
        }

        $this->reset();

        return new OpeningPlan(
            rows: $this->resolveAll($rows, commit: false),
            date: $this->dateFor($date),
            filename: $filename,
            fingerprint: $this->fingerprintFor($rows),
        );
    }

    /* ---------------------------------------------------------------------
     | Committing
     |-------------------------------------------------------------------- */

    /**
     * Resolve rows, create whatever they need, and post the lot.
     *
     * Everything below happens inside one database transaction — the catalogue
     * records, the party records, every posting and the import receipt. A
     * go-live that half-succeeded would leave a workshop unable to tell what was
     * in the books without checking every line by hand, which is precisely the
     * work the import exists to remove.
     *
     * @param  array<int, array{0: int, 1: OpeningRow}>  $rows
     *
     * @throws OpeningBalanceException
     */
    public function import(array $rows, ?string $date = null, ?string $filename = null, ?User $actor = null): OpeningImport
    {
        if ($rows === []) {
            throw OpeningBalanceException::nothingToImport();
        }

        $fingerprint = $this->fingerprintFor($rows);
        $existing = $this->imports->findByFingerprint($fingerprint);

        if ($existing !== null) {
            throw OpeningBalanceException::alreadyImported(
                $existing->created_at?->toDateString() ?? $existing->date->toDateString(),
                $existing->imported_count,
            );
        }

        $on = $this->dateFor($date);

        // Resolved *inside* the transaction, for the reason the posting engine
        // re-composes a draft inside one: between a preview and a commit another
        // session may have added the very item this file was about to invent,
        // and the resolution that matters is the one holding the write.
        return DB::transaction(function () use ($rows, $on, $filename, $fingerprint, $actor) {
            $this->reset();

            $plan = new OpeningPlan(
                rows: $this->resolveAll($rows, commit: true),
                date: $on,
                filename: $filename,
                fingerprint: $fingerprint,
            );

            if ($plan->hasErrors()) {
                throw OpeningBalanceException::planHasErrors(count($plan->errors()));
            }

            if ($plan->hasNothingToPost()) {
                // Every row was already declared. Not an error — it is what a
                // second run of the same file looks like once the fingerprint
                // has been defeated by an edit — so the receipt is written and
                // says so, rather than leaving the caller with an exception and
                // no record of what happened.
                return $this->recordImport($plan, $actor, []);
            }

            $posted = array_merge(
                $this->postStock($plan, $actor),
                $this->postPartyBalances($plan, $actor),
                $this->postAccountBalances($plan, $actor),
            );

            $import = $this->recordImport($plan, $actor, $posted);

            Log::info('opening.imported', [
                'import_id' => $import->id,
                'tenant_id' => $import->tenant_id,
                'rows' => count($plan->rows),
                'posted' => count($posted),
                'skipped' => count($plan->skipped()),
                'owners_stake' => $plan->ownersStake()->amount(),
            ]);

            return $import;
        });
    }

    /**
     * The receipt, and the write-once stamp that ties the postings to it.
     *
     * @param  array<int, Transaction>  $posted
     */
    private function recordImport(OpeningPlan $plan, ?User $actor, array $posted): OpeningImport
    {
        $import = $this->imports->create([
            'filename' => $plan->filename,
            'fingerprint' => $plan->fingerprint,
            'date' => $plan->date,
            'row_count' => count($plan->rows),
            'imported_count' => count($plan->ready()),
            'skipped_count' => count($plan->skipped()),
            'stock_value' => $plan->totalFor(OpeningRowKind::Stock)->amount(),
            'receivable_total' => $plan->totalFor(OpeningRowKind::Receivable)->amount(),
            'payable_total' => $plan->totalFor(OpeningRowKind::Payable)->amount(),
            'other_total' => $plan->totalFor(OpeningRowKind::Balance)->amount(),
            'items_created' => $plan->creationsOf(PlannedRow::CREATES_ITEM),
            'parties_created' => $plan->creationsOf(PlannedRow::CREATES_PARTY),
            'created_by' => $actor?->id,
        ]);

        foreach ($posted as $transaction) {
            // Write-once provenance — see Transaction::STAMPABLE_ONCE_POSTED.
            // Stamped after the fact rather than passed through the posting
            // engine, because which file a transaction came from is not an
            // accounting fact and the engine has no business knowing about
            // spreadsheets.
            $this->transactions->update($transaction, ['opening_import_id' => $import->id]);
        }

        return $import;
    }

    /* ---------------------------------------------------------------------
     | Posting
     |-------------------------------------------------------------------- */

    /**
     * Everything on the shelf, as one transaction.
     *
     * One rather than one per line, because a workshop's opening stock is a
     * single act — it counted what it had on a Tuesday — and forty transactions
     * dated the same day would make the day book unreadable for no gain.
     *
     * @return array<int, Transaction>
     */
    private function postStock(OpeningPlan $plan, ?User $actor): array
    {
        $rows = $plan->readyOfKind(OpeningRowKind::Stock);

        if ($rows === []) {
            return [];
        }

        return [$this->post($plan, $actor, 'Opening stock', [
            'stock' => array_map(fn (PlannedRow $row) => [
                'variant_id' => $row->variantId,
                'quantity' => $row->quantity?->amount(),
                'unit_cost' => $row->unitCost?->amount(),
                'memo' => $row->row->reference,
            ], $rows),
        ])];
    }

    /**
     * One transaction per counterparty.
     *
     * Per party rather than per row, because `transactions.party_id` is one
     * column and a party ledger is what this is for: a counterparty who was both
     * owed ₹12,000 and owing ₹40,000 at go-live gets one document showing both,
     * which is exactly how M5 said a party who holds both roles should read.
     *
     * @return array<int, Transaction>
     */
    private function postPartyBalances(OpeningPlan $plan, ?User $actor): array
    {
        $byParty = [];

        foreach ([OpeningRowKind::Receivable, OpeningRowKind::Payable] as $kind) {
            foreach ($plan->readyOfKind($kind) as $row) {
                $byParty[(int) $row->partyId][] = $row;
            }
        }

        $posted = [];

        foreach ($byParty as $partyId => $rows) {
            $posted[] = $this->post(
                $plan,
                $actor,
                sprintf('Opening balance — %s', $rows[0]->resolved ?? 'party'),
                [
                    'party_id' => $partyId,
                    'balances' => array_map(fn (PlannedRow $row) => [
                        'account_id' => $row->accountId,
                        'side' => $row->row->kind->side()?->value,
                        'amount' => $row->amount()->amount(),
                        'memo' => $row->row->reference,
                    ], $rows),
                ],
            );
        }

        return $posted;
    }

    /**
     * Cash, bank, loans — the workshop's own accounts, as one transaction.
     *
     * @return array<int, Transaction>
     */
    private function postAccountBalances(OpeningPlan $plan, ?User $actor): array
    {
        $rows = $plan->readyOfKind(OpeningRowKind::Balance);

        if ($rows === []) {
            return [];
        }

        return [$this->post($plan, $actor, 'Opening balances', [
            'balances' => array_map(fn (PlannedRow $row) => [
                'account_id' => $row->accountId,
                'side' => ($row->side ?? BalanceSide::Debit)->value,
                'amount' => $row->amount()->amount(),
                'memo' => $row->row->reference ?? $row->resolved,
            ], $rows),
        ])];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function post(OpeningPlan $plan, ?User $actor, string $notes, array $payload): Transaction
    {
        return $this->engine->postComposed(
            TransactionType::Opening,
            array_merge($payload, [
                'date' => $plan->date,
                'notes' => $notes,
                // Provenance, and the reason TransactionSource::Import has
                // existed unused since M4: a figure that came out of somebody
                // else's spreadsheet carries a different level of trust from one
                // a person typed, and M12's worklists filter on it.
                'source' => TransactionSource::Import,
            ]),
            $actor,
        );
    }

    /* ---------------------------------------------------------------------
     | Resolution
     |-------------------------------------------------------------------- */

    /**
     * @param  array<int, array{0: int, 1: OpeningRow}>  $rows
     * @return array<int, PlannedRow>
     */
    private function resolveAll(array $rows, bool $commit): array
    {
        $planned = [];

        foreach ($rows as [$lineNo, $row]) {
            $planned[] = $this->resolve($lineNo, $row, $commit);
        }

        return $planned;
    }

    private function resolve(int $lineNo, OpeningRow $row, bool $commit): PlannedRow
    {
        try {
            return match ($row->kind) {
                OpeningRowKind::Stock => $this->resolveStock($lineNo, $row, $commit),
                OpeningRowKind::Receivable, OpeningRowKind::Payable => $this->resolveParty($lineNo, $row, $commit),
                OpeningRowKind::Balance => $this->resolveBalance($lineNo, $row),
            };
        } catch (ApiException $exception) {
            // A row-level refusal from deeper down — an attribute schema a
            // spreadsheet cell cannot satisfy, a name already taken by a record
            // this run did not match. Reported as the row it came from rather
            // than failing the file, so somebody fixing a spreadsheet sees every
            // problem in one pass instead of one per upload.
            //
            // ApiException and not something narrower: every refusal this
            // application raises on purpose is one, and every one of them
            // reaching here is about the row being resolved. Anything else is a
            // bug and is left to propagate as a 500, which is what it is.
            return PlannedRow::error($lineNo, $row, $exception->getMessage());
        }
    }

    /* ---------------------------------------------------------------------
     | Stock rows
     |-------------------------------------------------------------------- */

    private function resolveStock(int $lineNo, OpeningRow $row, bool $commit): PlannedRow
    {
        if ($row->name === null) {
            return PlannedRow::error($lineNo, $row, 'Say which item this stock is. A quantity with no name cannot be put anywhere.');
        }

        $quantity = $this->quantityOf($row->quantity);

        if ($quantity === null || ! $quantity->isPositive()) {
            return PlannedRow::error($lineNo, $row, sprintf(
                'Give a quantity greater than zero for %s. If none was on the shelf at go-live, leave the row out.',
                $row->name,
            ));
        }

        $value = $this->stockValueFor($row, $quantity);

        if ($value === null) {
            return PlannedRow::error($lineNo, $row, sprintf(
                'Say what %s was worth — either a unit cost or a total value. Opening stock valued at nothing '.
                'would report a margin of 100%% on the first one sold.',
                $row->name,
            ));
        }

        $match = $this->resolveItem($row, $commit);

        if (is_string($match)) {
            return PlannedRow::error($lineNo, $row, $match);
        }

        [$item, $variant, $creates, $confidence] = $match;

        if (! ($item->category?->holds_stock ?? true)) {
            return PlannedRow::error($lineNo, $row, sprintf(
                '"%s" is a %s, which cannot be held in stock — an hour of labour is produced at the moment it '.
                'is sold, so there was never any of it on the shelf.',
                $item->name,
                strtolower($item->categoryLabel()),
            ));
        }

        if (! $quantity->fitsUnit($item->base_uom)) {
            return PlannedRow::error($lineNo, $row, sprintf(
                '%s is counted in %s, so %s of it is not a quantity anybody can act on.',
                $item->name,
                $item->base_uom->label(),
                $quantity->trimmed(),
            ));
        }

        $label = sprintf('%s · %s', $item->name, $variant->displayLabel());

        if ($variant->id > 0 && $this->hasOpeningStock((int) $variant->id)) {
            return PlannedRow::skipped($lineNo, $row, $label, 'Opening stock has already been declared for this variant.');
        }

        return PlannedRow::ready(
            lineNo: $lineNo,
            row: $row,
            resolved: $label,
            amount: $value,
            itemId: (int) $item->id,
            variantId: (int) $variant->id,
            quantity: $quantity,
            unitCost: $quantity->rateFrom($value),
            creates: $creates,
            confidence: $confidence,
        );
    }

    /**
     * What the row says the stock is worth.
     *
     * A stated total wins over quantity × rate, because that is what a previous
     * package's valuation report prints and re-multiplying a rounded rate does
     * not give it back. Null when the row says neither — which is refused rather
     * than defaulted to zero, since stock carried at nothing reports a 100%
     * margin on the first one sold.
     */
    private function stockValueFor(OpeningRow $row, Quantity $quantity): ?Money
    {
        $total = $this->moneyOf($row->amount);

        if ($total !== null && $total->isPositive()) {
            return $total;
        }

        $rate = $this->moneyOf($row->unitCost);

        return $rate !== null && $rate->isPositive() ? $quantity->costAt($rate) : null;
    }

    /**
     * Find, or arrange to create, the item and variant a stock row names.
     *
     * @return string|array{0: Item, 1: ItemVariant, 2: array<int, string>, 3: int|null}
     *                                                                                   An error message, or the resolution.
     */
    private function resolveItem(OpeningRow $row, bool $commit): string|array
    {
        $name = (string) $row->name;
        $creates = [];
        $confidence = null;

        $found = $this->matcher->best($name, $this->allItems(), fn (Item $item) => $item->name);

        if ($found === null) {
            // A new family. The type has to be stated and cannot be guessed: it
            // fixes the unit every quantity will ever be recorded in, and M7
            // made both permanent precisely because changing them later would
            // reinterpret every figure already posted — "each" becoming
            // "kilogram" turns 40 pieces into 40 kilograms in every report.
            $category = $this->resolveCategory($row->categoryName);

            if ($category === null) {
                return sprintf(
                    'Nothing in the catalogue matches "%s". To add it, the file has to name a category in its '.
                    '"type" column%s — the category fixes the unit its quantities are counted in and what its '.
                    'specification has to say, and neither can be changed afterwards.',
                    $name,
                    $this->knownCategoryList(),
                );
            }

            $item = $this->createItem($name, $row, $commit);
            $creates[] = PlannedRow::CREATES_ITEM;
        } else {
            [$item, $confidence] = $found;
        }

        $variant = $this->resolveVariant($item, $row, $commit, $creates);

        return is_string($variant) ? $variant : [$item, $variant, $creates, $confidence];
    }

    /**
     * @param  array<int, string>  $creates  Appended to when a variant is added.
     */
    private function resolveVariant(Item $item, OpeningRow $row, bool $commit, array &$creates): string|ItemVariant
    {
        $existing = $item->id > 0 ? $this->variantsOf($item) : new Collection;

        if ($row->variant === null) {
            // No specification given. One variant is unambiguous; more than one
            // is not, and picking the first would attach a workshop's opening
            // stock of 5 HP motors to whichever rating happened to be created
            // first.
            if ($existing->count() === 1) {
                return $existing->first();
            }

            if ($existing->count() > 1) {
                return sprintf(
                    '"%s" has %d variants, so the file has to say which one — stock is counted per variant, '.
                    'never per family.',
                    $item->name,
                    $existing->count(),
                );
            }

            return $this->createVariant($item, $row, $item->name, $commit, $creates);
        }

        // SKU first and exactly: a code that identifies two things is worse than
        // no code, so an exact SKU is the one match that needs no judgement.
        $bySku = $existing->first(
            fn (ItemVariant $variant) => $variant->sku !== null
                && strcasecmp($variant->sku, $row->variant) === 0
        );

        if ($bySku !== null) {
            return $bySku;
        }

        $found = $this->matcher->best(
            $row->variant,
            $existing,
            fn (ItemVariant $variant) => $variant->displayLabel(),
        );

        return $found !== null
            ? $found[0]
            : $this->createVariant($item, $row, $row->variant, $commit, $creates);
    }

    /* ---------------------------------------------------------------------
     | Party rows
     |-------------------------------------------------------------------- */

    private function resolveParty(int $lineNo, OpeningRow $row, bool $commit): PlannedRow
    {
        $kind = $row->kind;
        $role = $kind->partyRole();

        if ($row->name === null) {
            return PlannedRow::error($lineNo, $row, sprintf(
                'Say who this %s is with. Money owed by nobody in particular cannot be chased or settled.',
                strtolower($kind->label()),
            ));
        }

        $amount = $this->moneyOf($row->amount);

        if ($amount === null || ! $amount->isPositive()) {
            return PlannedRow::error($lineNo, $row, sprintf(
                'Give an amount greater than zero for %s. A balance of nothing is not a balance.',
                $row->name,
            ));
        }

        [$party, $creates, $confidence] = $this->findOrMakeParty($row, $role, $commit);

        if ($party->id > 0 && $this->hasOpeningBalance((int) $party->id)) {
            return PlannedRow::skipped(
                $lineNo,
                $row,
                $party->name,
                'An opening balance has already been declared for this party.',
            );
        }

        $control = $kind->controlAccount();
        $account = $control === null ? null : $this->accounts->findBySystemKey($control);

        if ($account === null) {
            return PlannedRow::error($lineNo, $row, sprintf(
                'The %s control account is missing from this workshop\'s chart. Run `php artisan accounts:seed`.',
                strtolower($kind->label()),
            ));
        }

        return PlannedRow::ready(
            lineNo: $lineNo,
            row: $row,
            resolved: $party->name,
            amount: $amount,
            partyId: (int) $party->id,
            accountId: (int) $account->id,
            side: $kind->side(),
            creates: $creates,
            confidence: $confidence,
        );
    }

    /**
     * @return array{0: Party, 1: array<int, string>, 2: int|null}
     */
    private function findOrMakeParty(OpeningRow $row, PartyRole $role, bool $commit): array
    {
        $name = (string) $row->name;

        // A GSTIN is an exact identifier and beats any amount of name
        // similarity: two branches of one business file under one number, and a
        // workshop dealing with both has two records — so this narrows the field
        // rather than deciding it outright.
        $candidates = $this->allParties();

        if ($row->gstin !== null) {
            $sharing = $candidates->filter(fn (Party $party) => $party->gstin === $row->gstin);

            if ($sharing->isNotEmpty()) {
                $candidates = $sharing;
            }
        }

        $found = $this->matcher->best($name, $candidates, fn (Party $party) => $party->name);

        if ($found === null) {
            return [$this->createParty($name, $row, $role, $commit), [PlannedRow::CREATES_PARTY], null];
        }

        [$party, $confidence] = $found;

        // Declaring a payable to somebody *is* the statement that they are a
        // supplier, so the role is added rather than the row being refused.
        // Onboarding is the one moment a workshop describes its whole trading
        // history at once, and stopping to tick a box on a record they are in
        // the middle of importing would be bureaucracy. It is one click to undo
        // on the parties screen, and M5's decision that roles never filter a
        // *read* means nothing is hidden if it turns out to be wrong.
        if (! $party->hasRole($role)) {
            if ($commit) {
                $party = $this->parties->update((int) $party->id, [
                    'roles' => array_values(array_unique([...$party->roles ?? [], $role->value])),
                ]);
                $this->partyCache = null;
            }

            return [$party, ['role:'.$role->value], $confidence];
        }

        return [$party, [], $confidence];
    }

    /* ---------------------------------------------------------------------
     | Account rows
     |-------------------------------------------------------------------- */

    private function resolveBalance(int $lineNo, OpeningRow $row): PlannedRow
    {
        $named = $row->account ?? $row->name;

        if ($named === null) {
            return PlannedRow::error($lineNo, $row, 'Say which account this balance is on.');
        }

        $amount = $this->moneyOf($row->amount);

        if ($amount === null || ! $amount->isPositive()) {
            return PlannedRow::error($lineNo, $row, sprintf(
                'Give an amount greater than zero for "%s". To open an account on the other side — an overdrawn '.
                'bank, say — put "credit" in the side column rather than a minus in the amount.',
                $named,
            ));
        }

        $account = $this->findAccount($named);

        if ($account === null) {
            // Never invented. The chart of accounts is structural and M3 owns
            // it: an account created from a spreadsheet cell would land in
            // whichever code band its name happened to suggest, and an expense
            // numbered 1500 sorts into the assets in every report that groups by
            // code — a mistake nobody sees until the balance sheet looks wrong.
            return PlannedRow::error($lineNo, $row, sprintf(
                'No account in this workshop\'s chart is called "%s". Add it on the Accounting screen first — '.
                'accounts are not created from an import, because the code decides which financial statement '.
                'every entry lands on.',
                $named,
            ));
        }

        if ($account->represents(SystemAccount::OpeningBalanceEquity)) {
            return PlannedRow::error($lineNo, $row, OpeningBalanceException::equityIsNotADeclaration()->getMessage());
        }

        if ($account->represents(SystemAccount::Inventory)) {
            return PlannedRow::error($lineNo, $row, OpeningBalanceException::inventoryNeedsQuantities()->getMessage());
        }

        foreach ([[SystemAccount::Receivables, 'receivable'], [SystemAccount::Payables, 'payable']] as [$key, $kind]) {
            if ($account->represents($key)) {
                return PlannedRow::error($lineNo, $row, sprintf(
                    '"%s" is the control account behind every customer and supplier balance, so it cannot be '.
                    'opened as a lump sum — a total nobody can break down cannot be chased or settled. Use one '.
                    '"%s" row per party instead.',
                    $account->name,
                    $kind,
                ));
            }
        }

        if (! $account->is_active) {
            return PlannedRow::error($lineNo, $row, sprintf(
                '"%s" is archived, so nothing new is posted to it. Restore the account or choose another.',
                $account->name,
            ));
        }

        $side = $row->side === null
            ? $account->normalBalance()
            : BalanceSide::tryFrom(strtolower($row->side));

        if ($side === null) {
            return PlannedRow::error($lineNo, $row, sprintf(
                '"%s" is neither a debit nor a credit.',
                $row->side,
            ));
        }

        if ($this->hasOpeningEntry((int) $account->id)) {
            return PlannedRow::skipped(
                $lineNo,
                $row,
                $account->name,
                'An opening balance has already been declared for this account.',
            );
        }

        return PlannedRow::ready(
            lineNo: $lineNo,
            row: $row,
            resolved: sprintf('%s · %s', $account->code, $account->name),
            amount: $amount,
            accountId: (int) $account->id,
            side: $side,
        );
    }

    /**
     * An account by its code, exactly, or by its name.
     *
     * Code first and without fuzziness: "1010" is either an account number or it
     * is not, and a near miss on a number is a different account rather than a
     * typo worth guessing at.
     */
    private function findAccount(string $named): ?ChartOfAccount
    {
        $byCode = $this->allAccounts()->first(fn (ChartOfAccount $account) => $account->code === trim($named));

        if ($byCode !== null) {
            return $byCode;
        }

        $found = $this->matcher->best($named, $this->allAccounts(), fn (ChartOfAccount $account) => $account->name);

        return $found[0] ?? null;
    }

    /* ---------------------------------------------------------------------
     | Creating what a row needs
     |-------------------------------------------------------------------- */

    /**
     * Every record invented by an import is flagged **draft**.
     *
     * A row of a spreadsheet gives an item a name, a rough type and nothing
     * else: no HSN code, no GST rate, no sell price. Every one of those is
     * needed before it can be billed correctly, and a record that looks complete
     * because nothing said otherwise is how a workshop ends up charging 0% GST
     * on a motor for a year. M7 built the review queue for exactly this moment.
     */
    private function createItem(string $name, OpeningRow $row, bool $commit): Item
    {
        $key = $this->matcher->normalise($name);

        if (isset($this->newItems[$key])) {
            return $this->newItems[$key];
        }

        $category = $this->resolveCategory($row->categoryName);

        if ($category === null) {
            // Unreachable: the planner refuses the row before it gets here. Kept
            // as a refusal rather than an assumption, because a category picked
            // by default would fix the unit of every quantity in the file.
            throw OpeningBalanceException::planHasErrors(1);
        }

        if (! $commit) {
            $item = new Item([
                'name' => $name,
                'category_id' => $category->id,
                'base_uom' => $category->default_unit_code ?? 'piece',
                'is_stock' => true,
                'is_draft' => true,
                'is_active' => true,
            ]);

            $item->id = --$this->placeholder;
            $item->setRelation('variants', new Collection);
            $item->setRelation('category', $category);

            return $this->newItems[$key] = $item;
        }

        $item = $this->items->create([
            'name' => $name,
            'category_id' => (int) $category->id,
            'is_stock' => true,
            'is_draft' => true,
        ]);

        // The cached list is now stale, and a later row naming the same item by
        // a slightly different spelling has to be able to find it.
        $this->itemCache = null;

        return $this->newItems[$key] = $item;
    }

    /**
     * @param  array<int, string>  $creates
     */
    private function createVariant(Item $item, OpeningRow $row, string $specification, bool $commit, array &$creates): string|ItemVariant
    {
        $key = $item->id.'|'.$this->matcher->normalise($specification);

        if (isset($this->newVariants[$key])) {
            return $this->newVariants[$key];
        }

        $attributes = $this->attributesFrom($item, $specification);

        if (is_string($attributes)) {
            return $attributes;
        }

        $creates[] = PlannedRow::CREATES_VARIANT;

        if (! $commit) {
            $variant = new ItemVariant([
                'item_id' => $item->id,
                'label' => $specification,
                'attributes' => $attributes === [] ? null : $attributes,
                'is_draft' => true,
                'is_active' => true,
            ]);

            $variant->id = --$this->placeholder;
            $variant->setRelation('item', $item);

            return $this->newVariants[$key] = $variant;
        }

        $variant = $this->variants->create($item, [
            // The specification as written is kept as the label as well as being
            // parsed into attributes, so a fitter who asks for "22 SWG" finds it
            // under the words they used — M7's rule that a stored label wins.
            'label' => $specification,
            'attributes' => $attributes,
            'is_draft' => true,
        ]);

        return $this->newVariants[$key] = $variant;
    }

    /**
     * Turn a written specification into the attribute bag its item type demands.
     *
     * The rule is the inverse of {@see ItemVariant::derivedLabel()}, which is
     * what makes it explainable: the app prints "5 HP / 3 ph / 1440 RPM", so the
     * importer reads segments split on `/` into the type's required attributes,
     * in schema order. A file exported from this product round-trips, and a file
     * written by hand only has to follow what the screens already show.
     *
     * Fewer segments than required attributes is refused by name rather than
     * padded. A motor whose HP was never captured is unidentifiable by anybody
     * afterwards, and that is permanent — M7's reasoning, applied at the one
     * moment a workshop is creating forty records at once.
     *
     * @return string|array<string, string>
     */
    private function attributesFrom(Item $item, string $specification): string|array
    {
        $category = $item->category;

        if ($category === null) {
            return [];
        }

        $required = $category->requiredAttributeKeys();

        if ($required === []) {
            return [];
        }

        $segments = array_values(array_filter(array_map(
            'trim',
            explode('/', $specification),
        ), static fn (string $segment) => $segment !== ''));

        if (count($segments) < count($required)) {
            $schema = $category->attributeSchema();

            return sprintf(
                'A %s needs %s, written as "%s". "%s" gives %d of %d — a %s whose %s was never recorded cannot '.
                'be identified by anybody afterwards, and that cannot be fixed later.',
                strtolower($category->name),
                $this->listOf(array_map(fn (string $key) => strtolower($schema[$key]['label']), $required)),
                implode(' / ', array_map(fn (string $key) => strtolower($schema[$key]['label']), $required)),
                $specification,
                count($segments),
                count($required),
                strtolower($category->name),
                strtolower($schema[$required[count($segments)] ?? $required[0]]['label']),
            );
        }

        $attributes = [];

        foreach ($required as $index => $key) {
            // The suffix the app prints is taken back off, so "5 HP" read from a
            // file this product exported stores as "5" and not "5 HP" — which
            // would then print as "5 HP HP".
            $attributes[$key] = $this->stripSuffix(
                $segments[$index],
                $category->attributeSchema()[$key]['suffix'] ?? null,
            );
        }

        return $attributes;
    }

    /**
     * The category a row's "type" column names.
     *
     * Matched on the code first and the name second, both case-insensitively,
     * because a file exported by this product carries the code and a file typed
     * by a person carries the word they see on screen. Both have to work: the
     * commonest import is a spreadsheet somebody filled in by hand.
     *
     * Null where the column is empty or names nothing — which the planner turns
     * into a refusal naming the categories that do exist, rather than picking one.
     */
    private function resolveCategory(?string $given): ?ItemCategory
    {
        $given = trim((string) $given);

        if ($given === '') {
            return null;
        }

        $this->categoryCache ??= $this->categoryRepository->all();

        $needle = strtolower($given);
        // The old file format wrote "bulk material" for what the seeded category
        // codes call `bulk_material`, so a space is treated as an underscore.
        $slug = str_replace([' ', '-'], '_', $needle);

        return $this->categoryCache->first(
            fn (ItemCategory $category) => strtolower((string) $category->code) === $slug
                || strtolower($category->name) === $needle
        );
    }

    /**
     * The categories a file may name, for the refusal that says one is missing.
     *
     * Listed rather than described, because the set is the shop's own now: the
     * message used to be able to say "motor, part or bulk_material" and cannot,
     * since the whole point of the change is that nobody knows what they are
     * called any more.
     */
    private function knownCategoryList(): string
    {
        $this->categoryCache ??= $this->categoryRepository->all();

        $names = $this->categoryCache
            ->filter(fn (ItemCategory $category) => $category->is_active && $category->holds_stock)
            ->map(fn (ItemCategory $category) => $category->name)
            ->values()
            ->all();

        return $names === [] ? '' : ' ('.implode(', ', $names).')';
    }

    private function stripSuffix(string $value, ?string $suffix): string
    {
        if ($suffix === null) {
            return $value;
        }

        $trimmed = preg_replace('/\s*'.preg_quote($suffix, '/').'$/i', '', $value) ?? $value;

        return trim($trimmed) === '' ? $value : trim($trimmed);
    }

    private function createParty(string $name, OpeningRow $row, PartyRole $role, bool $commit): Party
    {
        $key = $this->matcher->normalise($name);

        if (isset($this->newParties[$key])) {
            $party = $this->newParties[$key];

            // A counterparty who is both owed and owing at go-live — the case
            // M5's multi-value roles exist for. Two rows, one record.
            if (! $party->hasRole($role)) {
                $roles = array_values(array_unique([...$party->roles ?? [], $role->value]));

                $party = $commit
                    ? $this->parties->update((int) $party->id, ['roles' => $roles])
                    : tap($party, fn (Party $p) => $p->roles = $roles);

                $this->newParties[$key] = $party;
            }

            return $party;
        }

        if (! $commit) {
            $party = new Party([
                'name' => $name,
                'roles' => [$role->value],
                'gstin' => $row->gstin,
                'is_active' => true,
            ]);

            $party->id = --$this->placeholder;

            return $this->newParties[$key] = $party;
        }

        $party = $this->parties->create([
            'name' => $name,
            'roles' => [$role->value],
            'gstin' => $row->gstin,
        ]);

        $this->partyCache = null;

        return $this->newParties[$key] = $party;
    }

    /* ---------------------------------------------------------------------
     | Reading what is already declared
     |-------------------------------------------------------------------- */

    /**
     * The go-live position, for the screen that offers the import.
     *
     * @return array<string, mixed>
     */
    public function position(): array
    {
        $tenant = $this->tenants->findById($this->context->requireTenant('reading opening balances'));

        $equity = $this->accounts->findBySystemKey(SystemAccount::OpeningBalanceEquity);
        $trial = $this->ledger->trialBalance();

        return [
            // Where the books open. An import dated before this is refused by
            // the engine (BOOKS_CLOSED), so the screen states it rather than
            // letting somebody discover it at the moment they commit.
            'books_start_date' => $tenant?->books_start_date?->toDateString(),
            'default_date' => $this->dateFor(null),

            'has_opening_balances' => $this->imports->openingTransactionCount() > 0,
            'opening_transactions' => $this->imports->openingTransactionCount(),

            // What Opening Balance Equity holds: the owner's stake at go-live.
            // Signed on its own normal side, so a workshop that declared more
            // assets than debts reports a positive figure.
            'owners_stake' => $equity === null
                ? Money::zero()->amount()
                : $this->ledger->balanceFor($equity)->amount(),

            'trial_balance' => [
                'debit' => $trial['totals']['debit']->amount(),
                'credit' => $trial['totals']['credit']->amount(),
                'is_balanced' => $trial['is_balanced'],
                'difference' => $trial['difference']->amount(),
            ],
        ];
    }

    /**
     * @return Collection<int, OpeningImport>
     */
    public function history(int $limit = 20): Collection
    {
        return $this->imports->history($limit);
    }

    /* ---------------------------------------------------------------------
     | Internals
     |-------------------------------------------------------------------- */

    private function reset(): void
    {
        $this->newItems = [];
        $this->newVariants = [];
        $this->newParties = [];
        $this->placeholder = 0;
        $this->itemCache = null;
        $this->partyCache = null;
        $this->accountCache = null;
        // Cleared with the rest, and for the reason they are: this service is
        // resolved once per process, so a cache left standing would answer the
        // next workshop's import with the previous workshop's categories — whose
        // ids belong to somebody else entirely.
        $this->categoryCache = null;
        $this->alreadyDeclared = [];
    }

    private function hasOpeningStock(int $variantId): bool
    {
        return $this->alreadyDeclared['variant:'.$variantId] ??=
            $this->imports->variantsWithOpeningStock([$variantId]) !== [];
    }

    private function hasOpeningBalance(int $partyId): bool
    {
        return $this->alreadyDeclared['party:'.$partyId] ??=
            $this->imports->partiesWithOpeningBalance([$partyId]) !== [];
    }

    private function hasOpeningEntry(int $accountId): bool
    {
        return $this->alreadyDeclared['account:'.$accountId] ??=
            $this->imports->accountsWithOpeningEntry([$accountId]) !== [];
    }

    /**
     * The whole catalogue, once per run.
     *
     * Loaded in full rather than queried per row, and that is the right trade
     * here for the same reason M8's stock screen sorts in PHP: the question
     * being asked — "which of these names is this one" — is not a question SQL
     * can answer, so the set has to be in memory whatever happens. A workshop's
     * catalogue is what one person maintains by hand.
     *
     * @return Collection<int, Item>
     */
    private function allItems(): Collection
    {
        return $this->itemCache ??= $this->itemRepository->all();
    }

    /**
     * @return Collection<int, ItemVariant>
     */
    private function variantsOf(Item $item): Collection
    {
        return $item->id > 0 ? $this->variantRepository->forItem($item) : new Collection;
    }

    /**
     * @return Collection<int, Party>
     */
    private function allParties(): Collection
    {
        return $this->partyCache ??= $this->partyRepository->all();
    }

    /**
     * @return Collection<int, ChartOfAccount>
     */
    private function allAccounts(): Collection
    {
        return $this->accountCache ??= $this->accounts->all();
    }

    /**
     * The date an opening balance is declared as at.
     *
     * The workshop's go-live day where it has set one, because that is what an
     * opening balance means and because the engine refuses anything earlier
     * (`BOOKS_CLOSED`, from M2.2). Today otherwise — a workshop that has not set
     * a go-live date is going live now.
     */
    private function dateFor(?string $date): string
    {
        if ($date !== null && trim($date) !== '') {
            return CarbonImmutable::parse($date)->toDateString();
        }

        $tenantId = $this->context->current();
        $tenant = $tenantId === null ? null : $this->tenants->findById($tenantId);

        return $tenant?->books_start_date?->toDateString()
            ?? CarbonImmutable::now()->toDateString();
    }

    /**
     * A hash of the declarations, insensitive to the things that do not change
     * what is being declared: column order, header spelling, blank spacer rows.
     *
     * @param  array<int, array{0: int, 1: OpeningRow}>  $rows
     */
    private function fingerprintFor(array $rows): string
    {
        $parts = array_map(
            fn (array $pair) => implode("\x1f", $pair[1]->fingerprintParts()),
            $rows,
        );

        // Sorted, so the same declarations in a different order are recognised
        // as the same file. Somebody who re-sorted their spreadsheet by supplier
        // and uploaded it again has not changed a single figure.
        sort($parts);

        return hash('sha256', implode("\x1e", $parts));
    }

    private function moneyOf(?string $amount): ?Money
    {
        if ($amount === null) {
            return null;
        }

        try {
            return Money::of($amount);
        } catch (\InvalidArgumentException) {
            // Reported as a row that could not be read rather than as an
            // exception that kills the file. Returning null lands it in the
            // caller's "give an amount" branch, which names the row.
            return null;
        }
    }

    private function quantityOf(?string $quantity): ?Quantity
    {
        if ($quantity === null) {
            return null;
        }

        try {
            return Quantity::of($quantity);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * "a rating, a phase and a speed" — an Oxford-comma-free English list.
     *
     * @param  array<int, string>  $items
     */
    private function listOf(array $items): string
    {
        if (count($items) <= 1) {
            return $items[0] ?? '';
        }

        $last = array_pop($items);

        return implode(', ', $items).' and '.$last;
    }
}
