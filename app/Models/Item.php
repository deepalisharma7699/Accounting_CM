<?php

namespace App\Models;

use App\Enums\ItemType;
use App\Enums\UnitOfMeasure;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\ItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A family of things the workshop deals in: "3-phase induction motor", "copper
 * winding wire", "rewinding labour".
 *
 * **There is no quantity and no cost here, by design.** Stock on hand and
 * weighted average cost are sums over M8's `stock_movement`, for the same reason a
 * party's outstanding is a sum over `journal_entries`: a stored aggregate agrees
 * with its movements right up until one is written without the other.
 *
 * The family carries what the tax authority and the accountant care about — the
 * HSN code, the GST rate, the unit. {@see ItemVariant} carries what the customer
 * asks for. Splitting them that way is what lets one HSN code and one rate cover
 * forty motor ratings without being repeated forty times, which is how two of them
 * eventually end up disagreeing.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string|null $code
 * @property ItemType $type
 * @property string|null $hsn_sac
 * @property string $gst_rate
 * @property UnitOfMeasure $base_uom
 * @property bool $is_stock
 * @property bool $is_draft
 * @property string|null $description
 * @property bool $is_active
 */
#[Fillable([
    'tenant_id', 'name', 'code', 'type', 'hsn_sac', 'gst_rate',
    'base_uom', 'is_stock', 'is_draft', 'description', 'is_active',
])]
class Item extends Model
{
    /** @use HasFactory<ItemFactory> */
    use Auditable, BelongsToTenant, HasFactory;

    /**
     * `type` and `base_uom` are listed even though neither is editable, so they
     * cannot produce an edit entry. They are here for the deletion snapshot: an
     * item removed from the catalogue leaves nothing behind, and "copper wire,
     * bulk material, kilograms" is what makes the row mean anything afterwards.
     *
     * `gst_rate` is the one to watch. It is copied onto every bill line at the
     * moment of posting, so changing it does not restate a single existing
     * invoice — which is correct, and is also exactly why a reader comparing two
     * quarters needs to know it moved.
     *
     * @return array<int, string>
     */
    public function auditAttributes(): array
    {
        return [
            'name', 'code', 'type', 'hsn_sac', 'gst_rate',
            'base_uom', 'is_stock', 'is_draft', 'description', 'is_active',
        ];
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
            'type' => ItemType::class,
            'base_uom' => UnitOfMeasure::class,
            'gst_rate' => 'decimal:2',
            'is_stock' => 'boolean',
            'is_draft' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /* ---------------------------------------------------------------------
     | Relations
     |-------------------------------------------------------------------- */

    /**
     * @return HasMany<ItemVariant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(ItemVariant::class)->orderBy('label');
    }

    /* ---------------------------------------------------------------------
     | Type
     |-------------------------------------------------------------------- */

    /**
     * Whether this item is actually inventoried.
     *
     * Two conditions, not one: the type has to be *capable* of holding stock, and
     * the workshop has to have said it does. A service fails the first outright;
     * a part bought to order may fail the second. Read through this rather than
     * off the column, so nothing has to remember the pairing.
     */
    public function tracksStock(): bool
    {
        return $this->is_stock && $this->type->canHoldStock();
    }

    public function isService(): bool
    {
        return $this->type === ItemType::Service;
    }

    /**
     * The label the HSN/SAC field should carry for this item — they are the same
     * column and the same position on a GST invoice, but not the same word.
     */
    public function taxCodeLabel(): string
    {
        return $this->type->usesSacCode() ? 'SAC' : 'HSN';
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
     * Everything a person still has to look at — M11's imports and M15's captured
     * items. A worklist, never a filter on the books: a draft item is a real item
     * that stock may already have been posted against.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeDrafts(Builder $query): Builder
    {
        return $query->where('is_draft', true);
    }

    /**
     * Everything M8 has to compute a stock position for.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeStocked(Builder $query): Builder
    {
        return $query->where('is_stock', true)
            ->whereIn('type', array_map(
                static fn (ItemType $type) => $type->value,
                array_filter(ItemType::cases(), static fn (ItemType $type) => $type->canHoldStock()),
            ));
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }
}
