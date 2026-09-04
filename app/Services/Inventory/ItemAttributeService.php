<?php

namespace App\Services\Inventory;

use App\Enums\AttributeType;
use App\Exceptions\Accounting\CatalogueMasterException;
use App\Exceptions\ResourceNotFoundException;
use App\Models\ItemAttribute;
use App\Models\ItemCategory;
use App\Repositories\Contracts\ItemAttributeRepositoryInterface;
use App\Repositories\Contracts\ItemCategoryRepositoryInterface;
use App\Support\Units\UnitRegistry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * The fields a category asks for — adding them, changing them, and refusing the
 * changes that would strand data already recorded.
 *
 * ## The one rule everything here follows
 *
 * **The values live in `item_variants.attributes` and nothing rewrites them.**
 * Every refusal below is a consequence of that single fact:
 *
 *   * the **key is write-once**, because renaming `hp` would not rename it inside
 *     a thousand bags — it would orphan every one of them and leave a required
 *     field looking unfilled;
 *   * a field **products have answered cannot be deleted**, only switched off,
 *     because the definition is the only thing that explains what the stored
 *     values mean;
 *   * a field **cannot be made compulsory** while products exist without it, or
 *     every one of them would be refused on its next edit by a rule nobody
 *     applied when they were created;
 *   * a dropdown's **options cannot be narrowed** below what products already
 *     hold, or they would be carrying a value the field says is impossible.
 *
 * Each of those is a thing an admin can reasonably want to do and a thing that
 * quietly breaks records if it is simply allowed. So each is refused with the
 * remedy in the message.
 */
class ItemAttributeService
{
    public function __construct(
        private readonly ItemAttributeRepositoryInterface $attributes,
        private readonly ItemCategoryRepositoryInterface $categories,
        private readonly UnitRegistry $units,
    ) {}

    /* ---------------------------------------------------------------------
     | Reading
     |-------------------------------------------------------------------- */

    /**
     * @return Collection<int, ItemAttribute>
     */
    public function forCategory(ItemCategory $category, bool $activeOnly = false): Collection
    {
        return $this->attributes->forCategory($category, $activeOnly);
    }

    public function find(int $id): ItemAttribute
    {
        return $this->attributes->findById($id)
            ?? throw new ResourceNotFoundException('Category field', $id);
    }

    public function findForCategory(ItemCategory $category, int $id): ItemAttribute
    {
        $attribute = $this->find($id);

        // The nesting in the URL has to mean something: without this,
        // PATCH /categories/7/attributes/12 would edit field 12 of category 3 and
        // report the change against the category the caller was looking at.
        if ((int) $attribute->category_id !== (int) $category->id) {
            throw new ResourceNotFoundException('Category field', $id);
        }

        return $attribute;
    }

    /**
     * How many products have answered this field, and how many have not.
     *
     * Both numbers, because they drive different refusals: the first blocks a
     * delete and the second blocks making it compulsory.
     *
     * @return array{answered: int, missing: int}
     */
    public function usageFor(ItemAttribute $attribute): array
    {
        $scope = $this->governedCategoryIds($attribute);

        return [
            'answered' => $this->attributes->valueCountForKey($attribute->key, $scope),
            'missing' => $this->attributes->missingValueCountForKey($attribute->key, $scope),
        ];
    }

    /* ---------------------------------------------------------------------
     | Writing
     |-------------------------------------------------------------------- */

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(ItemCategory $category, array $data): ItemAttribute
    {
        $label = trim((string) $data['label']);
        $key = $this->normaliseKey($data['key'] ?? null, $label);
        $type = $this->resolveType($data['data_type'] ?? null);

        $this->assertKeyAvailable($category, $key);

        $attribute = $this->attributes->create([
            'category_id' => $category->id,
            'key' => $key,
            'label' => $label,
            'data_type' => $type,
            'unit_code' => $this->resolveUnitCode($type, $data['unit_code'] ?? null),
            'is_required' => (bool) ($data['is_required'] ?? false),
            'default_value' => $this->trimmed($data['default_value'] ?? null),
            'options' => $this->normaliseOptions($type, $data['options'] ?? null),
            'min_value' => $this->normaliseBound($type, $data['min_value'] ?? null),
            'max_value' => $this->normaliseBound($type, $data['max_value'] ?? null),
            'help_text' => $this->trimmed($data['help_text'] ?? null),
            'display_order' => array_key_exists('display_order', $data)
                ? (int) $data['display_order']
                : $this->attributes->nextDisplayOrder((int) $category->id),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);

        Log::info('item_attributes.created', [
            'attribute_id' => $attribute->id,
            'category_id' => $category->id,
            'key' => $key,
        ]);

        return $attribute;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(ItemAttribute $attribute, array $data): ItemAttribute
    {
        $fields = [];
        $type = $attribute->data_type;

        if (array_key_exists('data_type', $data)) {
            $type = $this->resolveType($data['data_type']);
            $fields['data_type'] = $type;
        }

        if (array_key_exists('label', $data)) {
            $fields['label'] = trim((string) $data['label']);
        }

        if (array_key_exists('unit_code', $data) || array_key_exists('data_type', $data)) {
            $fields['unit_code'] = $this->resolveUnitCode(
                $type,
                $data['unit_code'] ?? $attribute->unit_code,
            );
        }

        if (array_key_exists('is_required', $data)) {
            $required = (bool) $data['is_required'];

            if ($required && ! $attribute->is_required) {
                $this->assertCanBecomeRequired($attribute);
            }

            $fields['is_required'] = $required;
        }

        if (array_key_exists('options', $data) || array_key_exists('data_type', $data)) {
            $options = $this->normaliseOptions($type, $data['options'] ?? $attribute->options);

            if ($options !== null) {
                $this->assertOptionsStillCover($attribute, $options);
            }

            $fields['options'] = $options;
        }

        foreach (['min_value', 'max_value'] as $bound) {
            if (array_key_exists($bound, $data)) {
                $fields[$bound] = $this->normaliseBound($type, $data[$bound]);
            }
        }

        foreach (['default_value', 'help_text'] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[$field] = $this->trimmed($data[$field]);
            }
        }

        if (array_key_exists('display_order', $data)) {
            $fields['display_order'] = (int) $data['display_order'];
        }

        if (array_key_exists('is_active', $data)) {
            $fields['is_active'] = (bool) $data['is_active'];
        }

        // `key` is absent by design — see the class note. It is the JSON key the
        // values are stored under, and renaming it would orphan every one.

        if ($fields === []) {
            return $attribute;
        }

        $attribute = $this->attributes->update($attribute, $fields);

        Log::info('item_attributes.updated', [
            'attribute_id' => $attribute->id,
            'fields' => array_keys($fields),
        ]);

        return $attribute;
    }

    /**
     * Remove a field nothing has answered.
     *
     * Anything else is refused and told to switch it off — which takes it off the
     * form and leaves it explaining the values already recorded under its key.
     */
    public function delete(ItemAttribute $attribute): void
    {
        $answered = $this->attributes->valueCountForKey(
            $attribute->key,
            $this->governedCategoryIds($attribute),
        );

        if ($answered > 0) {
            throw CatalogueMasterException::attributeHasValues(
                (int) $attribute->id,
                $attribute->label,
                $answered,
            );
        }

        $this->attributes->delete($attribute);

        Log::info('item_attributes.deleted', ['attribute_id' => $attribute->id]);
    }

    /**
     * Put a category's fields in a stated order.
     *
     * The order is the order the universal form draws them, and it matters more
     * than it looks: a specification reads the way somebody reciting it would say
     * it — 5 HP, 3 phase, 1440 RPM — and an alphabetical form makes every product
     * take a moment longer to read.
     *
     * @param  array<int, int>  $orderedIds
     */
    public function reorder(ItemCategory $category, array $orderedIds): Collection
    {
        $this->attributes->reorder($category, $orderedIds);

        return $this->forCategory($category);
    }

    /* ---------------------------------------------------------------------
     | Invariants
     |-------------------------------------------------------------------- */

    /**
     * The categories whose products answer this field: the one that defines it
     * and everything that inherits it.
     *
     * Scoped rather than global, because two categories may legitimately both ask
     * for "size" and they are different questions — counting one against the
     * other would refuse a delete on the strength of somebody else's data.
     *
     * @return array<int, int>
     */
    private function governedCategoryIds(ItemAttribute $attribute): array
    {
        return $this->categories->descendantIds((int) $attribute->category_id);
    }

    /**
     * Refuse a key an ancestor already defines, or this category already has.
     *
     * Two definitions of one JSON field means the resolver picks one and the
     * other silently does nothing — which looks exactly like a bug in the form.
     */
    private function assertKeyAvailable(ItemCategory $category, string $key, ?int $exceptId = null): void
    {
        if ($this->attributes->keyExists((int) $category->id, $key, $exceptId)) {
            throw CatalogueMasterException::attributeKeyTaken($key);
        }

        $ancestor = $category->parent;
        $guard = 0;

        while ($ancestor !== null && $guard++ < 10) {
            foreach ($ancestor->fields as $inherited) {
                if ($inherited->key === $key) {
                    throw CatalogueMasterException::attributeKeyInherited($key, $ancestor->name);
                }
            }

            $ancestor = $ancestor->parent;
        }
    }

    private function assertCanBecomeRequired(ItemAttribute $attribute): void
    {
        $missing = $this->attributes->missingValueCountForKey(
            $attribute->key,
            $this->governedCategoryIds($attribute),
        );

        if ($missing === 0) {
            return;
        }

        throw CatalogueMasterException::attributeCannotBeRequired($attribute->label, $missing);
    }

    /**
     * Refuse an option list that no longer covers values products already hold.
     *
     * @param  array<int, string>  $options
     */
    private function assertOptionsStillCover(ItemAttribute $attribute, array $options): void
    {
        $recorded = $this->attributes->distinctValuesForKey(
            $attribute->key,
            $this->governedCategoryIds($attribute),
        );

        $orphaned = array_values(array_diff($recorded, $options));

        if ($orphaned === []) {
            return;
        }

        throw CatalogueMasterException::attributeOptionsInUse($attribute->label, $orphaned);
    }

    /* ---------------------------------------------------------------------
     | Normalisation
     |-------------------------------------------------------------------- */

    /**
     * The JSON key, derived from the label where nobody supplied one.
     *
     * Snake case and starting with a letter, because it is used as an object key
     * in the browser, as a form field name, and — in one place — interpolated
     * into a JSON path in SQL. Anything outside that is refused rather than
     * escaped, because a key nobody can type is a key nobody can debug.
     */
    private function normaliseKey(?string $key, string $label): string
    {
        $source = trim((string) ($key ?? ''));

        if ($source === '') {
            $source = $label;
        }

        $slug = strtolower(preg_replace('/[^A-Za-z0-9]+/', '_', $source) ?? '');
        $slug = trim($slug, '_');

        if ($slug === '' || preg_match('/^[a-z]/', $slug) !== 1) {
            $slug = 'f_'.$slug;
        }

        return substr($slug, 0, 40);
    }

    private function resolveType(mixed $type): AttributeType
    {
        return AttributeType::tryFrom((string) $type) ?? AttributeType::Text;
    }

    /**
     * The unit, dropped where the type cannot carry one.
     *
     * A yes/no with "kg" printed after it is a form somebody has to stop and
     * puzzle over, and it would come from a type change rather than from anybody
     * choosing it. Unknown codes are dropped for the same reason: printing a unit
     * the workshop has never heard of is worse than printing none.
     */
    private function resolveUnitCode(AttributeType $type, mixed $code): ?string
    {
        if (! $type->acceptsUnit()) {
            return null;
        }

        $code = $this->trimmed($code);

        if ($code === null) {
            return null;
        }

        return $this->units->has($code) ? $code : null;
    }

    /**
     * The option list, or null where the type has none.
     *
     * Order is preserved: it is what the select renders, and alphabetising "Deep
     * groove, Needle, Tapered" would bury the common one in the middle.
     * Duplicates are dropped — two identical choices is a list nobody can pick
     * from unambiguously.
     *
     * @return array<int, string>|null
     */
    private function normaliseOptions(AttributeType $type, mixed $options): ?array
    {
        if (! $type->hasOptions()) {
            return null;
        }

        if (! is_array($options)) {
            return [];
        }

        $cleaned = [];

        foreach ($options as $option) {
            $option = trim((string) $option);

            if ($option === '' || in_array($option, $cleaned, true)) {
                continue;
            }

            $cleaned[] = $option;
        }

        return $cleaned;
    }

    /**
     * A bound, dropped where the type has no range.
     */
    private function normaliseBound(AttributeType $type, mixed $value): ?string
    {
        if (! $type->acceptsRange() || $value === null || $value === '') {
            return null;
        }

        return number_format((float) $value, 3, '.', '');
    }

    private function trimmed(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
