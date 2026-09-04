<?php

namespace App\Exceptions\Accounting;

use App\Exceptions\ApiException;
use App\Models\ItemCategory;

/**
 * A product described in terms its category does not recognise, described with a
 * value the category does not allow, or not described at all.
 *
 * The attribute schema is what makes a catalogue usable rather than a list of
 * names. A motor without its HP rating cannot be priced, matched to a customer's
 * request, or resolved from "the five horse" — and a workshop with forty
 * unlabelled "motor" rows has a catalogue that is worse than no catalogue, because
 * people will pick one at random rather than admit they cannot tell.
 *
 * Optional attributes are never demanded. Workshops differ in how much they
 * record, and refusing a bearing because nobody typed its material would push
 * people into not recording the bearing.
 *
 * Every message names the *category*, because that is the word the person on the
 * screen just chose from a dropdown. "A motor needs its rating" is actionable;
 * "attributes.hp is required" is a developer talking to themselves.
 */
class InvalidItemAttributesException extends ApiException
{
    /**
     * @param  array<int, string>  $missing
     * @param  array<string, array<string, mixed>>  $schema
     */
    public static function missing(ItemCategory $category, array $missing, array $schema = []): self
    {
        $labels = array_map(
            static fn (string $key) => strtolower($schema[$key]['label'] ?? $key),
            $missing,
        );

        return new self(
            message: sprintf(
                'A %s needs its %s — without them nobody can tell this product from another.',
                strtolower($category->name),
                implode(' and ', $labels),
            ),
            status: 422,
            errorCode: 'ITEM_ATTRIBUTES_MISSING',
            details: [
                'field' => 'attributes',
                'category_id' => (int) $category->id,
                'category' => $category->name,
                'missing' => $missing,
            ],
        );
    }

    /**
     * @param  array<int, string>  $unknown
     * @param  array<int, string>  $allowed
     */
    public static function unknown(ItemCategory $category, array $unknown, array $allowed): self
    {
        return new self(
            message: sprintf(
                'A %s is not described by %s. %s',
                strtolower($category->name),
                implode(' or ', $unknown),
                $allowed === []
                    ? 'This category has no fields configured — add them under Categories if it should.'
                    : 'It is described by '.implode(', ', $allowed).'.',
            ),
            status: 422,
            errorCode: 'ITEM_ATTRIBUTES_UNKNOWN',
            details: [
                'field' => 'attributes',
                'category_id' => (int) $category->id,
                'category' => $category->name,
                'unknown' => $unknown,
                'allowed' => $allowed,
            ],
        );
    }

    /**
     * A field with a genuinely fixed set of values, given something outside it —
     * a motor's phase is 1 or 3 and there is no third possibility.
     *
     * @param  array<int, string>  $allowed
     */
    public static function badValue(ItemCategory $category, string $key, string $label, string $given, array $allowed): self
    {
        return new self(
            message: sprintf(
                'A %s\'s %s is one of %s — "%s" is not.',
                strtolower($category->name),
                strtolower($label),
                implode(', ', $allowed),
                $given,
            ),
            status: 422,
            errorCode: 'ITEM_ATTRIBUTE_VALUE_INVALID',
            details: [
                'field' => 'attributes',
                'attribute' => $key,
                'given' => $given,
                'allowed' => $allowed,
            ],
        );
    }

    /**
     * A value that is not the shape its field asks for — text in a number, a
     * fraction in a whole-number field, something a date parser cannot read.
     *
     * Separate from {@see badValue()} because the remedy is different: one says
     * "pick from this list" and this says "that is not a number".
     */
    public static function badFormat(ItemCategory $category, string $key, string $label, string $given, string $expected): self
    {
        return new self(
            message: sprintf(
                'A %s\'s %s must be %s — "%s" is not.',
                strtolower($category->name),
                strtolower($label),
                $expected,
                $given,
            ),
            status: 422,
            errorCode: 'ITEM_ATTRIBUTE_FORMAT_INVALID',
            details: [
                'field' => 'attributes',
                'attribute' => $key,
                'given' => $given,
                'expected' => $expected,
            ],
        );
    }

    /**
     * A number outside the bounds its field declares — a phase of 7, a 900 HP
     * motor in a shop whose largest is 50.
     */
    public static function outOfRange(ItemCategory $category, string $key, string $label, string $given, ?string $min, ?string $max): self
    {
        $bound = match (true) {
            $min !== null && $max !== null => sprintf('between %s and %s', self::trim($min), self::trim($max)),
            $min !== null => sprintf('at least %s', self::trim($min)),
            default => sprintf('at most %s', self::trim((string) $max)),
        };

        return new self(
            message: sprintf(
                'A %s\'s %s must be %s — "%s" is not.',
                strtolower($category->name),
                strtolower($label),
                $bound,
                $given,
            ),
            status: 422,
            errorCode: 'ITEM_ATTRIBUTE_OUT_OF_RANGE',
            details: [
                'field' => 'attributes',
                'attribute' => $key,
                'given' => $given,
                'min' => $min,
                'max' => $max,
            ],
        );
    }

    /**
     * Bounds are stored as DECIMAL(15,3), so "5" comes back as "5.000". Printing
     * that at somebody is a system talking to itself.
     */
    private static function trim(string $number): string
    {
        return str_contains($number, '.')
            ? rtrim(rtrim($number, '0'), '.')
            : $number;
    }
}
