<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Support\Money;
use Database\Factories\ItemVariantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The specific thing that is bought and sold: this motor, at this rating, at this
 * speed.
 *
 * The trade does not deal in families. Nobody buys "a three-phase induction
 * motor" — they buy a 5 HP, 1440 RPM one, and that is what carries a price, a
 * stock level and a cost. M8 counts stock per variant; M9 prices a bill line from
 * one.
 *
 * **No quantity and no cost column here.** `qty_on_hand` and `avg_cost` are sums
 * over `stock_movement`, which is the whole reason that table exists.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $item_id
 * @property string|null $sku
 * @property string|null $barcode
 * @property string|null $label
 * @property array<string, string>|null $attributes
 * @property string|null $sell_price
 * @property string|null $markup_percent
 * @property string|null $reorder_level
 * @property string|null $min_stock
 * @property bool $is_draft
 * @property bool $is_active
 */
#[Fillable([
    'tenant_id', 'item_id', 'sku', 'barcode', 'label', 'attributes',
    'sell_price', 'markup_percent', 'reorder_level', 'min_stock', 'is_draft', 'is_active',
])]
class ItemVariant extends Model
{
    /** @use HasFactory<ItemVariantFactory> */
    use Auditable, BelongsToTenant, HasFactory;

    /**
     * `attributes` is the specification — the HP, the phase, the gauge — and it
     * is what makes a variant identifiable at all. Editing it does not restate
     * any stock already posted against the variant, so a shelf can quietly come
     * to be labelled something it is not.
     *
     * `sell_price` is here and `markup_percent` beside it. Neither is a cost:
     * the cost is M8's weighted average at the moment of sale, derived from the
     * movements, and nothing on this model has ever been able to change it.
     *
     * @return array<int, string>
     */
    public function auditAttributes(): array
    {
        return [
            'sku', 'barcode', 'label', 'attributes', 'sell_price',
            'markup_percent', 'reorder_level', 'min_stock', 'is_draft', 'is_active',
        ];
    }

    /**
     * Named with its family, because "5 HP / 1440" is uninterpretable on its own
     * — the same reason a variant cannot be deleted apart from its item.
     */
    public function auditLabel(): string
    {
        $family = $this->relationLoaded('item') ? $this->item?->name : null;

        return trim(($family === null ? '' : $family.' · ').($this->label ?: $this->sku ?: ''), ' ·');
    }

    /**
     * Note `attributes`: that is also Eloquent's own name for a model's column
     * data, so inside this class `$this->attributes` is the raw bag and not the
     * JSON column. Every read of the column goes through {@see attributeBag()},
     * which asks for it by name.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attributes' => 'array',
            'sell_price' => 'decimal:2',
            'markup_percent' => 'decimal:2',
            'reorder_level' => 'decimal:3',
            'min_stock' => 'decimal:3',
            'is_draft' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /* ---------------------------------------------------------------------
     | Relations
     |-------------------------------------------------------------------- */

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /* ---------------------------------------------------------------------
     | Attributes
     |-------------------------------------------------------------------- */

    /**
     * The variant's own attribute bag.
     *
     * Named away from `getAttributes()` deliberately: that is Eloquent's method for
     * a model's columns, and overriding it to mean something else would break
     * every part of the framework that relies on it.
     *
     * @return array<string, string>
     */
    public function attributeBag(): array
    {
        $bag = $this->getAttributeValue('attributes');

        return is_array($bag) ? $bag : [];
    }

    public function attribute(string $key): ?string
    {
        $value = $this->attributeBag()[$key] ?? null;

        return $value === null ? null : (string) $value;
    }

    /**
     * What to call this variant on a picker, a bill line or a stock report.
     *
     * The stored label wins where the workshop set one, because a fitter asking
     * for "the small Crompton" should find it under that name. Otherwise it is
     * built from the attributes in schema order, which is the order somebody
     * reciting a motor's specification uses: "5 HP / 3 ph / 1440 RPM".
     *
     * Falls back to the item's name for a service, which has nothing to vary.
     */
    public function displayLabel(): string
    {
        if (filled($this->label)) {
            return $this->label;
        }

        $derived = $this->derivedLabel();

        return $derived ?? ($this->item?->name ?? 'Variant #'.$this->id);
    }

    /**
     * The label the attributes imply, or null when there are none.
     *
     * Schema order rather than insertion order, so two variants described in
     * different sequences read identically — the same reason a party's roles are
     * stored in enum order.
     */
    public function derivedLabel(): ?string
    {
        $category = $this->item?->category;
        $bag = $this->attributeBag();

        if ($bag === []) {
            return null;
        }

        // No category to order by — an item filed under nothing, which is only
        // reachable between the migration that added the column and the backfill
        // that fills it. The bag's own order is worse than the schema's and much
        // better than showing the customer nothing.
        if ($category === null) {
            return implode(' / ', array_map('strval', array_values($bag)));
        }

        $parts = [];

        foreach ($category->resolvedAttributes() as $attribute) {
            $value = $bag[$attribute->key] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            $suffix = $attribute->suffix();

            $parts[] = $suffix === null
                ? (string) $value
                : sprintf('%s %s', $value, $suffix);
        }

        return $parts === [] ? null : implode(' / ', $parts);
    }

    /* ---------------------------------------------------------------------
     | Price
     |-------------------------------------------------------------------- */

    public function sellPriceMoney(): ?Money
    {
        return Money::ofNullable($this->sell_price);
    }

    /**
     * The price the workshop's target markup implies, given a cost.
     *
     * Takes the cost as an argument rather than reading it, because there is
     * nothing here to read: cost is M8's weighted average at the moment of sale,
     * and a price computed from a stored one would be stale the next time stock
     * arrived. This is a suggestion for a form, never a figure on a bill.
     */
    public function suggestedPriceFrom(Money $cost): ?Money
    {
        if ($this->markup_percent === null) {
            return null;
        }

        // Integer paise throughout: the markup is applied to whole paise and
        // rounded once, at the end, rather than passing through a float.
        $basis = $cost->minor();
        $markup = (int) round($basis * (float) $this->markup_percent / 100);

        return Money::fromMinor($basis + $markup);
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
    public function scopeDrafts(Builder $query): Builder
    {
        return $query->where('is_draft', true);
    }

    /**
     * Variants of items that are actually inventoried — what M8 computes a
     * position for.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeStocked(Builder $query): Builder
    {
        return $query->whereHas('item', fn ($item) => $item->stocked());
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }

    /**
     * Whether this variant's category describes it with attributes at all.
     *
     * False for a category nobody has given fields to — labour, most obviously,
     * where an hour of rewinding is an hour of rewinding and a bag of attributes
     * would only ever be filled in wrong. The form uses this to decide whether to
     * draw a specification section or say there is nothing to describe.
     */
    public function acceptsAttributes(): bool
    {
        $category = $this->item?->category;

        return $category !== null && $category->resolvedAttributes()->isNotEmpty();
    }
}
