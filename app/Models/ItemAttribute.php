<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One field a category asks about the things filed under it.
 *
 * The row that replaced an entry in `ItemType::attributeSchema()`. An admin adds
 * one and the universal create form grows a field — no migration, no API, no
 * component, no deployment. That is the whole point of the module.
 *
 * The *values* go where they always went: the `item_variants.attributes` JSON
 * bag, keyed by `key`. Which is why the key is write-once — renaming it would
 * not rename it inside a thousand bags, it would orphan every one of them.
 *
 * Everything this class used to spell out — the casts, the unit, the fixed set,
 * the bounds and the shape a form reads a field in — moved to
 * {@see AttributeDefinition} when the bench got its own question sets, because
 * `job_kind_attributes` is the same shape and the two must not drift. What is
 * left here is the half that is genuinely about a *product*: the table, and
 * which category a field belongs to.
 *
 * @property int $category_id
 */
#[Fillable([
    'tenant_id', 'category_id', 'key', 'label', 'data_type', 'unit_code',
    'is_required', 'default_value', 'options', 'min_value', 'max_value',
    'help_text', 'display_order', 'is_active',
])]
class ItemAttribute extends AttributeDefinition
{
    public function ownerKey(): string
    {
        return 'category_id';
    }

    /**
     * @return BelongsTo<ItemCategory, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->category();
    }

    /**
     * @return BelongsTo<ItemCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ItemCategory::class, 'category_id');
    }
}
