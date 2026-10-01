<?php

namespace App\Services\Workshop;

use App\Contracts\QuestionSet;
use App\Enums\DocumentSeries;
use App\Enums\PartyRole;
use App\Enums\TransactionType;
use App\Enums\WorkshopJobStatus;
use App\Exceptions\Accounting\InvalidJournalException;
use App\Exceptions\ResourceNotFoundException;
use App\Exceptions\Workshop\InvalidJobLineException;
use App\Exceptions\Workshop\InvalidJobStateException;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemVariant;
use App\Models\Party;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WorkshopJob;
use App\Models\WorkshopJobPart;
use App\Repositories\Contracts\ItemCategoryRepositoryInterface;
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
        // Still here, and only for the window before the bench's own kinds
        // exist — see resolveCategoryAsKind().
        private readonly ItemCategoryRepositoryInterface $categories,
        private readonly JobKindService $jobKinds,
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

        $this->decorate(collect($page->items()));

        return $page;
    }

    public function find(int $id): WorkshopJob
    {
        $job = $this->jobs->findWithDetail($id)
            ?? throw new ResourceNotFoundException('Job', $id);

        $this->decorate(collect([$job]));

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
     * Everything a job carries that is not a column on its row.
     *
     * Both halves are attached rather than computed per row, and for one reason:
     * each needs rows a per-row serialiser cannot see, so asking inside the
     * resource would be two queries per job of every listing in the module
     * (§7.2). One call site, so a new read path cannot get one and forget the
     * other.
     *
     * @param  Collection<int, WorkshopJob>  $jobs
     */
    private function decorate(Collection $jobs): void
    {
        $this->attachBilled($jobs);
        $this->attachSpecSchema($jobs);
    }

    /**
     * Hang the words each job's specification is read in onto the models.
     *
     * `specs` is `{"hp": "7.5"}` — the same flat bag a variant stores, and just
     * as unreadable on its own. What turns it into "Rating 7.5 HP" is the
     * category that asked, and the labels and units live in `item_attributes`.
     *
     * **One query for the page**, over the handful of categories a page of jobs
     * actually spans, rather than a lookup per row: a bench is mostly motors, so
     * twenty-five jobs are typically two categories. Resolved through
     * {@see ItemCategory::attributeSchema()}, which is the same method
     * `GET /items/meta` publishes to the create form — one definition of what a
     * kind of thing is described by, read by both (§4.4).
     *
     * Inactive attributes are included. An admin switches a field off when it
     * stops being worth asking about; the jobs that already answered it still
     * have to be able to say what the answer meant.
     *
     * @param  Collection<int, WorkshopJob>  $jobs
     */
    private function attachSpecSchema(Collection $jobs): void
    {
        $ids = $jobs->pluck($this->kindKey())->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return;
        }

        $schemas = [];

        foreach ($ids as $id) {
            $category = $this->questionSet((int) $id);

            if ($category === null) {
                continue;
            }

            $order = 0;
            $schema = [];

            foreach ($category->resolvedAttributes(false) as $attribute) {
                $schema[$attribute->key] = [
                    'label' => $attribute->label,
                    'suffix' => $attribute->suffix(),
                    'order' => $order++,
                ];
            }

            $schemas[(int) $id] = $schema;
        }

        foreach ($jobs as $job) {
            $job->specSchema = $schemas[(int) $job->{$this->kindKey()}] ?? null;
        }
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
            $live = 0;

            foreach ($mine as $bill) {
                $position = $settlements[(int) $bill->id] ?? null;

                // A reversed invoice contributes nothing to any of the three: it
                // was cancelled, and counting its total would tell a workshop it
                // had billed for work it then un-billed.
                if ($position === null) {
                    continue;
                }

                $live++;
                $total = $total->plus(Money::of($position['total']));
                $paid = $paid->plus(Money::of($position['paid']));
                $due = $due->plus(Money::of($position['due']));
            }

            $job->billed = [
                'total' => $total->amount(),
                'paid' => $paid->amount(),
                'due' => $due->amount(),
                // How many documents this job has produced, reversals included —
                // the job card lists them all, and a reversal is part of the
                // record of what happened.
                'count' => $mine->count(),
                // How many still stand, which is the different question the
                // "Invoiced" badge asks: reversing the only bill off a job puts
                // it back to not billed, with nothing having to remember.
                'live' => $live,
            ];
        }
    }

    /* ---------------------------------------------------------------------
     | Booking something in
     |-------------------------------------------------------------------- */

    /**
     * Book something in.
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
        $kind = $this->resolveKind($this->kindIdFrom($data));
        $receivedDate = $data['received_date'] ?? now()->toDateString();

        $job = DB::transaction(fn () => $this->jobs->create([
            'job_no' => $this->numbers->assignSeries(
                DocumentSeries::JobCard,
                CarbonImmutable::parse($receivedDate),
            ),
            'party_id' => $party->id,
            'item_id' => $this->resolveItemId($data['item_id'] ?? null),
            $this->kindKey() => $kind?->id,
            // Copied, not joined — the migration's reason, and the same one the
            // brand and the model beside it are copied for.
            'kind_label' => $kind?->name,
            'brand' => $this->trimmed($data['brand'] ?? null),
            'model' => $this->trimmed($data['model'] ?? null),
            'serial_no' => $this->trimmed($data['serial_no'] ?? null),
            'specs' => $this->normaliseSpecs($data['specs'] ?? null, $kind),
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

        foreach (['brand', 'model', 'serial_no', 'notes'] as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $this->trimmed($data[$field]);
            }
        }

        if (array_key_exists('item_id', $data)) {
            $attributes['item_id'] = $this->resolveItemId($data['item_id']);
        }

        /*
        | The kind and its answers move together, always.
        |
        | Correcting a job booked in as a motor to a cooler leaves every motor
        | answer meaningless — `hp` is not a field a cooler has — so `specs` is
        | filtered against whichever category the job ends up under, and a
        | request that changes the kind without resending the answers clears
        | them rather than keeping a bag nothing can read.
        */
        $kindSent = array_key_exists($this->kindKey(), $data);

        $kind = $kindSent
            ? $this->resolveKind($this->kindIdFrom($data))
            : $this->kindOf($job);

        if ($kindSent) {
            $attributes[$this->kindKey()] = $kind?->id;
            $attributes['kind_label'] = $kind?->name;
        }

        if (array_key_exists('specs', $data) || $kindSent) {
            $attributes['specs'] = $this->normaliseSpecs(
                // Held only while the kind has not moved: answers keyed to one
                // question set mean nothing under another.
                $data['specs'] ?? ($kind?->id === $job->{$this->kindKey()} ? $job->specs : null),
                $kind,
            );
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
     * the Jobs card's bill pane pre-filled with exactly this, so the operator can change a
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
            'notes' => sprintf('%s — %s', $job->job_no, $job->equipmentLabel()),
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

        /*
        | The operator's own changes win. They are standing in front of the
        | customer and the job card is a week old.
        |
        | The list is everything the shared bill document can carry, because the
        | Jobs card raises this invoice through that document and a key missing
        | from here is a control on screen that does nothing: a bill discount
        | typed and ignored, or - until C4 - the fitter and the winder dropped on
        | the one document where naming them matters most. `party_id` is
        | deliberately absent, and stays absent: whose motor this is was settled
        | when the job was opened.
        */
        $payload = array_merge($payload, array_intersect_key($overrides, array_flip([
            'date', 'notes', 'items', 'payments', 'client_ref',
            'bill_discount', 'bill_discount_percent', 'staff',
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
     * Which kind of thing came in.
     *
     * The catalogue's categories, not a second list of them — see
     * {@see WorkshopJob::category()}. Held to the ones that can be a physical
     * object: `holds_stock = false` means, in this application, produced at the
     * moment it is sold, and nobody wheels an hour of labour onto a bench. That
     * is a property of the category rather than a flag invented for this module,
     * which is why there is no `repairable` column to keep in step with it.
     *
     * Archived categories are refused for the same reason the brand dropdown
     * omits them: a category switched off is still the answer on the jobs that
     * carry it, and must not be the answer to a new one.
     */
    /**
     * Which key on an incoming payload names the kind.
     *
     * `job_kind_id` once the bench has its own list, `category_id` in the
     * window before it (§4.6) — and **never both at once**. A payload's
     * `category_id` is an `item_categories` id, so reading it as a kind id
     * after the tables exist would not be a fallback, it would be a lookup of
     * one table's id in another: a 404 where the ids happen not to collide, and
     * the wrong kind on the card where they do. A client that has not been
     * reloaded therefore books a job with no kind, which is a state the form
     * already allows and a person can correct.
     */
    private function kindKey(): string
    {
        return $this->jobKinds->isInstalled() ? 'job_kind_id' : 'category_id';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function kindIdFrom(array $data): mixed
    {
        return $data[$this->kindKey()] ?? null;
    }

    private function resolveKind(mixed $kindId): ?QuestionSet
    {
        if ($kindId === null || $kindId === '') {
            return null;
        }

        if (! $this->jobKinds->isInstalled()) {
            return $this->resolveCategoryAsKind((int) $kindId);
        }

        $kind = $this->jobKinds->byId()->get((int) $kindId);

        if ($kind === null || ! $kind->is_active) {
            throw new ResourceNotFoundException('Job kind', (int) $kindId);
        }

        return $kind;
    }

    /**
     * The list the bench read before it had one of its own.
     *
     * Reached only in the window between this code deploying and
     * `database/manual/sql/2026_09_30_001_job_kinds.sql` being run (§4.6), and
     * it is the old behaviour unchanged — `holds_stock` and all, wrong filter
     * included, because being wrong in exactly the way it was yesterday is the
     * point of a fallback.
     */
    private function resolveCategoryAsKind(int $categoryId): ItemCategory
    {
        $category = $this->categories->findWithSchema($categoryId);

        if ($category === null || ! $category->is_active || ! $category->holds_stock) {
            throw new ResourceNotFoundException('Item category', $categoryId);
        }

        return $category;
    }

    /**
     * The question set a job was booked in under, whichever shape it is.
     */
    private function kindOf(WorkshopJob $job): ?QuestionSet
    {
        $id = $job->{$this->kindKey()};

        return $id === null ? null : $this->questionSet((int) $id);
    }

    /**
     * One id, resolved with its fields loaded, on whichever side is live.
     */
    private function questionSet(int $id): ?QuestionSet
    {
        return $this->jobKinds->isInstalled()
            ? $this->jobKinds->byId()->get($id)
            : $this->categories->findWithSchema($id);
    }

    /**
     * What was recorded about the thing, filtered to what its kind actually asks
     * about and written in the order the kind asks it.
     *
     * Three decisions, and each is the opposite of what the catalogue does to
     * the same bag on a variant.
     *
     * **Nothing is required.** `is_required` on an attribute says a *product*
     * cannot exist without it — a motor with no rating is not a catalogue entry
     * anybody could sell. A job is a physical object that is already on the
     * bench: a pump is wheeled in at four in the afternoon by a driver who knows
     * none of it, and a form that refused to book it in is a form that gets a job
     * card written on paper instead. So the intake asks and never insists.
     *
     * **Nothing is coerced or checked against the options.** The catalogue
     * describes what the workshop deals in and can hold its own values to it;
     * this describes a competitor's forty-year-old unit, and a plate reading a
     * voltage nobody put in the dropdown is a fact about the object rather than a
     * mistake to refuse.
     *
     * **Keys the kind does not ask about are dropped.** A bag whose keys no
     * schema explains cannot be labelled, printed or edited — {@see
     * WorkshopJob::resolvedSpecs()} would have nothing to read it by — so a job
     * with no kind holds no specification at all. Inactive attributes still
     * count: an admin switching a field off must not blank it on the next edit
     * of every job that answered it.
     *
     * @param  mixed  $raw
     * @return array<string, string>|null
     */
    private function normaliseSpecs(mixed $raw, ?QuestionSet $kind): ?array
    {
        if ($kind === null || ! is_array($raw)) {
            return null;
        }

        $specs = [];

        foreach ($kind->resolvedAttributes(false) as $attribute) {
            $value = $this->trimmed($raw[$attribute->key] ?? null);

            if ($value !== null) {
                $specs[$attribute->key] = $value;
            }
        }

        // Absent rather than an empty object, so "nothing was recorded" reads the
        // same way it does everywhere else on this row.
        return $specs === [] ? null : $specs;
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
