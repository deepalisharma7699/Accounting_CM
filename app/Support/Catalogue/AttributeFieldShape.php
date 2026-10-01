<?php

namespace App\Support\Catalogue;

use App\Enums\AttributeType;
use App\Support\Units\UnitRegistry;

/**
 * What a field definition's values are allowed to look like on the way in.
 *
 * The write-side counterpart to {@see \App\Models\AttributeDefinition}: that
 * decides what a field *means* once stored, this decides what is stored. Both
 * are shared by the catalogue's `item_attributes` and the bench's
 * `job_kind_attributes`, which are two separate lists over one piece of
 * machinery — see {@see \App\Models\JobKind} for why the lists are separate and
 * why the machinery is not.
 *
 * Every rule here was written for a product and every one of them holds for a
 * thing on a bench, because none of them is about what the field describes: a
 * key has to be typable either way, a yes/no cannot carry a unit either way, and
 * a duplicated dropdown option is unpickable either way. Two copies would be two
 * places the snake-case rule lives, and the one that drifts is discovered when a
 * key that works on one form is refused on the other.
 */
final class AttributeFieldShape
{
    public function __construct(private readonly UnitRegistry $units) {}

    /**
     * The JSON key, derived from the label where nobody supplied one.
     *
     * Snake case and starting with a letter, because it is used as an object key
     * in the browser, as a form field name, and — in one place — interpolated
     * into a JSON path in SQL. Anything outside that is refused rather than
     * escaped, because a key nobody can type is a key nobody can debug.
     */
    public function key(?string $key, string $label): string
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

    public function type(mixed $type): AttributeType
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
    public function unitCode(AttributeType $type, mixed $code): ?string
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
    public function options(AttributeType $type, mixed $options): ?array
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
    public function bound(AttributeType $type, mixed $value): ?string
    {
        if (! $type->acceptsRange() || $value === null || $value === '') {
            return null;
        }

        return number_format((float) $value, 3, '.', '');
    }

    public function trimmed(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
