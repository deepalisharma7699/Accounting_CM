<?php

namespace App\Services\Workshop;

use App\Enums\DocumentSeries;
use App\Enums\PartyRole;
use App\Enums\TransactionType;
use App\Enums\WorkshopJobStatus;
use App\Exceptions\Accounting\InvalidJournalException;
use App\Exceptions\ResourceNotFoundException;
use App\Exceptions\Workshop\InvalidJobLineException;
use App\Exceptions\Workshop\InvalidJobStateException;
use App\Models\Item;
use App\Models\ItemVariant;
use App\Models\Party;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WorkshopJob;
use App\Models\WorkshopJobPart;
use App\Repositories\Contracts\ItemRepositoryInterface;
use App\Repositories\Contracts\ItemVariantRepositoryInterface;
use App\Repositories\Contracts\PartyRepositoryInterface;
use App\Repositories\Contracts\WorkshopJobRepositoryInterface;
use App\Services\Accounting\BillService;
use App\Services\Accounting\DocumentNumberService;
use App\Services\Accounting\TransactionService;
use App\Support\Money;
use App\Support\Quantity;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The workshop's own workflow: a motor arrives, is looked at, is quoted, is
 * repaired, and is billed — M19, and the brief's §16 to §18.
 *
 * ## What this service is careful about
 *
 * **It re-enters nothing.** Billing a job builds the exact payload
 * `POST /transactions/sale` accepts and hands it to {@see TransactionService},
 * so the tax arithmetic, the stock issue, the cost of goods sold, the document
 * numbering, the duplicate protection and the negative-stock refusal are the
 * ones the counter already uses. There is no second bill engine here, and there
 * must never be one: the GST on a workshop invoice ends up on a government
 * return, and two implementations of it agree right up until the month they do
 * not.
 *
 * **Stock moves once, at billing.** Adding a part to a job writes a row in
 * `workshop_job_parts` and touches nothing else — decision D2. The bearing
 * leaves the shelf when the invoice posts. See the migration for why giving up
 * reservation is the cheaper half of that trade.
 *
 * **A job cannot be billed twice for the same part.** Not by a flag, which
 * somebody would have to remember to set, but because each part points at the
 * invoice line it became. A second bill simply finds nothing left to bill and is
 * refused, and that stays true however the first invoice was raised.
 *
 * ## What it does not do
 *
 * It does not reverse or amend an invoice. A bill raised off a job in error is
 * reversed like any other posted transaction, through the ledger's own path —
 * which leaves both the mistake and the correction visible, and leaves the parts
 * still marked as billed against a document that is now cancelled. That is the
 * honest record: the parts *were* billed, on a bill that was then cancelled, and
 * re-billing them means adding them again as what they now are.
 */
class JobService
{
    public function __construct(
        private readonly WorkshopJobRepositoryInterface $jobs,
        private readonly PartyRepositoryInterface $parties,
        private readonly ItemRepositoryInterface $items,
        private readonly ItemVariantRepositoryInterface $variants,
        private readonly TransactionService $transactions,
        private readonly BillService $bills,
        private readonly DocumentNumberService $numbers,
    ) {}

    /* ---------------------------------------------------------------------
     | Reading
     |-------------------------------------------------------------------- */

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, WorkshopJob>
     */
    public function paginate(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        $page = $this->jobs->paginate($filters, $perPage);

        $this->attachBilled(collect($page->items()));

        return $page;
    }

    public function find(int $id): WorkshopJob
    {
        $job = $this->jobs->findWithDetail($id)
            ?? throw new ResourceNotFoundException('Job', $id);

        $this->attachBilled(collect([$job]));

        return $job;
    }

    /**
     * @return array<string, int>
     */
    public function countsByStatus(): array
    {
        return $this->jobs->countsByStatus();
    }

    /**
     * Hang what each job has been billed onto the models, for the resource to
     * serialise.
     *
     * Attached rather than computed in the resource, for the reason a bill's
     * settlement is: it depends on rows a per-row serialiser cannot see, and
     * going to look for them would be a query per row of every listing in the
     * module. The paid and due figures come from {@see BillService}, so a job's
     * "₹4,000 outstanding" is the same arithmetic the bills list shows — not a
     * second opinion about the same invoice.
     *
     * @param  Collection<int, WorkshopJob>  $jobs
     */
    private function attachBilled(Collection $jobs): void
    {
        if ($jobs->isEmpty()) {
            return;
        }

        $bills = Transaction::query()
            ->whereIn('workshop_job_id', $jobs->pluck('id')->all())
            ->inTheBooks()
            ->get();

        $settlements = $this->bills->settlementsFor($bills);

        foreach ($jobs as $job) {
            $mine = $bills->where('workshop_job_id', (int) $job->id);

            $total = Money::zero();
            $paid = Money::zero();
            $due = Money::zero();

            foreach ($mine as $bill) {
                $position = $settlements[(int) $bill->id] ?? null;

                // A reversed invoice contributes nothing to any of the three: it
                // was cancelled, and counting its total would tell a workshop it
                // had billed for work it then un-billed.
                if ($position === null) {
                    continue;
                }

                $total = $total->plus(Money::of($position['total']));
                $paid = $paid->plus(Money::of($position['paid']));
                $due = $due->plus(Money::of($position['due']));
            }

            $job->billed = [
                'total' => $total->amount(),
                'paid' => $paid->amount(),
                'due' => $due->amount(),
                'count' => $mine->count(),
            ];
        }
    }

    /* ---------------------------------------------------------------------
     | Booking a motor in
     |-------------------------------------------------------------------- */

    /**
     * Book a motor in.
     *
     * The number is taken inside a database transaction, under the same locked
     * counter every invoice number comes from — two motors on two benches
     * carrying one ticket is the same unrecoverable mess as two invoices
     * carrying one number.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidJournalException
     */
    public function create(array $data, ?User $actor = null): WorkshopJob
    {
        $party = $this->requireCustomer((int) $data['party_id']);
        $receivedDate = $data['received_date'] ?? now()->toDateString();

        $job = DB::transaction(fn () => $this->jobs->create([
            'job_no' => $this->numbers->assignSeries(
                DocumentSeries::JobCard,
                CarbonImmutable::parse($receivedDate),
            ),
            'party_id' => $party->id,
            'item_id' => $this->resolveItemId($data['item_id'] ?? null),
            'hp' => $this->trimmed($data['hp'] ?? null),
            'brand' => $this->trimmed($data['brand'] ?? null),
            'model' => $this->trimmed($data['model'] ?? null),
            'serial_no' => $this->trimmed($data['serial_no'] ?? null),
            'phase' => $this->trimmed($data['phase'] ?? null),
            'complaint' => trim((string) $data['complaint']),
            'received_date' => $receivedDate,
            'promised_date' => $data['promised_date'] ?? null,
            // Always. There is no way to book a motor in as anything else, and
            // that is deliberate: the complaint and the motor are what a job *is*,
            // and both are known the moment it arrives.
            'status' => WorkshopJobStatus::Received,
            'notes' => $this->trimmed($data['notes'] ?? null),
            'created_by' => $actor?->id,
        ]));

        return $this->find((int) $job->id);
    }

    /**
     * Correct the details of a job — the motor, the complaint, the promise.
     *
     * Not the status, which has its own verb, and not the customer: an invoice
     * may already have been raised against them, and re-pointing the job would
     * leave the bill explaining a repair for somebody else. Booking it in against
     * the wrong customer is corrected by cancelling and re-booking, which is
     * cheap while nothing has been billed and is honest once something has.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(int $id, array $data): WorkshopJob
    {
        $job = $this->find($id);

        $this->assertUnfinished($job);

        $attributes = [];

        foreach (['hp', 'brand', 'model', 'serial_no', 'phase', 'notes'] as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $this->trimmed($data[$field]);
            }
        }

        if (array_key_exists('item_id', $data)) {
            $attributes['item_id'] = $this->resolveItemId($data['item_id']);
        }

        if (array_key_exists('complaint', $data)) {
            $attributes['complaint'] = trim((string) $data['complaint']);
        }

        if (array_key_exists('promised_date', $data)) {
            $attributes['promised_date'] = $data['promised_date'];
        }

        $this->jobs->update($job, $attributes);

        return $this->find($id);
    }

    /**
     * Throw a job away.
     *
     * Only ever reaches one that has never been billed. Anything with a document
     * pointing at it is refused and cancelled instead — the same rule an account,
     * a party and an item follow, for the same reason: the invoice would lose the
     * job that explains it.
     */
    public function delete(int $id): void
    {
        $job = $this->find($id);
        $bills = $this->jobs->billCount((int) $job->id);

        if ($bills > 0) {
            throw InvalidJobStateException::billed($job->job_no, $bills);
        }

        $this->jobs->delete($job);
    }

    /* ---------------------------------------------------------------------
     | The pipeline
     |-------------------------------------------------------------------- */

    /**
     * Move a job along, refusing anything the pipeline does not allow.
     *
     * The legal moves are declared on {@see WorkshopJobStatus} rather than here,
     * so the screen's pipeline control, this refusal and the `meta` endpoint all
     * read one answer. A copy in the browser would drift the day a state was
     * added, and the drift shows up as a button that does nothing.
     *
     * `delivered_at` is stamped as a side effect of reaching `delivered`, because
     * the two are one fact: a status a worklist filters on, and the timestamp
     * somebody needs when a customer rings to ask when they collected it.
     */
    public function advance(int $id, WorkshopJobStatus $to, ?string $notes = null): WorkshopJob
    {
        $job = $this->find($id);

        if ($job->status === $to) {
            // Idempotent rather than refused. Two clerks tapping "Ready" is not a
            // mistake anybody needs telling about, and it is exactly what happens
            // on a slow connection.
            return $job;
        }

        if (! $job->status->canMoveTo($to)) {
            throw InvalidJobStateException::illegalTransition($job->job_no, $job->status, $to);
        }

        $attributes = ['status' => $to];

        if ($to === WorkshopJobStatus::Delivered) {
            $attributes['delivered_at'] = CarbonImmutable::now();
        }

        // Moving off `delivered` is impossible — it is terminal — so the stamp is
        // never cleared. Nothing here can produce a job that claims to have been
        // collected on a day it was on the bench.

        if ($notes !== null && trim($notes) !== '') {
            $attributes['notes'] = trim($notes);
        }

        $this->jobs->update($job, $attributes);

        return $this->find($id);
    }

    /* ---------------------------------------------------------------------
     | Parts
     |-------------------------------------------------------------------- */

    /**
     * Write a part onto the job.
     *
     * Nothing moves. The description, unit and price are resolved and copied here
     * rather than joined later, for the same reason a bill line copies them: the
     * job card has to say what was fitted, in the words that were true when it
     * was fitted, after somebody renames the variant next year.
     *
     * @param  array<string, mixed>  $data
     */
    public function addPart(int $id, array $data): WorkshopJob
    {
        $job = $this->find($id);

        $this->assertUnfinished($job);

        [$item, $variant] = $this->resolveLineItem($data);

        $quantity = Quantity::of($data['quantity'] ?? 0);

        if (! $quantity->fitsUnit($item->base_uom)) {
            throw InvalidJobLineException::fractionalUnit(
                $item->name,
                $quantity->trimmed(),
                $item->base_uom->label(),
            );
        }

        $this->jobs->addPart($job, [
            'item_id' => $item->id,
            'variant_id' => $variant?->id,
            'description' => $variant?->displayLabel() ?? $item->name,
            'quantity' => $quantity->amount(),
            // Copied from the family, exactly as a bill line copies it: `each`
            // must not become `kilogram` on a job card already handed over.
            'unit' => $item->base_uom,
            'unit_price' => Money::of($data['unit_price'] ?? $variant?->sell_price ?? 0)->amount(),
            'discount_amount' => Money::of($data['discount'] ?? 0)->amount(),
            'memo' => $this->trimmed($data['memo'] ?? null),
        ]);

        return $this->find($id);
    }

    /**
     * Take a part back off the job.
     *
     * Refused once it has been billed: the bearing is on an invoice the customer
     * is holding, and quietly removing it from the job would leave the two
     * disagreeing about what was fitted. Correcting a billed part is a credit
     * note against the invoice — M18's path, and the one that leaves a record.
     */
    public function removePart(int $id, int $partId): WorkshopJob
    {
        $job = $this->find($id);

        $part = $this->jobs->findPart($job, $partId)
            ?? throw new ResourceNotFoundException('Job part', $partId);

        if ($part->isBilled()) {
            throw InvalidJobStateException::partNotRemovable($job->job_no, $partId);
        }

        $this->jobs->deletePart($part);

        return $this->find($id);
    }

    /* ---------------------------------------------------------------------
     | The estimate — §18
     |-------------------------------------------------------------------- */

    /**
     * Save or replace the quotation.
     *
     * A field on the job, not a transaction — decision D3. An estimate that
     * posted journal entries would be claiming revenue nobody has agreed to, and
     * a customer who said no would leave a cancelled invoice on a job that never
     * happened.
     *
     * Replacing an approved estimate clears the approval, and that is the point:
     * the customer agreed to a figure, and if the figure changes they have not
     * agreed to the new one. Silently keeping the tick would let a re-quote
     * inherit an approval it was never given.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function saveEstimate(int $id, array $lines, ?string $notes = null): WorkshopJob
    {
        $job = $this->find($id);

        $this->assertUnfinished($job);

        $this->jobs->update($job, [
            'estimate_lines' => $this->normaliseEstimate($lines),
            'estimate_approved_at' => null,
            'notes' => $notes === null ? $job->notes : trim($notes),
        ]);

        return $this->find($id);
    }

    /**
     * Record that the customer said yes.
     *
     * Separate from saving the estimate, because they are separate events that
     * often happen days apart, and because an approval that arrived with the
     * quotation would mean nobody was ever asked.
     */
    public function approveEstimate(int $id, bool $approved = true): WorkshopJob
    {
        $job = $this->find($id);

        $this->assertUnfinished($job);

        if (! $job->hasEstimate()) {
            throw InvalidJobLineException::noEstimate($job->job_no);
        }

        $this->jobs->update($job, [
            'estimate_approved_at' => $approved ? CarbonImmutable::now() : null,
        ]);

        return $this->find($id);
    }

    /**
     * The estimate, cleaned up: real items, quantities and prices, in the shape a
     * bill's `items` payload uses.
     *
     * Stored in that shape rather than in one of its own, so converting a
     * quotation into an invoice is a copy rather than a translation — and so the
     * one thing that could differ between what was quoted and what was billed is
     * something somebody deliberately changed.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array<string, mixed>>
     */
    private function normaliseEstimate(array $lines): array
    {
        $normalised = [];

        foreach ($lines as $line) {
            [$item, $variant] = $this->resolveLineItem($line);

            $normalised[] = [
                'item_id' => (int) $item->id,
                'variant_id' => $variant?->id === null ? null : (int) $variant->id,
                'description' => $variant?->displayLabel() ?? $item->name,
                'quantity' => Quantity::of($line['quantity'] ?? 0)->amount(),
                'unit' => $item->base_uom->value,
                'unit_price' => Money::of($line['unit_price'] ?? $variant?->sell_price ?? 0)->amount(),
                'discount' => Money::of($line['discount'] ?? 0)->amount(),
                'memo' => $this->trimmed($line['memo'] ?? null),
            ];
        }

        return $normalised;
    }

    /**
     * Copy an approved estimate onto the job as parts.
     *
     * The §18 conversion, and the reason the two are stored in one shape. What
     * was quoted is usually what is fitted, and re-typing six lines to say so is
     * how a workshop ends up billing something different from what it quoted.
     *
     * Additive rather than replacing: parts already on the job stay, because they
     * are a record of what was actually fitted and the estimate is a record of
     * what was expected to be.
     */
    public function partsFromEstimate(int $id): WorkshopJob
    {
        $job = $this->find($id);

        $this->assertUnfinished($job);

        foreach ($job->estimate_lines ?? [] as $line) {
            $this->addPart($id, $line);
        }

        return $this->find($id);
    }

    /* ---------------------------------------------------------------------
     | Billing
     |-------------------------------------------------------------------- */

    /**
     * The payload `POST /transactions/sale` accepts, built from the job.
     *
     * Public because the counter screen reads it: "Generate bill" lands on
     * `/bills/new` pre-filled with exactly this, so the operator can change a
     * price or add a line before committing — and what they are looking at is the
     * same structure {@see bill()} would post, not a rendering of it.
     *
     * Only the unbilled parts. A job billed in two halves — an advance and the
     * balance — must not put the first half's bearings on the second invoice.
     *
     * @return array<string, mixed>
     */
    public function billPayloadFor(WorkshopJob $job, ?string $date = null): array
    {
        $parts = $job->unbilledParts();

        return [
            'date' => $date ?? now()->toDateString(),
            'party_id' => (int) $job->party_id,
            'notes' => sprintf('%s — %s', $job->job_no, $job->motorLabel()),
            'items' => $parts->map(fn (WorkshopJobPart $part) => [
                'item_id' => (int) $part->item_id,
                'variant_id' => $part->variant_id === null ? null : (int) $part->variant_id,
                'quantity' => $part->quantityValue()->amount(),
                'unit_price' => $part->unitPriceMoney()->amount(),
                'discount' => $part->discountMoney()->amount(),
                'memo' => $part->memo,
            ])->values()->all(),
            // Echoed so a screen can label each line with the part it came from,
            // and so `bill()` can pair the posted lines back to the parts without
            // guessing at the order.
            'part_ids' => $parts->map(fn (WorkshopJobPart $part) => (int) $part->id)->values()->all(),
            'payments' => [],
        ];
    }

    /**
     * Raise the invoice.
     *
     * One database transaction around three writes that have to agree: the sale
     * itself, the stamp on it saying which job it came off, and the pointers from
     * each part to the line it became. A crash between them would otherwise leave
     * a job that could be billed a second time for bearings that have already
     * left the shelf.
     *
     * Everything about the *bill* — the tax, the stock issue, the cost of goods
     * sold, the numbering, the duplicate protection, the negative-stock refusal —
     * is the existing engine's, reached through {@see TransactionService::create()}
     * exactly as the counter reaches it. Nothing about billing is reimplemented
     * here, and that is the whole design of this method.
     *
     * @param  array<string, mixed>  $overrides  Anything the operator changed at the counter.
     *
     * @throws InvalidJobStateException
     */
    public function bill(int $id, array $overrides = [], ?User $actor = null): Transaction
    {
        $job = $this->find($id);

        /*
        | The retry is answered before any refusal below, and it has to be.
        |
        | M17's duplicate protection lives inside TransactionService, which is too
        | late here: the first attempt marked every part as billed, so a second
        | request carrying the same client_ref would be refused for having nothing
        | left to bill — which is exactly backwards. The clerk who tapped Save
        | twice needs the invoice, and it already exists.
        */
        $existing = ($overrides['client_ref'] ?? null) === null
            ? null
            : $this->transactions->findByClientRef((string) $overrides['client_ref']);

        if ($existing !== null) {
            return $existing;
        }

        if (! $job->isBillable()) {
            throw InvalidJobStateException::notBillable($job->job_no, $job->status);
        }

        $payload = $this->billPayloadFor($job, $overrides['date'] ?? null);

        if ($payload['items'] === []) {
            throw InvalidJobStateException::nothingToBill($job->job_no);
        }

        $partIds = $payload['part_ids'];
        unset($payload['part_ids']);

        // The operator's own changes win. They are standing in front of the
        // customer and the job card is a week old.
        $payload = array_merge($payload, array_intersect_key($overrides, array_flip([
            'date', 'notes', 'items', 'payments', 'client_ref',
        ])));

        // Where the lines were replaced wholesale the pairing no longer holds —
        // line 3 of the operator's list is not part 3 of the job — so nothing is
        // marked. The parts stay unbilled and visible, which is the safe way to be
        // wrong: somebody sees them again rather than a bearing silently vanishing
        // off the job card.
        $pairable = ! array_key_exists('items', $overrides);

        $payload['post'] = true;

        return DB::transaction(function () use ($job, $payload, $partIds, $pairable, $actor) {
            $bill = $this->transactions->create(TransactionType::Sale, $payload, $actor);

            // A repeat of a request that already went through — M17's client_ref
            // path. The first attempt did all of this; doing it again would stamp
            // a second job onto an invoice that already names one, which the
            // model refuses outright.
            if (! $bill->wasRecentlyCreated) {
                return $bill;
            }

            // Write-once provenance, stamped inside the same wrapper as the
            // posting — see Transaction::STAMPABLE_ONCE_POSTED.
            $bill->forceFill(['workshop_job_id' => (int) $job->id])->save();

            if ($pairable) {
                $this->markPartsBilled($job, $bill, $partIds);
            }

            return $bill;
        });
    }

    /**
     * Point each part at the invoice line it became.
     *
     * By position, which is exactly what it looks like and is safe because both
     * sides came out of {@see billPayloadFor()} in one pass: the nth item on the
     * payload is the nth part, and the engine numbers lines by their position in
     * the array it was handed — see `EloquentTransactionLineRepository::writeFor()`.
     * The moment an operator replaces the lines that stops being true, which is
     * why the caller checks before asking for this.
     *
     * @param  array<int, int>  $partIds
     */
    private function markPartsBilled(WorkshopJob $job, Transaction $bill, array $partIds): void
    {
        $lines = $bill->lines()->orderBy('line_no')->get()->values();

        foreach (array_values($partIds) as $index => $partId) {
            $line = $lines->get($index);
            $part = $this->jobs->findPart($job, $partId);

            if ($line === null || $part === null) {
                continue;
            }

            $this->jobs->markPartBilled($part, (int) $line->id);
        }
    }

    /* ---------------------------------------------------------------------
     | Resolving what a line names
     |-------------------------------------------------------------------- */

    /**
     * The item and variant a part or estimate line names.
     *
     * Accepts either — a variant where the catalogue has one, the family itself
     * for a service, which has nothing to vary and no stock to count. The same
     * two shapes a bill line accepts, so a payload built for one works for the
     * other.
     *
     * @param  array<string, mixed>  $line
     * @return array{0: Item, 1: ItemVariant|null}
     *
     * @throws InvalidJobLineException
     */
    private function resolveLineItem(array $line): array
    {
        $variantId = ($line['variant_id'] ?? null) === '' ? null : ($line['variant_id'] ?? null);
        $itemId = ($line['item_id'] ?? null) === '' ? null : ($line['item_id'] ?? null);

        if ($variantId !== null) {
            $variant = $this->variants->findWithItem((int) $variantId)
                ?? throw InvalidJobLineException::unknownVariant((int) $variantId);

            // Unreachable through findWithItem(), which joins the family — and
            // refused rather than assumed, so a change to that repository can
            // never turn into a null dereference on the job card.
            if ($variant->item === null) {
                throw InvalidJobLineException::unknownVariant((int) $variantId);
            }

            return [$variant->item, $variant];
        }

        if ($itemId === null) {
            throw InvalidJobLineException::itemRequired();
        }

        $item = $this->items->findById((int) $itemId)
            ?? throw InvalidJobLineException::unknownItem((int) $itemId);

        // The refusal that matters, and it is the same one the bill engine makes:
        // stock is counted per variant, so a stocked family on its own leaves the
        // position of every rating unknowable. Caught here rather than at billing,
        // where the job card has already been printed.
        if ($item->tracksStock()) {
            throw InvalidJobLineException::needsVariant($item->name);
        }

        return [$item, null];
    }

    private function resolveItemId(mixed $itemId): ?int
    {
        if ($itemId === null || $itemId === '') {
            return null;
        }

        $item = $this->items->findById((int) $itemId)
            ?? throw InvalidJobLineException::unknownItem((int) $itemId);

        return (int) $item->id;
    }

    /**
     * The customer whose motor this is.
     *
     * Held to the customer role, and for the reason a sale is: the invoice this
     * job eventually produces debits Sundry Debtors, which *is* the claim that
     * this party is a customer of ours. Refusing here means the refusal lands
     * while the motor is still on the counter, rather than a fortnight later when
     * somebody tries to bill it.
     */
    private function requireCustomer(int $partyId): Party
    {
        $party = $this->parties->findById($partyId)
            ?? throw InvalidJournalException::unknownParty($partyId);

        if (! $party->is_active) {
            throw InvalidJournalException::archivedParty((int) $party->id, $party->name);
        }

        if (! $party->hasRole(PartyRole::Customer)) {
            throw InvalidJournalException::partyRoleMismatch(
                (int) $party->id,
                $party->name,
                PartyRole::Customer->label(),
                'Workshop job',
            );
        }

        return $party;
    }

    /**
     * @throws InvalidJobStateException
     */
    private function assertUnfinished(WorkshopJob $job): void
    {
        if ($job->status->isFinished()) {
            throw InvalidJobStateException::finished($job->job_no, $job->status);
        }
    }

    private function trimmed(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        // Blank is absent, not "". A form submits every field it renders, and an
        // untouched box stored as an empty string is noise every later reader has
        // to filter out.
        return $trimmed === '' ? null : $trimmed;
    }
}
