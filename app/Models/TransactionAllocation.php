<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * How much of one receipt went against one invoice — M16.
 *
 * The row that lets "INV/26-27/1012 — ₹35,000 — Partial" be computed. Without it
 * the ledger knows what a customer owes in total and nothing about which of
 * their bills the shortfall is on.
 *
 * ## What this is, and is not
 *
 * It is a fact about the *documents*, not about the money. The money already
 * moved: the receipt credited Sundry Debtors and the ledger is the authority on
 * that, unchanged. What the ledger structurally cannot hold is the operator's
 * decision that this ₹5,000 was meant for the March invoice rather than the
 * April one — the same kind of fact a cheque number is, and stored for the same
 * reason.
 *
 * Nothing derived from it is stored. A bill's paid amount is summed over these
 * rows on every read; see {@see \App\Services\Accounting\BillService::settlementFor()}.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $settlement_transaction_id
 * @property int $bill_transaction_id
 * @property string $amount
 * @property Carbon|null $created_at
 */
#[Fillable(['tenant_id', 'settlement_transaction_id', 'bill_transaction_id', 'amount'])]
class TransactionAllocation extends Model
{
    use BelongsToTenant;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    /**
     * The receipt or payment doing the settling.
     *
     * @return BelongsTo<Transaction, $this>
     */
    public function settlement(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'settlement_transaction_id');
    }

    /**
     * The sale or purchase being settled.
     *
     * @return BelongsTo<Transaction, $this>
     */
    public function bill(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'bill_transaction_id');
    }

    public function amountMoney(): Money
    {
        return Money::of($this->amount);
    }
}
