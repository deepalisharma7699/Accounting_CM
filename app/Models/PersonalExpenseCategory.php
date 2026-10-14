<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What a personal expense went on — groceries, school fees, fuel.
 *
 * Data, not code, for the reason the catalogue's vocabulary is: every family
 * spends on different things, and a list written into a template is a list
 * nobody can add "Pet care" to. A workshop starts with
 * {@see \App\Services\Personal\PersonalCategoryDefaults} and edits from there.
 *
 * Deliberately **not** `Auditable`. The History card is read by whoever holds
 * READ:AUDIT, and the point of this diary is that it is the owner's alone.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property bool $is_active
 * @property int $display_order
 */
#[Fillable(['tenant_id', 'name', 'is_active', 'display_order'])]
class PersonalExpenseCategory extends Model
{
    use BelongsToTenant;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    /**
     * @return HasMany<PersonalExpense, $this>
     */
    public function expenses(): HasMany
    {
        return $this->hasMany(PersonalExpense::class, 'category_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
