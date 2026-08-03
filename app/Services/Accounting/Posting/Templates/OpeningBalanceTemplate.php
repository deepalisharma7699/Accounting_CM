<?php

namespace App\Services\Accounting\Posting\Templates;

use App\Enums\BalanceSide;
use App\Enums\PartyRole;
use App\Enums\StockMovementType;
use App\Enums\SystemAccount;
use App\Enums\TransactionType;
use App\Exceptions\Accounting\InvalidJournalException;
use App\Exceptions\Accounting\InvalidStockMovementException;
use App\Exceptions\Onboarding\OpeningBalanceException;
use App\Models\ChartOfAccount;
use App\Models\ItemVariant;
use App\Repositories\Contracts\ItemVariantRepositoryInterface;
use App\Repositories\Contracts\PartyRepositoryInterface;
use App\Services\Accounting\ChartOfAccountService;
use App\Services\Accounting\Posting\MovesStock;
use App\Services\Accounting\Posting\PostingLine;
use App\Services\Accounting\Posting\PostingTemplate;
use App\Services\Accounting\Posting\StatesItsOwnTotal;
use App\Services\Accounting\Posting\StockChange;
use App\Services\Inventory\StockLedgerService;
use App\Support\Money;
use App\Support\Quantity;

/**
 * Template H — what the workshop already had on the day the books opened.
 *
 * A running workshop cannot start at zero. It has motors on the shelf, customers
 * who owe it money and suppliers it owes, and all three have to be in the books
 * before the first sale is recorded or every figure the product reports is wrong
 * by whatever was there already.
 *
 * ## Everything's other side is Opening Balance Equity
 *
 * And that is the definition, not a convenience. An opening balance is not a
 * transaction *with* anybody: nothing was bought, nothing was sold, nobody was
 * paid and no GST was charged. What is being declared is the workshop's own
 * position at go-live, and the accounting name for "what the business is worth
 * before we started counting" is equity.
 *
 * The alternative — routing opening stock through a purchase — is worth naming
 * because it is what a spreadsheet import usually does, and it is wrong in three
 * separate ways at once. It reports the workshop's first month as an enormous
 * acquisition, it claims input tax on stock whose tax was claimed years ago, and
 * it invents a supplier who was never paid. See {@see StockMovementType::Opening},
 * which exists so a stock report can tell the two apart afterwards.
 *
 * ## Why OBE never needs to balance to zero
 *
 * Because it is not a suspense account. Once every asset and liability the
 * workshop carried in is declared, whatever is left in Opening Balance Equity
 * *is* the owner's stake on the day the books opened — a real number, and usually
 * the most interesting one on the screen. The roadmap calls it "the residual",
 * and the important property is that it is always visible and always explains
 * itself: because every line here is posted against OBE, the trial balance
 * reconciles no matter what was imported, and a mistake shows up as an OBE
 * balance that does not look like the owner's stake rather than as books that do
 * not add up.
 *
 * ## Why one OBE line per row
 *
 * A stock-take's reasoning, applied to a different table — see
 * {@see StockAdjustmentTemplate}. Netting an import into a single equity line
 * would make the OBE ledger a column of unexplained figures, which is exactly
 * the thing somebody reconciling a go-live needs it not to be. Several lines on
 * one account are still one sum as far as the balance check is concerned.
 *
 * ## The input vocabulary
 *
 * ```
 * [
 *   'stock'    => [['variant_id' => 12, 'quantity' => '4', 'unit_cost' => '8200.00'], ...],
 *   'balances' => [['account_id' => 7, 'side' => 'debit', 'amount' => '15000.00'], ...],
 * ]
 * ```
 *
 * A party opening balance is a `balances` row against a control account, with
 * `party_id` on the transaction — because that is exactly what it is, and giving
 * it a vocabulary of its own would mean two ways to write one entry.
 */
class OpeningBalanceTemplate implements MovesStock, PostingTemplate, StatesItsOwnTotal
{
    /**
     * Memoised for the reason every stock-moving template memoises: {@see build()}
     * and the engine's own read of {@see stockChangesFrom()} happen inside one
     * `compose()` on one instance, and valuing the same rows twice is two answers
     * rather than one wasted query.
     *
     * @var array<int, StockChange>|null
     */
    private ?array $changes = null;

    public function __construct(
        private readonly ChartOfAccountService $accounts,
        private readonly StockLedgerService $stock,
        private readonly ItemVariantRepositoryInterface $variants,
        private readonly PartyRepositoryInterface $parties,
    ) {}

    public function type(): TransactionType
    {
        return TransactionType::Opening;
    }

    /**
     * @param  array{stock?: array<int, mixed>, balances?: array<int, mixed>, party_id?: int|null}  $input
     * @return array<int, PostingLine>
     *
     * @throws InvalidJournalException|InvalidStockMovementException|OpeningBalanceException
     */
    public function build(array $input): array
    {
        $equity = $this->accounts->system(SystemAccount::OpeningBalanceEquity)->id;
        $inventory = $this->accounts->system(SystemAccount::Inventory)->id;

        $lines = [];

        // Stock first, so an import that declares both reads on the OBE ledger
        // in the order somebody would recite it: the shelf, then the debts.
        foreach ($this->stockChangesFrom($input) as $change) {
            $value = $change->value->absolute();

            // A variant carried at nothing contributes nothing to the books, and
            // that is legitimate — a free sample is genuinely worth zero. The
            // quantity still moves; there is simply no equity in it. An opening
            // balance where *everything* is worthless is refused below, because
            // it would put stock on the shelf with no accounting trace at all.
            if ($value->isZero()) {
                continue;
            }

            $lines[] = PostingLine::debit($inventory, $value, $change->memo);
            $lines[] = PostingLine::credit($equity, $value, $change->memo);
        }

        foreach ($this->balanceRowsFrom($input) as $row) {
            [$account, $side, $amount, $memo] = $row;

            $lines[] = PostingLine::on($side, $account->id, $amount, $memo);
            $lines[] = PostingLine::on($side->opposite(), $equity, $amount, $memo);
        }

        if ($lines === []) {
            throw OpeningBalanceException::nothingToDeclare();
        }

        return $lines;
    }

    /**
     * What the workshop is declaring it is worth, on the debit side.
     *
     * Stated rather than inferred from the debits, for the same reason a sale
     * states its own total: half of an opening balance's debits are the equity
     * side of its payables, and reporting an import of ₹1,00,000 of assets and
     * ₹40,000 of debts as a ₹1,40,000 transaction would be wrong on every list
     * it appears in.
     *
     * @param  array<string, mixed>  $input
     */
    public function documentTotal(array $input): Money
    {
        return Money::sum([
            ...array_map(
                fn (StockChange $change) => $change->value->absolute(),
                $this->stockChangesFrom($input),
            ),
            ...array_map(
                fn (array $row) => $row[2],
                $this->balanceRowsFrom($input),
            ),
        ]);
    }

    /* ---------------------------------------------------------------------
     | The shelf
     |-------------------------------------------------------------------- */

    /**
     * @param  array{stock?: array<int, mixed>}  $input
     * @return array<int, StockChange>
     *
     * @throws InvalidStockMovementException|OpeningBalanceException
     */
    public function stockChangesFrom(array $input): array
    {
        if ($this->changes !== null) {
            return $this->changes;
        }

        $changes = [];

        foreach (array_values((array) ($input['stock'] ?? [])) as $row) {
            // Already a value object when the engine is re-reading a batch it
            // composed; an array when it arrived from an import or a stored draft.
            if ($row instanceof StockChange) {
                $changes[] = $row;

                continue;
            }

            $row = (array) $row;
            $variant = $this->resolveVariant((int) ($row['variant_id'] ?? 0));

            $quantity = Quantity::of($row['quantity'] ?? 0);

            // Opening stock is only ever a *positive* declaration. A negative
            // opening quantity would be a workshop claiming it went live owing
            // somebody four bearings, which is not a thing — and it would post as
            // an issue out of a position that does not exist yet, valued at a
            // fallback rate nobody supplied.
            if (! $quantity->isPositive()) {
                throw OpeningBalanceException::nonPositiveStock($variant->displayLabel());
            }

            $unitCost = Money::of($row['unit_cost'] ?? 0);

            // Stated, never derived. There is no weighted average to read yet —
            // this movement is the first thing that will create one — so the cost
            // has to come from whatever record the workshop kept before, and
            // `receipt()` is handed a total rather than being asked to guess.
            $changes[] = $this->stock->receipt(
                $variant,
                $quantity,
                $quantity->costAt($unitCost),
                StockMovementType::Opening,
                $this->memoFor($variant, $row['memo'] ?? null),
            );
        }

        return $this->changes = $changes;
    }

    /* ---------------------------------------------------------------------
     | The debts, the cash, and everything else
     |-------------------------------------------------------------------- */

    /**
     * The non-stock rows, resolved: an account, the side it opens on, how much,
     * and what to call it on the ledger.
     *
     * @param  array{balances?: array<int, mixed>, party_id?: int|null}  $input
     * @return array<int, array{0: ChartOfAccount, 1: BalanceSide, 2: Money, 3: string|null}>
     *
     * @throws OpeningBalanceException|InvalidJournalException
     */
    private function balanceRowsFrom(array $input): array
    {
        $rows = [];

        foreach (array_values((array) ($input['balances'] ?? [])) as $index => $row) {
            $row = (array) $row;
            $lineNo = $index + 1;

            $account = $this->accounts->find((int) ($row['account_id'] ?? 0));

            // Opening Balance Equity is the other side of every row here. A row
            // that named it as *this* side would post OBE against itself: two
            // lines that cancel, on one account, declaring nothing. Refused
            // rather than dropped, because a caller told their row saved while
            // nothing changed is worse off than one told it was rejected.
            if ($account->represents(SystemAccount::OpeningBalanceEquity)) {
                throw OpeningBalanceException::equityIsNotADeclaration();
            }

            // Inventory is declared by moving stock, never by naming the account:
            // a rupee figure typed straight into Inventory would put value in the
            // books with nothing on the shelf behind it, which is precisely the
            // disagreement M8's invariant exists to make impossible.
            if ($account->represents(SystemAccount::Inventory)) {
                throw OpeningBalanceException::inventoryNeedsQuantities();
            }

            $amount = Money::of($row['amount'] ?? 0);

            if (! $amount->isPositive()) {
                throw OpeningBalanceException::nonPositiveBalance($account->name);
            }

            // Defaulted from the account's own type, because that is right for
            // every ordinary row: an asset opens as a debit and a liability as a
            // credit. Overridable because the exceptions are real — a bank account
            // overdrawn at go-live opens on the credit side, and refusing it would
            // refuse a true fact about the workshop.
            $side = $this->sideFor($row['side'] ?? null, $account, $lineNo);

            $this->assertPartyRoleMatches($account, $input['party_id'] ?? null);

            $rows[] = [$account, $side, $amount, $this->trimmed($row['memo'] ?? null)];
        }

        return $rows;
    }

    /**
     * @throws OpeningBalanceException
     */
    private function sideFor(mixed $requested, ChartOfAccount $account, int $lineNo): BalanceSide
    {
        $requested = trim((string) ($requested ?? ''));

        if ($requested === '') {
            return $account->normalBalance();
        }

        return BalanceSide::tryFrom($requested)
            ?? throw OpeningBalanceException::unknownSide($lineNo, $requested);
    }

    /**
     * The claim a control-account row makes about the counterparty, checked.
     *
     * Debiting Sundry Debtors *is* the claim "this business owed us money at
     * go-live", so the party has to be a customer — the same reasoning that puts
     * a role check on a payment in {@see TransactionType::requiredPartyRole()}.
     * It lives here rather than on the type because an opening balance can be
     * either claim, and which one depends on the account the row names.
     *
     * Note what is *not* checked. A row against Cash, Bank or a loan account
     * needs no party at all, and demanding one would make an owner invent a
     * counterparty for their own till. Nor does the *side* enter into it: a
     * customer who had paid in advance at go-live opens as a credit on Sundry
     * Debtors and is still a customer, which is M5's overpayment decision
     * arriving at the same answer from the other end.
     *
     * @throws InvalidJournalException|OpeningBalanceException
     */
    private function assertPartyRoleMatches(ChartOfAccount $account, ?int $partyId): void
    {
        $role = match (true) {
            $account->represents(SystemAccount::Receivables) => PartyRole::Customer,
            $account->represents(SystemAccount::Payables) => PartyRole::Vendor,
            default => null,
        };

        if ($role === null) {
            return;
        }

        if ($partyId === null) {
            // A control-account balance with nobody behind it is money the
            // workshop can never chase or settle: the total is right and no
            // statement can account for a rupee of it.
            throw OpeningBalanceException::controlAccountNeedsParty($account->name);
        }

        $party = $this->parties->findById($partyId)
            ?? throw InvalidJournalException::unknownParty($partyId);

        if (! $party->hasRole($role)) {
            throw InvalidJournalException::partyRoleMismatch(
                $party->id,
                $party->name,
                $role->label(),
                'opening balance',
            );
        }
    }

    /* ---------------------------------------------------------------------
     | Internals
     |-------------------------------------------------------------------- */

    private function resolveVariant(int $id): ItemVariant
    {
        return $this->variants->findWithItem($id)
            ?? throw InvalidStockMovementException::unknownVariant($id);
    }

    /**
     * Every line says which variant it is about.
     *
     * Inventory and Opening Balance Equity are two accounts the whole workshop
     * shares, so without this the go-live entry would read as a single
     * unexplained figure — which is the state somebody reconciling an import is
     * trying to get out of.
     */
    private function memoFor(ItemVariant $variant, mixed $note): string
    {
        $label = $variant->displayLabel();
        $note = trim((string) ($note ?? ''));

        return $note === '' ? $label : sprintf('%s · %s', $label, $note);
    }

    private function trimmed(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
