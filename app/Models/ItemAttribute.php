<?php

namespace App\Models;

use App\Enums\AttributeType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Support\Units\UnitDefinition;
use App\Support\Units\UnitRegistry;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One field a category asks about the things filed under it.
 *
 * The row that replaced an entry in `ItemType::attributeSchema()`. An admin adds
 * one and the universal create form grows a field — no migration, no API, no
 * component, no deployment. That is the whole point of the module.
 *
 * The *values* go where they always went: the `item_variants.attributes` JSON
 * bag, keyed by {@see $key}. Which is why the key is write-once — renaming it
 * would not rename it inside a thousand bags, it would orphan every one of them.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $category_id
 * @property string $key
 * @property string $label
 * @property AttributeType $data_type
 * @property string|null $unit_code
 * @property bool $is_required
 * @property string|null $default_value
 * @property array<int, string>|null $options
 * @property string|null $min_value
 * @property string|null $max_value
 * @property string|null $help_text
 * @property int $display_order
 * @property bool $is_active
 */
#[Fillable([
    'tenant_id', 'category_id', 'key', 'label', 'data_type', 'unit_code',
    'is_required', 'default_value', 'options', 'min_value', 'max_value',
    'help_text', 'display_order', 'is_active',
])]
class ItemAttribute extends Model
{
    use Auditable, BelongsToTenant;

    /**
     * `key` is listed although it is never editable, for the same reason a unit's
     * code is: it is what makes a deletion snapshot readable. "Rating" tells a
     * reader nothing about which JSON field stopped being asked for.
     *
     * `is_required` and `is_active` are the two that change records without
     * touching one — a field switched off stops being collected, and every
     * product created afterwards is missing it.
     *
     * @return array<int, string>
     */
    public function auditAttributes(): array
    {
        return [
            'key', 'label', 'data_type', 'unit_code', 'is_required', 'default_value',
            'options', 'min_value', 'max_value', 'help_text', 'display_order', 'is_active',
        ];
    }

    public function auditLabel(): string
    {
        return $this->label;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'data_type' => AttributeType::class,
            'options' => 'array',
            'is_required' => 'boolean',
            'is_active' => 'boolean',
            'display_order' => 'integer',
            'min_value' => 'decimal:3',
            'max_value' => 'decimal:3',
        ];
    }

    /**
     * @return BelongsTo<ItemCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ItemCategory::class, 'category_id');
    }

    /**
     * The unit printed after the input, where the field has one.
     *
     * Resolved through the registry rather than a relation, for the reason
     * `$item->base_uom` is: this is read once per field per form render, and a
     * join for a symbol would be a query to print two characters.
     */
    public function unit(): ?UnitDefinition
    {
        if ($this->unit_code === null) {
            return null;
        }

        return app(UnitRegistry::class)->get($this->unit_code);
    }

    /**
     * The suffix a form prints beside the box — 'HP', 'RPM', 'mm'.
     */
    public function suffix(): ?string
    {
        return $this->unit()?->symbol();
    }

    /**
     * The fixed set, or an empty array where the type has none.
     *
     * Always an array, never null, so a caller never has to decide which of the
     * two "no options" means.
     *
     * @return array<int, string>
     */
    public function optionList(): array
    {
        if (! $this->data_type->hasOptions()) {
            return [];
        }

        return array_values(array_filter(
            $this->options ?? [],
            static fn ($option) => is_string($option) && trim($option) !== '',
        ));
    }

    /**
     * The shape the universal form is built from — and the shape
     * `ItemType::attributeSchema()` used to return.
     *
     * `label`, `required`, `values` and `suffix` keep the names the old schema
     * used, so the front end that already renders attribute inputs from the
     * server's answer did not have to be rewritten to read this instead. The rest
     * are additions the enum could not express.
     *
     * @return array<string, mixed>
     */
    public function toSchemaField(): array
    {
        $field = [
            'label' => $this->label,
            'required' => (bool) $this->is_required,
            'type' => $this->data_type->value,
            'attribute_id' => (int) $this->id,
            'category_id' => (int) $this->category_id,
        ];

        if ($this->suffix() !== null) {
            $field['suffix'] = $this->suffix();
            $field['unit_code'] = $this->unit_code;
        }

        // Named `values` rather than `options`, matching what the old schema
        // emitted and what the form already reads.
        if ($this->data_type->hasOptions()) {
            $field['values'] = $this->optionList();
        }

        if ($this->default_value !== null && $this->default_value !== '') {
            $field['default'] = $this->default_value;
        }

        if ($this->help_text !== null) {
            $field['help'] = $this->help_text;
        }

        if ($this->data_type->acceptsRange()) {
            if ($this->min_value !== null) {
                $field['min'] = (string) $this->min_value;
            }

            if ($this->max_value !== null) {
                $field['max'] = (string) $this->max_value;
            }
        }

        return $field;
    }

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
        return $query->orderBy('display_order')->orderBy('id');
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }
}
