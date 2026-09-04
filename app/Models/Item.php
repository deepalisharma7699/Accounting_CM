<?php

namespace App\Models;

use App\Casts\UnitCast;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Support\Units\UnitDefinition;
use Database\Factories\ItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
 * ## What decides how an item behaves
 *
 * {@see ItemCategory}, and it used to be the `ItemType` enum. The three things the
 * enum decided — whether stock is possible, whether the tax code is an HSN or a
 * SAC, and which attributes describe a variant — are now columns and rows an admin
 * can edit, which is what lets a shop file a bearing, a capacitor or a shirt
 * without a developer. See the category migration.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string|null $code
 * @property int|null $category_id
 * @property int|null $brand_id
 * @property string|null $hsn_sac
 * @property string $gst_rate
 * @property UnitDefinition $base_uom
 * @property bool $is_stock
 * @property bool $is_draft
 * @property string|null $description
 * @property string|null $image_path
 * @property bool $is_active
 */
#[Fillable([
    'tenant_id', 'name', 'code', 'category_id', 'brand_id', 'hsn_sac', 'gst_rate',
    'base_uom', 'is_stock', 'is_draft', 'description', 'image_path', 'is_active',
])]
class Item extends Model
{
    /** @use HasFactory<ItemFactory> */
    use Auditable, BelongsToTenant, HasFactory;

    /**
     * `category_id` and `base_uom` are listed even though neither is editable, so
     * they cannot produce an edit entry. They are here for the deletion snapshot:
     * an item removed from the catalogue leaves nothing behind, and "copper wire,
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
            'name', 'code', 'category_id', 'brand_id', 'hsn_sac', 'gst_rate',
            'base_uom', 'is_stock', 'is_draft', 'description', 'image_path', 'is_active',
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
            // A value object rather than an enum since the Unit Master replaced
            // `UnitOfMeasure`. The surface is identical — ->value, ->label(),
            // ->symbol(), ->isFractional(), ->quantityScale() — which is why no
            // call site reading a unit had to change. See UnitCast.
            'base_uom' => UnitCast::class,
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

    /**
     * The template this item is described by.
     *
     * Eager-loaded almost everywhere, and it has to be: {@see tracksStock()} is
     * asked on every row of every listing and before every stock movement, and a
     * lazy load there is a query per item. The repositories load it with the page.
     *
     * @return BelongsTo<ItemCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ItemCategory::class, 'category_id');
    }

    /**
     * Whose this product is — a row of the Brand Master rather than a typed
     * string, since a string is a master list nobody maintains: "Crompton",
     * "crompton" and "Crompton Greaves" are three brands to a column and one to
     * the shop.
     *
     * Eager-loaded with the listing for the same reason the category is: the
     * catalogue shows it on every row, and resolving it lazily is a query per
     * item.
     *
     * @return BelongsTo<ItemBrand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(ItemBrand::class, 'brand_id');
    }

    /* ---------------------------------------------------------------------
     | Category
     |-------------------------------------------------------------------- */

    /**
     * Whether this item is actually inventoried.
     *
     * Two conditions, not one: the category has to be *capable* of holding stock,
     * and the workshop has to have said this item does. Labour fails the first
     * outright; a part bought to order may fail the second. Read through this
     * rather than off the column, so nothing has to remember the pairing.
     *
     * A missing category falls back to the item's own flag rather than to false.
     * The column is nullable only between the migration that added it and the
     * backfill that fills it, and a catalogue that silently stopped tracking
     * stock in that window would be a far worse failure than one that kept
     * trusting the flag it already had.
     */
    public function tracksStock(): bool
    {
        if (! $this->is_stock) {
            return false;
        }

        return $this->category?->holds_stock ?? true;
    }

    /**
     * Whether this item is labour rather than goods.
     *
     * Asked of the *category*, which is the only thing that knows: a workshop may
     * have one service category or five, called whatever it calls them, and the
     * distinction that actually matters downstream is that labour holds no stock
     * and is billed under a SAC code.
     */
    public function isService(): bool
    {
        return $this->category !== null && ! $this->category->holds_stock;
    }

    /**
     * What this item's category is called — the word a listing, a picker and an
     * error message use. Never null: an unfiled item says so rather than showing
     * a blank column.
     */
    public function categoryLabel(): string
    {
        return $this->category?->name ?? 'Uncategorised';
    }

    /**
     * What this product's brand is called, or null where it has none.
     *
     * Null rather than a word like "Unbranded": an unbranded bush is a real
     * thing, and printing a placeholder where the shop deliberately said nothing
     * would be inventing a make for it.
     */
    public function brandLabel(): ?string
    {
        return $this->brand?->name;
    }

    /**
     * The label the HSN/SAC field should carry for this item — they are the same
     * column and the same position on a GST invoice, but not the same word.
     */
    public function taxCodeLabel(): string
    {
        return ($this->category?->uses_sac_code ?? false) ? 'SAC' : 'HSN';
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
     * The capability now lives on the category, so this is a join rather than a
     * list of enum values — and it stays correct the day an admin adds a category
     * that holds no stock, which the enum version could not.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeStocked(Builder $query): Builder
    {
        return $query->where('is_stock', true)
            ->whereHas('category', fn (Builder $category) => $category->where('holds_stock', true));
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }
}
