<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person credited with one trade's share of one invoice — M22.
 *
 * "Ramesh fitted INV/26-27/118, Sunil wound it." The row is a label on work, not
 * a figure: nothing about it reaches the ledger, the stock ledger or the
 * customer's copy of the invoice.
 *
 * ## Why it is audited when the transaction it hangs off is not
 *
 * A transaction is not `Auditable`, and does not need to be — it is immutable
 * once posted, so the posting *is* the record. This row is the opposite: it may
 * be corrected for as long as the invoice exists, precisely because correcting
 * it moves no number. See the create migration for why write-once would be the
 * wrong rule here, and `REVISION_WOULD_RESTATE_COST` for why "just reverse and
 * reissue it" is not available on a sale.
 *
 * So the trail is the whole safeguard. Without it, "the report says Ramesh did
 * forty jobs last month" would be unanswerable the moment anybody doubted it.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $transaction_id
 * @property int $employee_id
 * @property int $designation_id
 */
#[Fillable(['tenant_id', 'transaction_id', 'employee_id', 'designation_id'])]
class TransactionStaff extends Model
{
    use Auditable, BelongsToTenant;

    protected $table = 'transaction_staff';

    /**
     * Both pointers, and neither is decoration.
     *
     * `employee_id` is the correction this row exists to allow, so it is the one
     * a reader will come looking for. `designation_id` is on the list for a
     * subtler case: moving a row from Fitter to Winder re-attributes work
     * between two trades without either employee changing, and a trail that
     * recorded only the person would show nothing at all.
     *
     * @return array<int, string>
     */
    public function auditAttributes(): array
    {
        return ['employee_id', 'designation_id'];
    }

    /**
     * What the trail calls it — "INV/26-27/118 · Winder".
     *
     * The document number rather than the row id, because the row id addresses
     * nothing a person can open. Copied onto the audit row at the moment of the
     * change, like every other label, so the history still reads correctly
     * afterwards.
     */
    public function auditLabel(): string
    {
        $document = $this->transaction?->doc_no ?? "#{$this->transaction_id}";

        return $this->designation === null
            ? $document
            : "{$document} · {$this->designation->name}";
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
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<StaffDesignation, $this>
     */
    public function designation(): BelongsTo
    {
        return $this->belongsTo(StaffDesignation::class, 'designation_id');
    }
}
