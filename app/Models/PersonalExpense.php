<?php

namespace App\Models;

use App\Enums\PersonalPaymentMethod;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry in the owner's personal diary — money out of their own pocket.
 *
 * ## Why this is not a transaction
 *
 * Because the workshop's money never moved. A transaction is a ledger event,
 * and posting this would either put a family's groceries in the shop's P&L —
 * understating the profit that tax is paid on — or take cash out of a till it
 * never left. So it is a row of its own, edited and deleted freely like any
 * diary, and nothing in the books reads this table: not the ledger, not a
 * report, not Insights, not the day book.
 *
 * One diary per workshop, shared by everybody who holds PERSONAL_EXPENSES —
 * the owner decided that two owners keep one book. `created_by` says who wrote
 * each line.
 *
 * Deliberately **not** `Auditable`, for the reason the category is not.
 *
 * @property int $id
 * @property int $tenant_id
 * @property \Illuminate\Support\Carbon $date
 * @property int $category_id
 * @property string $amount
 * @property PersonalPaymentMethod $paid_by
 * @property string|null $notes
 * @property int|null $created_by
 */
#[Fillable(['tenant_id', 'date', 'category_id', 'amount', 'paid_by', 'notes', 'created_by'])]
class PersonalExpense extends Model
{
    use BelongsToTenant;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'amount' => 'decimal:2',
            'paid_by' => PersonalPaymentMethod::class,
        ];
    }

    /**
     * @return BelongsTo<PersonalExpenseCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(PersonalExpenseCategory::class, 'category_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
