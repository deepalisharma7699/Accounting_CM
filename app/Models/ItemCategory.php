<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * A category: the template that says what to record about a kind of thing.
 *
 * The record that replaced the `ItemType` enum. A category holds no stock, has no
 * price and is never sold — {@see Item} is the product and {@see ItemVariant} is
 * the specific thing on the shelf. What a category owns is the *question set*:
 * ask a motor for its HP, phase and speed; ask a bearing for three diameters.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $parent_id
 * @property string $name
 * @property string|null $code
 * @property string|null $description
 * @property bool $holds_stock
 * @property bool $uses_sac_code
 * @property string|null $default_unit_code
 * @property string|null $default_hsn_sac
 * @property string|null $default_gst_rate
 * @property bool $is_system
 * @property bool $is_active
 * @property int $display_order
 */
#[Fillable([
    'tenant_id', 'parent_id', 'name', 'code', 'description',
    'holds_stock', 'uses_sac_code', 'default_unit_code', 'default_hsn_sac',
    'default_gst_rate', 'is_system', 'is_active', 'display_order',
])]
class ItemCategory extends Model
{
    use Auditable, BelongsToTenant;

    /**
     * `holds_stock` is the one that matters most in a trail. Turning it off does
     * not touch a single product, and yet every product created afterwards is a
     * non-stock one — so without an entry here the history would show nobody
     * changing anything while the catalogue quietly changed shape.
     *
     * @return array<int, string>
     */
    public function auditAttributes(): array
    {
        return [
            'parent_id', 'name', 'code', 'description', 'holds_stock', 'uses_sac_code',
            'default_unit_code', 'default_hsn_sac', 'default_gst_rate', 'is_active', 'display_order',
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
            'holds_stock' => 'boolean',
            'uses_sac_code' => 'boolean',
            'is_system' => 'boolean',
            'is_active' => 'boolean',
            'display_order' => 'integer',
            'default_gst_rate' => 'decimal:2',
        ];
    }

    /* ---------------------------------------------------------------------
     | Relations
     |-------------------------------------------------------------------- */

    /**
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('display_order')->orderBy('name');
    }

    /**
     * This category's *own* fields, not counting anything inherited from a
     * parent. The universal form wants the resolved set — see
     * {@see resolvedAttributes()}, which walks the chain.
     *
     * ## Why this is `fields()` and not `attributes()`
     *
     * Because `$category->attributes` is Eloquent's own raw column bag, on every
     * model in the framework. A relation of that name loads perfectly well
     * through `with()` and then silently returns the column array on property
     * access — so the schema resolved to nothing and every category looked as
     * though it had no fields at all. The same trap {@see ItemVariant} sidesteps
     * with `attributeBag()`.
     *
     * @return HasMany<ItemAttribute, $this>
     */
    public function fields(): HasMany
    {
        return $this->hasMany(ItemAttribute::class, 'category_id')
            ->orderBy('display_order')
            ->orderBy('id');
    }

    /**
     * @return HasMany<Item, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(Item::class, 'category_id');
    }

    /* ---------------------------------------------------------------------
     | The resolved question set
     |-------------------------------------------------------------------- */

    /**
     * Every field a product of this category is asked for, inherited fields
     * included, in the order a form should draw them.
     *
     * Walks up `parent_id` and lays the ancestors down first, so "Submersible
     * Motor" under "Motor" asks for HP, phase and speed — its parent's, in its
     * parent's order — and then adds Head and Flow Rate. A subcategory adds to
     * the question set rather than restating it, which is the only arrangement
     * where correcting the parent corrects every child.
     *
     * A child may not reuse an ancestor's key; `ItemAttributeService` refuses it
     * on the way in, because two definitions of one JSON field would leave this
     * method picking one and the other silently ignored. If one ever gets through
     * — a hand-written INSERT — the *nearest* definition wins, which is the least
     * surprising of the available wrong answers.
     *
     * @return Collection<int, ItemAttribute>
     */
    public function resolvedAttributes(bool $activeOnly = true): Collection
    {
        $chain = [];
        $category = $this;
        $guard = 0;

        // Ancestors, nearest first, then reversed — so the root's fields come
        // out at the top of the form. The guard is not paranoia: `parent_id`
        // cannot be constrained against a cycle in MySQL (see the migration), so
        // a bad row must not be able to spin here forever.
        while ($category !== null && $guard++ < 10) {
            $chain[] = $category;
            $category = $category->parent;
        }

        $fields = [];

        foreach (array_reverse($chain) as $ancestor) {
            foreach ($ancestor->fields as $attribute) {
                if ($activeOnly && ! $attribute->is_active) {
                    continue;
                }

                // Keyed, so a nearer definition of the same key replaces the one
                // above it rather than appearing twice on the form.
                $fields[$attribute->key] = $attribute;
            }
        }

        return new Collection(array_values($fields));
    }

    /**
     * The keys a product of this category cannot exist without.
     *
     * @return array<int, string>
     */
    public function requiredAttributeKeys(): array
    {
        return $this->resolvedAttributes()
            ->filter(fn (ItemAttribute $attribute) => $attribute->is_required)
            ->map(fn (ItemAttribute $attribute) => $attribute->key)
            ->values()
            ->all();
    }

    /**
     * The question set in the shape the universal form reads — and the shape
     * `ItemType::attributeSchema()` used to return, key for key.
     *
     * That compatibility is deliberate and is why the front end did not have to
     * be rewritten: it already built its inputs from the server's answer, so
     * changing where the answer comes from changed nothing it could see.
     *
     * @return array<string, array<string, mixed>>
     */
    public function attributeSchema(): array
    {
        $schema = [];

        foreach ($this->resolvedAttributes() as $attribute) {
            $schema[$attribute->key] = $attribute->toSchemaField();
        }

        return $schema;
    }

    /* ---------------------------------------------------------------------
     | Behaviour
     |-------------------------------------------------------------------- */

    /**
     * The label the HSN/SAC field carries for products of this category. The same
     * column either way; not the same word on a GST invoice.
     */
    public function taxCodeLabel(): string
    {
        return $this->uses_sac_code ? 'SAC' : 'HSN';
    }

    /**
     * Whether this is one of the four seeded rows that were `ItemType`.
     *
     * They may be renamed, re-described and switched off; they may not be
     * deleted, because products and posted documents already refer to what they
     * mean.
     */
    public function isProtected(): bool
    {
        return (bool) $this->is_system;
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
    public function scopeRoots(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
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
