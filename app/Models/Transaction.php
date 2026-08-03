<?php

namespace App\Models;

use App\Enums\TransactionSource;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Exceptions\Accounting\TransactionImmutableException;
use App\Models\Concerns\BelongsToTenant;
use App\Support\Money;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * One business event — a sale, a payment, a hand-written correction.
 *
 * The accounting consequences are in {@see JournalEntry}, and they exist only
 * once this transaction is posted. Until then its intended lines sit in
 * `draft_lines`, outside the ledger entirely, so no report has to remember to
 * exclude unauthorised work.
 *
 * **Posted is permanent.** The guard in {@see booted()} refuses any change to a
 * posted transaction beyond its status, and refuses to delete anything that is
 * not a draft. A mis-posting is corrected with a reversing entry, which is
 * addition rather than erasure and leaves the original visible.
 *
 * @property int $id
 * @property int $tenant_id
 * @property TransactionType $type
 * @property TransactionStatus $status
 * @property TransactionSource $source
 * @property int|null $party_id
 * @property Carbon $date
 * @property string $total
 * @property string|null $notes
 * @property array<int, array<string, mixed>>|null $draft_lines
 * @property array<int, array<string, mixed>>|null $draft_payments
 * @property array<string, mixed>|null $draft_payload
 * @property int|null $created_by
 * @property Carbon|null $posted_at
 * @property int|null $reverses_id
 * @property int|null $opening_import_id
 */
#[Fillable([
    'tenant_id', 'type', 'status', 'source', 'party_id', 'date', 'total',
    'notes', 'draft_lines', 'draft_payments', 'draft_payload',
    'created_by', 'posted_at', 'reverses_id', 'opening_import_id',
])]
class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * The only columns that may change once a transaction is in the books.
     *
     * `status` alone, for the single transition posted -> reversed. Everything
     * else — the date, the total, the accounts — is history the moment it is
     * posted, and a report run yesterday must still produce yesterday's
     * numbers.
     *
     * @var array<int, string>
     */
    private const MUTABLE_ONCE_POSTED = ['status', 'updated_at'];

    /**
     * Provenance that may be stamped on, once, after posting — and never
     * changed afterwards.
     *
     * `opening_import_id` is the only member and is not a financial fact: it
     * records *which file* an opening balance came from, and it is written by
     * M11's importer inside the same database transaction as the posting itself.
     * It sits outside {@see MUTABLE_ONCE_POSTED} rather than inside it because
     * the two rules are different — status may go posted → reversed, while this
     * may only go null → set. A column that could be re-pointed would let an
     * import receipt claim postings it never made.
     *
     * @var array<int, string>
     */
    private const STAMPABLE_ONCE_POSTED = ['opening_import_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'status' => TransactionStatus::class,
            'source' => TransactionSource::class,
            'date' => 'date',
            'total' => 'decimal:2',
            'draft_lines' => 'array',
            'draft_payments' => 'array',
            'draft_payload' => 'array',
            'posted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $transaction): void {
            $original = TransactionStatus::tryFrom((string) $transaction->getRawOriginal('status'));

            if ($original === null || ! $original->isInTheBooks()) {
                return;
            }

            // A reversed transaction is finished: it has already been cancelled
            // and there is no further state for it to reach.
            if ($original === TransactionStatus::Reversed) {
                throw TransactionImmutableException::reversed($transaction->id);
            }

            $changed = array_keys($transaction->getDirty());

            // Write-once provenance is allowed through only while it is still
            // unwritten. Once stamped it is as fixed as the entries themselves.
            $allowed = self::MUTABLE_ONCE_POSTED;

            foreach (self::STAMPABLE_ONCE_POSTED as $column) {
                if (in_array($column, $changed, true) && $transaction->getRawOriginal($column) === null) {
                    $allowed[] = $column;
                }
            }

            if (array_diff($changed, $allowed) !== []) {
                throw TransactionImmutableException::posted($transaction->id, $changed);
            }
        });

        static::deleting(function (self $transaction): void {
            if ($transaction->status->isInTheBooks()) {
                throw TransactionImmutableException::deleting($transaction->id);
            }
        });
    }

    /* ---------------------------------------------------------------------
     | Relations
     |-------------------------------------------------------------------- */

    /**
     * @return HasMany<JournalEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(JournalEntry::class)->orderBy('line_no');
    }

    /**
     * How the money moved, for a payment or a receipt.
     *
     * Empty for everything else, and empty for a draft — an unposted settlement's
     * intended split lives in `draft_payments`, so nothing reading this table has
     * to remember to exclude unauthorised work. The same structural guarantee the
     * ledger relies on.
     *
     * @return HasMany<TransactionPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(TransactionPayment::class)->orderBy('line_no');
    }

    /**
     * The document's own lines — M9. What was billed, as distinct from what it
     * did to the books.
     *
     * Empty for everything that is not a bill, and empty for a draft: a draft
     * holds the request in `draft_payload` and is re-priced and re-taxed when it
     * is finally posted. Nothing reading invoices has to remember to exclude
     * unauthorised work — the same structural guarantee the ledger relies on.
     *
     * @return HasMany<TransactionLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(TransactionLine::class)->orderBy('line_no');
    }

    /**
     * What quantities this transaction moved — M8.
     *
     * Empty for everything that does not touch stock, and empty for a draft: a
     * draft's intended movements are not held anywhere at all, because they
     * cannot be. Stock is valued at the weighted average *at the moment of
     * posting*, so a draft holds the request (`draft_payload`) and the movements
     * are computed when it is finally authorised. The same structural guarantee
     * the ledger relies on, arrived at from the other direction.
     *
     * @return HasMany<StockMovement, $this>
     */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class)->orderBy('line_no');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The counterparty, where there is one. Null for a correcting journal, a
     * depreciation entry or anything else with no other side to it.
     *
     * Immutable once posted, like everything else on the row: a party ledger
     * that could be re-pointed after the fact would let today's outstanding
     * disagree with a statement sent last month.
     *
     * @return BelongsTo<Party, $this>
     */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /**
     * The transaction this one cancels, if it is a reversal.
     *
     * @return BelongsTo<self, $this>
     */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_id');
    }

    /**
     * The go-live import that posted this one, if it came from one — M11.
     *
     * Null for everything typed in, which is almost everything. `source` says
     * *how* a transaction arrived; this says *which file*, which is the question
     * somebody actually asks when an opening figure looks wrong.
     *
     * @return BelongsTo<OpeningImport, $this>
     */
    public function openingImport(): BelongsTo
    {
        return $this->belongsTo(OpeningImport::class, 'opening_import_id');
    }

    /**
     * The reversal that cancelled this one, if any.
     *
     * @return HasOne<self, $this>
     */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_id');
    }

    /* ---------------------------------------------------------------------
     | State
     |-------------------------------------------------------------------- */

    public function isDraft(): bool
    {
        return $this->status === TransactionStatus::Draft;
    }

    public function isPosted(): bool
    {
        return $this->status === TransactionStatus::Posted;
    }

    public function isReversed(): bool
    {
        return $this->status === TransactionStatus::Reversed;
    }

    public function isReversal(): bool
    {
        return $this->reverses_id !== null;
    }

    public function totalMoney(): Money
    {
        return Money::of($this->total);
    }

    /* ---------------------------------------------------------------------
     | Scopes
     |-------------------------------------------------------------------- */

    /**
     * Everything that has reached the ledger — posted and reversed alike. A
     * reversed transaction's entries are still in the books; they are simply
     * cancelled by a second set.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeInTheBooks(Builder $query): Builder
    {
        return $query->whereIn('status', [
            TransactionStatus::Posted->value,
            TransactionStatus::Reversed->value,
        ]);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeDrafts(Builder $query): Builder
    {
        return $query->where('status', TransactionStatus::Draft->value);
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }
}
