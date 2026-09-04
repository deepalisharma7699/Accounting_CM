<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One row of the Brand Master — whose a thing is.
 *
 * The record that replaced the free-text `items.brand` column. A brand holds no
 * stock, has no price and is never sold; it is an identity a product points at,
 * so that "Crompton" is one word the shop keeps rather than one somebody spells
 * afresh on every product.
 *
 * ## What it deliberately does not carry
 *
 * No default unit, no default HSN, no default GST rate. {@see ItemCategory} is a
 * *template* and copies those onto a new product; a brand has no opinion about
 * how the thing it makes is taxed or counted, and one that did would be a second
 * place a rate came from.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string|null $code
 * @property string|null $description
 * @property bool $is_active
 * @property int $display_order
 */
#[Fillable([
    'tenant_id', 'name', 'code', 'description', 'is_active', 'display_order',
])]
class ItemBrand extends Model
{
    use Auditable, BelongsToTenant;

    /**
     * `is_active` is the one that matters most in a trail. Archiving a brand
     * touches no product at all, and yet it disappears from every create form
     * afterwards — so without an entry here the history would show nobody
     * changing anything while the catalogue quietly changed shape.
     *
     * @return array<int, string>
     */
    public function auditAttributes(): array
    {
        return ['name', 'code', 'description', 'is_active', 'display_order'];
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
            'display_order' => 'integer',
        ];
    }

    /* ---------------------------------------------------------------------
     | Relations
     |-------------------------------------------------------------------- */

    /**
     * @return HasMany<Item, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(Item::class, 'brand_id');
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
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('display_order')->orderBy('name');
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }
}
