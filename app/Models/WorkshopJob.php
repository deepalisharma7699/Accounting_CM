<?php

namespace App\Models;

use App\Enums\JobBillingState;
use App\Enums\WorkshopJobStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Support\Money;
use App\Support\Quantity;
use Database\Factories\WorkshopJobFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Something on the bench — M19.
 *
 * The one record in this application that is about a physical object rather than
 * about money. Everything else here — a bill, a receipt, a stock movement —
 * describes something that happened to the workshop's books; this describes a
 * pump motor with a burnt winding, and its statuses are about the object.
 *
 * **It is not always a motor**, and the schema stopped saying so. Most of what
 * comes through the door is a motor; a good deal of it is a cooler, a table fan
 * or a pump, and now and then it is something nobody expected. What kind of
 * thing it is comes from {@see ItemCategory} — the catalogue's own vocabulary,
 * edited by an admin — and what was recorded about it is {@see $specs}, keyed by
 * the same attributes a variant of that category answers. There is no motor
 * column and there must not be one again.
 *
 * **There is no total column, by design.** What the job is worth is the bill
 * raised from it, derived on read from `transactions` — see
 * {@see billedTotal()}. That is the same rule as a party's outstanding and a
 * variant's quantity on hand, and it is the rule for the same reason: a stored
 * figure agrees with the document right up until one of them is written without
 * the other.
 *
 * **Nothing here moves stock.** A part on a job is a note about what will be
 * billed. The bearing leaves the shelf when the invoice posts, in one movement,
 * written by the posting engine like every other movement in the application.
 * See the `workshop_job_parts` migration for why that matters more than it looks
 * like it does.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $job_no
 * @property int $party_id
 * @property int|null $item_id
 * @property int|null $category_id
 * @property string|null $kind_label
 * @property string|null $brand
 * @property string|null $model
 * @property string|null $serial_no
 * @property array<string, string>|null $specs
 * @property string $complaint
 * @property Carbon $received_date
 * @property Carbon|null $promised_date
 * @property WorkshopJobStatus $status
 * @property array<int, array<string, mixed>>|null $estimate_lines
 * @property Carbon|null $estimate_approved_at
 * @property Carbon|null $delivered_at
 * @property string|null $notes
 * @property int|null $created_by
 */
#[Fillable([
    'tenant_id', 'job_no', 'party_id', 'item_id', 'category_id', 'kind_label',
    'brand', 'model', 'serial_no', 'specs',
    'complaint', 'received_date', 'promised_date', 'status',
    'estimate_lines', 'estimate_approved_at', 'delivered_at', 'notes', 'created_by',
])]
class WorkshopJob extends Model
{
    /** @use HasFactory<WorkshopJobFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * What this job has been billed, attached on read.
     *
     * A plain property rather than an attribute, and for the reason
     * {@see Transaction::$settlement} is one: an attribute set on a model marks
     * it dirty, and a job is very often read and then saved — advancing its
     * status is exactly that sequence. A dirty `billed` key would reach the
     * UPDATE statement and fail on a column that does not exist, which is the
     * sort of bug that only appears on the one path nobody exercised.
     *
     * Null where nothing computed it, which a serialiser reports as absent
     * rather than as zero — "nothing has been billed" and "nobody asked" are
     * different answers.
     *
     * @var array{total: string, paid: string, due: string, count: int, live: int}|null
     */
    public ?array $billed = null;

    /**
     * How to read {@see $specs} — the label, the unit suffix and the order, for
     * each key this job's category asks about.
     *
     * Attached by {@see \App\Services\Workshop\JobService}, a plain property
     * for the same reason {@see $billed} is one: an attribute would mark the
     * model dirty, and advancing a job's status is a read followed by a save.
     *
     * The bag on its own is `{"hp": "7.5"}`, which is unreadable without the
     * category that asked. Resolving it once per page — one query for the
     * handful of categories a page of jobs spans — is what keeps
     * {@see equipmentLabel()} able to say "7.5 HP" without a query per row.
     *
     * Null where nobody resolved it, and then the label prints what it can.
     *
     * @var array<string, array{label: string, suffix: string|null, order: int}>|null
     */
    public ?array $specSchema = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => WorkshopJobStatus::class,
            'received_date' => 'date',
            'promised_date' => 'date',
            'estimate_lines' => 'array',
            'specs' => 'array',
            'estimate_approved_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    /* ---------------------------------------------------------------------
     | Relations
     |-------------------------------------------------------------------- */

    /**
     * Whose motor it is. Never null — a job attributed to nobody could not be
     * billed and could not be returned.
     *
     * @return BelongsTo<Party, $this>
     */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /**
     * The catalogue entry for this exact product, where the workshop happens to
     * sell one.
     *
     * Optional, and the free-text `brand` / `model` columns beside it are not a
     * fallback for it: the catalogue says what the workshop deals in, and these
     * columns say what was actually wheeled through the door — very often a
     * competitor's forty-year-old unit that will never be in anybody's catalogue.
     *
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * What kind of thing came in — a motor, a cooler, a fan, a pump.
     *
     * The catalogue's own vocabulary rather than a second list of kinds: a
     * category already says what a thing of that kind is described by, and
     * {@see ItemCategory::attributeSchema()} is the question set the intake form
     * draws. A workshop that starts repairing coolers adds the category from the
     * Items card and the bench asks the right questions, with no deployment —
     * which is the acceptance criterion the catalogue module was rebuilt to.
     *
     * Nullable, like {@see item()} and for the same reason: a pump wheeled in by
     * a driver who cannot say what it is still has to be bookable. `kind_label`
     * beside it is the name at the moment it arrived, copied — so a renamed or
     * deleted category leaves the card still saying what came through the door.
     *
     * @return BelongsTo<ItemCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ItemCategory::class, 'category_id');
    }

    /**
     * @return HasMany<WorkshopJobPart, $this>
     */
    public function parts(): HasMany
    {
        return $this->hasMany(WorkshopJobPart::class)->orderBy('id');
    }

    /**
     * The invoices raised from this job — plural, deliberately.
     *
     * A long repair is legitimately billed more than once: an advance against
     * the estimate, the balance on collection. A single `bill_transaction_id`
     * column on this table could hold neither pair, which is why the link lives
     * on the transaction and this is a query.
     *
     * @return HasMany<Transaction, $this>
     */
    public function bills(): HasMany
    {
        return $this->hasMany(Transaction::class, 'workshop_job_id')->orderBy('date')->orderBy('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /* ---------------------------------------------------------------------
     | State
     |-------------------------------------------------------------------- */

    public function isBillable(): bool
    {
        return $this->status->isBillable();
    }

    /**
     * How much of this job has reached an invoice — {@see JobBillingState}.
     *
     * Decided here rather than in each screen that renders the badge, for §38's
     * reason and for §4.4's: the Jobs list, the job card and anything counted off
     * either have to agree about what "invoiced" means.
     *
     * Null where nothing computed {@see $billed}, which a serialiser reports as
     * absent rather than as `unbilled` — "nothing has been billed" and "nobody
     * asked" are different answers, and the second one rendered as the first is
     * a claim about the books.
     */
    public function billingState(): ?JobBillingState
    {
        if ($this->billed === null) {
            return null;
        }

        // `live` rather than `count`: a reversed invoice was cancelled, and a job
        // whose only bill was reversed has nothing standing against it.
        if ((int) ($this->billed['live'] ?? 0) === 0) {
            return JobBillingState::Unbilled;
        }

        return $this->hasUnbilledParts()
            ? JobBillingState::PartBilled
            : JobBillingState::Billed;
    }

    /**
     * Whether anything on the card is still to bill.
     *
     * Three ways of asking one question, cheapest first: a detail read has the
     * parts in hand, a listing has them counted by the repository, and anything
     * else pays for a query rather than answering wrongly.
     */
    private function hasUnbilledParts(): bool
    {
        if ($this->relationLoaded('parts')) {
            return $this->unbilledParts()->isNotEmpty();
        }

        if ($this->unbilled_parts_count !== null) {
            return (int) $this->unbilled_parts_count > 0;
        }

        return $this->parts()->unbilled()->exists();
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    /**
     * Whether the customer has said yes to the quotation.
     *
     * An estimate that exists and an estimate that was approved are different
     * things, and only the second is a reason to start cutting copper.
     */
    public function isEstimateApproved(): bool
    {
        return $this->estimate_approved_at !== null;
    }

    public function hasEstimate(): bool
    {
        return ($this->estimate_lines ?? []) !== [];
    }

    /**
     * What the estimate came to, at the prices quoted.
     *
     * Before tax, and it says so wherever it is shown: an estimate is a
     * conversation at a counter, not a document with a GST treatment. The
     * invoice raised from it is where the tax is worked out, on the server, once.
     */
    public function estimateTotal(): Money
    {
        $total = Money::zero();

        foreach ($this->estimate_lines ?? [] as $line) {
            // Through Quantity rather than a multiplication here, so an estimate
            // rounds the way every other line in the application rounds. Two
            // roundings of one arithmetic is how a quotation comes to be a paisa
            // away from the invoice it turns into.
            $line_total = Quantity::of($line['quantity'] ?? 0)
                ->costAt(Money::of($line['unit_price'] ?? 0))
                ->minus(Money::of($line['discount'] ?? 0));

            $total = $total->plus($line_total);
        }

        return $total;
    }

    /**
     * Parts that have not yet reached an invoice — what the next bill would
     * carry.
     *
     * This is what stops a job being billed twice, and it is a property of the
     * data rather than a flag anybody has to remember to set: a part points at
     * the line that consumed it, so a second invoice simply finds nothing left
     * to bill.
     *
     * @return \Illuminate\Support\Collection<int, WorkshopJobPart>
     */
    public function unbilledParts(): \Illuminate\Support\Collection
    {
        return $this->parts->filter(fn (WorkshopJobPart $part) => ! $part->isBilled())->values();
    }

    /* ---------------------------------------------------------------------
     | Scopes
     |-------------------------------------------------------------------- */

    /**
     * Everything still on the workshop's plate — what a worklist shows by
     * default, and what the dashboard counts.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', [
            WorkshopJobStatus::Delivered->value,
            WorkshopJobStatus::Cancelled->value,
        ]);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWithStatus(Builder $query, WorkshopJobStatus $status): Builder
    {
        return $query->where('status', $status->value);
    }

    /**
     * What came in, in the words a counter would use — "Motor 7.5 HP, 3 ph ·
     * Crompton CR-1234" — falling back to the catalogue name and then to the job
     * number.
     *
     * Two groups: what the thing is, and whose it is. Built from whatever was
     * recorded rather than requiring all of it, because a cooler with nothing
     * but a serial number is still a cooler somebody has to be able to refer to.
     *
     * The specification is summarised rather than printed whole — the first two
     * fields the category asks about, which for every kind seeded or templated
     * are the two that identify one of them at a counter (a motor's rating and
     * phase, a pump's rating and head, a lamp's wattage and voltage). The rest
     * are on the job card, where there is room for them.
     *
     * **Nothing from the bag reaches this without {@see $specSchema}.** A value
     * with no label and no unit is a bare "7.5 3 1440", which says less than
     * leaving it out; where nobody resolved the schema the label is the kind,
     * the brand and the model, all of which are columns on this row.
     */
    public function equipmentLabel(): string
    {
        $kind = trim((string) ($this->kind_label ?? ''));
        $specs = $this->specSummary();

        $groups = array_values(array_filter([
            trim($kind.' '.$specs),
            trim(implode(' ', array_filter([$this->brand, $this->model]))),
        ], fn (string $group) => $group !== ''));

        if ($groups !== []) {
            return implode(' · ', $groups);
        }

        return $this->item?->name ?? $this->job_no;
    }

    /** The first couple of recorded fields, with their units — "7.5 HP, 3 ph". */
    private function specSummary(int $limit = 2): string
    {
        return implode(', ', array_map(
            fn (array $spec) => trim($spec['value'].' '.($spec['suffix'] ?? '')),
            array_slice($this->resolvedSpecs(), 0, $limit),
        ));
    }

    /**
     * What was recorded about the thing, in the order the category asks and with
     * the words it asks in.
     *
     * Empty until {@see $specSchema} is attached, deliberately: a screen that
     * printed the raw bag would be printing JSON keys at a counter, and a
     * caller that forgot to resolve them would never find out. A value whose
     * attribute has since been deleted outright is still listed, under its key —
     * the catalogue archives rather than deletes, so this is the rare case, and
     * losing a fact about a customer's motor is worse than an ugly label.
     *
     * @return array<int, array{key: string, label: string, value: string, suffix: string|null}>
     */
    public function resolvedSpecs(): array
    {
        $values = $this->specs ?? [];
        $schema = $this->specSchema;

        if ($values === [] || $schema === null) {
            return [];
        }

        $specs = [];

        foreach ($values as $key => $value) {
            $value = trim((string) $value);

            if ($value === '') {
                continue;
            }

            $field = $schema[$key] ?? null;

            $specs[] = [
                'key' => (string) $key,
                'label' => $field['label'] ?? (string) $key,
                'value' => $value,
                'suffix' => $field['suffix'] ?? null,
                // Unknown keys sort last rather than first, which is what a big
                // number does here and what a missing one would not.
                'order' => $field['order'] ?? PHP_INT_MAX,
            ];
        }

        usort($specs, fn (array $a, array $b) => $a['order'] <=> $b['order']);

        return array_map(
            fn (array $spec) => array_diff_key($spec, ['order' => null]),
            $specs,
        );
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }
}
