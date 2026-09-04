<?php

namespace App\Models;

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
 * A motor on the bench — M19.
 *
 * The one record in this application that is about a physical object rather than
 * about money. Everything else here — a bill, a receipt, a stock movement —
 * describes something that happened to the workshop's books; this describes a
 * pump motor with a burnt winding, and its statuses are about the motor.
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
 * @property string|null $hp
 * @property string|null $brand
 * @property string|null $model
 * @property string|null $serial_no
 * @property string|null $phase
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
    'tenant_id', 'job_no', 'party_id', 'item_id',
    'hp', 'brand', 'model', 'serial_no', 'phase',
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
     * @var array{total: string, paid: string, due: string, count: int}|null
     */
    public ?array $billed = null;

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
     * The catalogue entry for this kind of motor, where the workshop happens to
     * have one.
     *
     * Optional, and the free-text `hp` / `brand` / `model` columns beside it are
     * not a fallback for it: the catalogue says what the workshop deals in, and
     * these columns say what was actually wheeled through the door — very often a
     * competitor's forty-year-old unit that will never be in anybody's catalogue.
     *
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
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
     * What the motor is, in the words a counter would use — "7.5 HP Crompton
     * 3-phase", falling back to the catalogue name and then to the job number.
     *
     * Built from whatever was recorded rather than requiring all of it: a motor
     * with nothing but a serial number is still a motor somebody has to be able
     * to refer to.
     */
    public function motorLabel(): string
    {
        $parts = array_values(array_filter([
            $this->hp === null ? null : trim($this->hp).' HP',
            $this->brand,
            $this->model,
            $this->phase,
        ], fn (?string $value) => $value !== null && trim($value) !== ''));

        if ($parts !== []) {
            return implode(' ', $parts);
        }

        return $this->item?->name ?? $this->job_no;
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }
}
