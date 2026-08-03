<?php

namespace App\Exceptions\Accounting;

use App\Enums\ItemType;
use App\Exceptions\ApiException;

/**
 * A variant described in terms its item type does not recognise, or not described
 * at all.
 *
 * The attribute schema is what makes a catalogue usable rather than a list of
 * names. A motor without its HP rating cannot be priced, matched to a customer's
 * request, or resolved by M15 from "the five horse" — and a workshop with forty
 * unlabelled "motor" rows has a catalogue that is worse than no catalogue, because
 * people will pick one at random rather than admit they cannot tell.
 *
 * Optional attributes are never demanded. Workshops differ in how much they
 * record, and refusing a bearing because nobody typed its material would push
 * people into not recording the bearing.
 */
class InvalidItemAttributesException extends ApiException
{
    /**
     * @param  array<int, string>  $missing
     */
    public static function missing(ItemType $type, array $missing): self
    {
        $labels = array_map(
            static fn (string $key) => strtolower($type->attributeSchema()[$key]['label'] ?? $key),
            $missing,
        );

        return new self(
            message: sprintf(
                'A %s needs its %s — without them nobody can tell this variant from another.',
                strtolower($type->label()),
                implode(' and ', $labels),
            ),
            status: 422,
            errorCode: 'ITEM_ATTRIBUTES_MISSING',
            details: ['field' => 'attributes', 'type' => $type->value, 'missing' => $missing],
        );
    }

    /**
     * @param  array<int, string>  $unknown
     * @param  array<int, string>  $allowed
     */
    public static function unknown(ItemType $type, array $unknown, array $allowed): self
    {
        return new self(
            message: sprintf(
                'A %s is not described by %s. %s',
                strtolower($type->label()),
                implode(' or ', $unknown),
                $allowed === []
                    ? 'It has no attributes at all — an hour of labour is an hour of labour.'
                    : 'It is described by '.implode(', ', $allowed).'.',
            ),
            status: 422,
            errorCode: 'ITEM_ATTRIBUTES_UNKNOWN',
            details: ['field' => 'attributes', 'type' => $type->value, 'unknown' => $unknown, 'allowed' => $allowed],
        );
    }

    /**
     * A field with a genuinely fixed set of values, given something outside it —
     * a motor's phase is 1 or 3 and there is no third possibility.
     *
     * @param  array<int, string>  $allowed
     */
    public static function badValue(ItemType $type, string $key, string $given, array $allowed): self
    {
        $label = strtolower($type->attributeSchema()[$key]['label'] ?? $key);

        return new self(
            message: sprintf(
                'A %s\'s %s is one of %s — "%s" is not.',
                strtolower($type->label()),
                $label,
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
}
