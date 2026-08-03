<?php

namespace App\Models;

use App\Enums\AccountType;
use App\Enums\BalanceSide;
use App\Exceptions\Accounting\LedgerImmutableException;
use App\Models\Concerns\BelongsToTenant;
use App\Services\Accounting\PostingEngine;
use App\Support\Money;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One line of the ledger: this account, this much, on this side.
 *
 * Every number the product reports is a query over this table. There is no
 * balance column anywhere in the system — an account's balance, a party's
 * outstanding and the trial balance are all sums of these rows, because a
 * stored aggregate drifts out of step with its entries and a derived one
 * cannot.
 *
 * Three rules hold without exception:
 *
 *   1. Rows arrive only through {@see PostingEngine},
 *      in balanced sets, inside one database transaction.
 *   2. Exactly one of `debit` and `credit` is non-zero, and it is positive.
 *      Enforced by a CHECK constraint as well as by the engine.
 *   3. A row is never updated and never deleted — guarded below.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $transaction_id
 * @property int $account_id
 * @property int $line_no
 * @property string $debit
 * @property string $credit
 * @property Carbon $date
 * @property string|null $memo
 */
#[Fillable([
    'tenant_id', 'transaction_id', 'account_id', 'line_no',
    'debit', 'credit', 'date', 'memo',
])]
class JournalEntry extends Model
{
    use BelongsToTenant;

    /**
     * An entry is written once and never touched again, so a column claiming
     * to record its last modification would be a permanent lie.
     */
    public const UPDATED_AT = null;

    /**
     * Deliberately no HasFactory. A journal entry that did not come from the
     * posting engine is an unbalanced entry waiting to happen — tests post
     * through the engine like everything else does.
     */

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'debit' => 'decimal:2',
            'credit' => 'decimal:2',
            'date' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $entry): void {
            throw LedgerImmutableException::updating($entry->id);
        });

        static::deleting(function (self $entry): void {
            throw LedgerImmutableException::deleting($entry->id);
        });
    }

    /* ---------------------------------------------------------------------
     | Relations
     |-------------------------------------------------------------------- */

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * @return BelongsTo<ChartOfAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'account_id');
    }

    /* ---------------------------------------------------------------------
     | Amounts
     |-------------------------------------------------------------------- */

    public function debitMoney(): Money
    {
        return Money::of($this->debit);
    }

    public function creditMoney(): Money
    {
        return Money::of($this->credit);
    }

    public function side(): BalanceSide
    {
        return $this->debitMoney()->isZero() ? BalanceSide::Credit : BalanceSide::Debit;
    }

    public function amount(): Money
    {
        return $this->side() === BalanceSide::Debit ? $this->debitMoney() : $this->creditMoney();
    }

    /**
     * The effect of this line on an account whose balance normally sits on the
     * given side: positive when it increases the account, negative when it
     * reduces it.
     *
     * Debit and credit are columns, not directions — a debit increases Cash and
     * decreases Sales — so a running balance is meaningless until the account's
     * normal side is known. See {@see AccountType::normalBalance()}.
     */
    public function signedAgainst(BalanceSide $normal): Money
    {
        return $normal === BalanceSide::Debit
            ? $this->debitMoney()->minus($this->creditMoney())
            : $this->creditMoney()->minus($this->debitMoney());
    }

    /* ---------------------------------------------------------------------
     | Scopes
     |-------------------------------------------------------------------- */

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where('account_id', $accountId);
    }

    /**
     * @param  Builder<self>  $query
     * @param  array<int, int>  $accountIds
     * @return Builder<self>
     */
    public function scopeForAccounts(Builder $query, array $accountIds): Builder
    {
        // An empty list means "no accounts", not "all accounts" — a party with
        // no roles has no control account, and the alternative reading would
        // hand them the entire ledger.
        return $accountIds === []
            ? $query->whereRaw('1 = 0')
            : $query->whereIn('account_id', $accountIds);
    }

    /**
     * The entries belonging to one counterparty.
     *
     * A party is a property of the *transaction*, not of the line, so this
     * reaches through the relation. whereHas rather than a join, because
     * Transaction carries the tenant scope too and a subquery inherits it —
     * a join would have to restate the tenant filter by hand.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForParty(Builder $query, int $partyId): Builder
    {
        return $query->whereHas('transaction', fn ($transaction) => $transaction->where('party_id', $partyId));
    }

    /**
     * Entries dated on or before a day — the "as at" of any balance.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeUpTo(Builder $query, DateTimeInterface|string $date): Builder
    {
        return $query->whereDate('date', '<=', $date);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeFrom(Builder $query, DateTimeInterface|string $date): Builder
    {
        return $query->whereDate('date', '>=', $date);
    }

    /**
     * Ledger order: by day, then by the order the lines were written.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeInLedgerOrder(Builder $query): Builder
    {
        return $query->orderBy('date')->orderBy('id');
    }
}
