<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One row of the Designation Master — what somebody is called — M22.
 *
 * The staff module's equivalent of {@see ItemBrand}, and it exists for the same
 * reason: a designation typed onto each employee is a master list nobody
 * maintains, and within a month there are three spellings of "Helper", a filter
 * offering all three, and no single one of them that counts the trade.
 *
 * ## What it deliberately does not carry
 *
 * **No default pay rate, and no default basis.** It is tempting — every Helper in
 * a workshop is on roughly the same money — and it would be a second place a
 * wage came from. What somebody is paid is an agreement with that person, and a
 * default that quietly filled itself in would be wrong for the one employee
 * whose arrangement is different, silently, on a form nobody re-reads.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property bool $is_active
 * @property bool $track_on_sales
 */
#[Fillable(['tenant_id', 'name', 'is_active', 'track_on_sales'])]
class StaffDesignation extends Model
{
    use Auditable, BelongsToTenant;

    /**
     * All three, and `is_active` is the one that matters most: archiving a
     * designation touches no employee at all, and yet it disappears from every
     * form afterwards — so without an entry here the trail would show nobody
     * changing anything while the vocabulary quietly changed shape.
     *
     * `track_on_sales` earns its place for a related reason: switching it off
     * takes a box off the sale form, and every invoice written afterwards is
     * silently attributed to nobody. "When did we stop recording who wound
     * these" is otherwise a question with no answer anywhere.
     *
     * @return array<int, string>
     */
    public function auditAttributes(): array
    {
        return ['name', 'is_active', 'track_on_sales'];
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'track_on_sales' => 'boolean',
        ];
    }

    /* ---------------------------------------------------------------------
     | Relations
     |-------------------------------------------------------------------- */

    /**
     * @return HasMany<Employee, $this>
     */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'designation_id');
    }

    /* ---------------------------------------------------------------------
     | Scopes
     |-------------------------------------------------------------------- */

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * The trades a sale asks about by name — M22.
     *
     * Always narrowed by {@see scopeActive} at the call site rather than here: an
     * archived designation is gone from every form, and one that was still
     * painting a picker on the invoice screen would be the archive doing nothing.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeTrackedOnSales(Builder $query): Builder
    {
        return $query->where('track_on_sales', true);
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }
}
