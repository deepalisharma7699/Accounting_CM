<?php

namespace App\Enums;

/**
 * The kinds of value a category attribute can hold.
 *
 * ## Why this one stays an enum
 *
 * `ItemType` and `UnitOfMeasure` became tables because they were the *business's*
 * vocabulary, and the business kept needing words the developer had not thought
 * of. This is not that. These are the input controls the form knows how to draw
 * and the rules the validator knows how to apply — the *system's* capability, not
 * the workshop's. A row here would be a promise the application could not keep:
 * an admin inserting `data_type = 'colour_picker'` would get a text box and no
 * explanation.
 *
 * ## Why there is no `measurement`
 *
 * The brief lists one, and it is not a seventh type: a measurement is a number
 * with a unit, and `item_attributes.unit_code` is what makes it one. Adding a
 * type that differed from `number` only by having a unit would be two ways to say
 * one thing, and the form would have to decide which of them meant it.
 */
enum AttributeType: string
{
    /** Free text — a frame size, a material, a fit. */
    case Text = 'text';

    /**
     * A whole number: RPM, lumens, a colour temperature.
     *
     * Separate from {@see Decimal} because the distinction is the same one
     * `UnitOfMeasure::isFractional()` drew and for the same reason: 1440.5 RPM is
     * a typo somebody should be told about, and 5.5 HP is ordinary.
     */
    case Number = 'number';

    /** A fractional number: 5.5 HP, 12.7 mm, 2.5 µF. */
    case Decimal = 'decimal';

    /**
     * One of a fixed set the admin defines — phase is 1 or 3, a bearing is deep
     * groove or tapered or needle.
     *
     * The only type whose `options` column is used, and the only one where a
     * value outside the set is refused rather than stored.
     */
    case Dropdown = 'dropdown';

    /** Yes or no — battery required, veg or non-veg, RoHS compliant. */
    case Boolean = 'boolean';

    /** A date — a manufacture date, a certification expiry. */
    case Date = 'date';

    public function label(): string
    {
        return match ($this) {
            self::Text => 'Text',
            self::Number => 'Whole number',
            self::Decimal => 'Decimal number',
            self::Dropdown => 'Dropdown',
            self::Boolean => 'Yes / No',
            self::Date => 'Date',
        };
    }

    /**
     * One line telling an admin configuring a category what they are choosing.
     */
    public function hint(): string
    {
        return match ($this) {
            self::Text => 'Any text — a frame size, a material, a model code.',
            self::Number => 'Whole numbers only. 1440 RPM, 800 lumens.',
            self::Decimal => 'Fractions allowed. 5.5 HP, 12.7 mm.',
            self::Dropdown => 'A fixed list you define. The user picks one.',
            self::Boolean => 'A tick box. Stored as yes or no.',
            self::Date => 'A calendar date.',
        };
    }

    /**
     * Whether the type carries a fixed set of options — the one thing that
     * decides whether the `options` column means anything.
     */
    public function hasOptions(): bool
    {
        return $this === self::Dropdown;
    }

    /**
     * Whether a unit can sensibly be printed beside the input.
     *
     * Numbers take one — 5 *HP*, 1440 *RPM*. A yes/no and a date do not, and a
     * dropdown does only occasionally (phase), so it is permitted rather than
     * assumed.
     */
    public function acceptsUnit(): bool
    {
        return match ($this) {
            self::Number, self::Decimal, self::Dropdown => true,
            self::Text, self::Boolean, self::Date => false,
        };
    }

    /**
     * Whether `min_value` / `max_value` apply.
     */
    public function acceptsRange(): bool
    {
        return $this === self::Number || $this === self::Decimal;
    }

    public function isNumeric(): bool
    {
        return $this->acceptsRange();
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return array<int, array{value: string, label: string, hint: string, has_options: bool, accepts_unit: bool, accepts_range: bool}>
     */
    public static function catalogue(): array
    {
        return array_map(fn (self $type) => [
            'value' => $type->value,
            'label' => $type->label(),
            'hint' => $type->hint(),
            'has_options' => $type->hasOptions(),
            'accepts_unit' => $type->acceptsUnit(),
            'accepts_range' => $type->acceptsRange(),
        ], self::cases());
    }
}
