<?php

namespace App\Models;

use App\Enums\PaymentMode;
use App\Models\Concerns\BelongsToTenant;
use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One settlement line: this much money, through this mode, against this
 * reference.
 *
 * A record of *how* a payment or receipt moved, not of the money itself — the
 * money is in `journal_entries` and nothing here is ever summed into a report.
 * What this table adds is what the ledger structurally cannot say: that the
 * ₹5,000 on the Bank account was a cheque rather than a transfer, and that the
 * cheque was numbered 402317.
 *
 * Immutable for the same reason a journal entry is. These rows exist only for a
 * posted transaction, they are written inside the same database transaction as
 * its entries, and a posted transaction is never edited. A draft's intended
 * split lives in `transactions.draft_payments` instead, so this table contains
 * nothing unauthorised.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $transaction_id
 * @property PaymentMode $mode
 * @property string $amount
 * @property string|null $reference
 * @property int $line_no
 */
#[Fillable([
    'tenant_id', 'transaction_id', 'mode', 'amount', 'reference', 'line_no',
])]
class TransactionPayment extends Model
{
    use BelongsToTenant;

    /**
     * Written once, alongside the entries it describes, and never touched again.
     */
    public const UPDATED_AT = null;

    /**
     * Deliberately no HasFactory, for the same reason JournalEntry has none: a
     * settlement row invented by a factory describes a movement that never
     * happened. Post through the engine, which is also the only way to get one
     * in the application.
     */

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mode' => PaymentMode::class,
            'amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function amountMoney(): Money
    {
        return Money::of($this->amount);
    }
}
