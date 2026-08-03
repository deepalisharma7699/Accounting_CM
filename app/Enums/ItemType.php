<?php

namespace App\Enums;

use App\Models\Item;
use App\Models\ItemVariant;

/**
 * What kind of thing an item is.
 *
 * The type decides three things nothing else can: whether it can be held in
 * stock, which unit it is counted in, and **which attributes a variant of it must
 * carry**. That last one is the reason this enum is more than a label — a motor
 * without its HP rating is not identifiable, and a workshop with a hundred
 * unlabelled "motor" rows cannot price a rewind.
 *
 * The four types come from the trade rather than from accounting. A rewinding
 * shop sells finished motors, fits bought-in parts, consumes copper and varnish
 * by weight, and bills labour by the hour. Those four behave differently at every
 * later stage: three of them move stock and one cannot, two of them have a
 * meaningful piece count and one is measured out.
 *
 * @see Item for the catalogue record, {@see ItemVariant} for the specific thing
 */
enum ItemType: string
{
    /**
     * A finished motor — the thing the workshop is actually known for.
     *
     * Identified by its electrical rating rather than by a part number, because
     * that is how customers ask for one: "5 HP, three phase, 1440".
     */
    case Motor = 'motor';

    /** A bought-in component: a bearing, a fan, a terminal block, a capacitor. */
    case Part = 'part';

    /**
     * Something consumed by weight or length rather than counted — copper wire,
     * varnish, insulation paper.
     *
     * The type M8's weighted average cost matters most for: buy copper at ₹700/kg
     * and again at ₹800/kg, and the cost of the 3 kg used on today's rewind is
     * neither of those numbers.
     */
    case BulkMaterial = 'bulk_material';

    /**
     * Labour. Rewinding, testing, a site visit.
     *
     * The one type that **cannot hold stock**, and not as a matter of policy: an
     * hour is produced at the moment it is sold. Recording an opening balance of
     * forty hours would be inventing an asset that does not exist.
     */
    case Service = 'service';

    public function label(): string
    {
        return match ($this) {
            self::Motor => 'Motor',
            self::Part => 'Part',
            self::BulkMaterial => 'Bulk material',
            self::Service => 'Service',
        };
    }

    /**
     * Whether the type is capable of being held in stock at all.
     *
     * A service never is. The other three are, and an item of those types may
     * still be marked as non-stock by the workshop — a part they buy to order and
     * never inventory is a real arrangement. So this is capability, and
     * `items.is_stock` is the workshop's choice within it.
     */
    public function canHoldStock(): bool
    {
        return $this !== self::Service;
    }

    /**
     * The unit an item of this type is counted in unless the workshop says
     * otherwise.
     */
    public function defaultUom(): UnitOfMeasure
    {
        return match ($this) {
            self::Motor, self::Part => UnitOfMeasure::Piece,
            self::BulkMaterial => UnitOfMeasure::Kilogram,
            self::Service => UnitOfMeasure::Hour,
        };
    }

    /**
     * Whether the type is billed under an HSN code (goods) or a SAC code
     * (services). M9 needs the distinction; the column holds either.
     */
    public function usesSacCode(): bool
    {
        return $this === self::Service;
    }

    /**
     * The attributes a variant of this type is described by.
     *
     * This is the schema the roadmap asks to be validated per item type, declared
     * once here rather than as a validation rule in a form request — because
     * M11's importer and M15's capture agent create variants without passing
     * through one, and a motor whose HP was never captured is not identifiable by
     * anybody afterwards.
     *
     * `required` means a variant cannot exist without it. Everything else is
     * accepted and stored but never demanded: workshops differ in how much they
     * record, and refusing a bearing because nobody typed its material would push
     * people into not recording the bearing.
     *
     * `values` constrains a field to a fixed set where one genuinely exists —
     * phase is 1 or 3 and nothing else — and is absent where the range is open.
     *
     * @return array<string, array{label: string, required: bool, values?: array<int, string>, suffix?: string}>
     */
    public function attributeSchema(): array
    {
        return match ($this) {
            self::Motor => [
                'hp' => ['label' => 'Rating', 'required' => true, 'suffix' => 'HP'],
                'phase' => ['label' => 'Phase', 'required' => true, 'values' => ['1', '3'], 'suffix' => 'ph'],
                'rpm' => ['label' => 'Speed', 'required' => true, 'suffix' => 'RPM'],
                'frame' => ['label' => 'Frame size', 'required' => false],
                'mounting' => ['label' => 'Mounting', 'required' => false, 'values' => ['foot', 'flange', 'face']],
            ],

            self::Part => [
                'size' => ['label' => 'Size', 'required' => true],
                'material' => ['label' => 'Material', 'required' => false],
                'brand' => ['label' => 'Brand', 'required' => false],
            ],

            self::BulkMaterial => [
                'gauge' => ['label' => 'Gauge', 'required' => true],
                'grade' => ['label' => 'Grade', 'required' => false],
            ],

            // A service has nothing to vary. An hour of rewinding is an hour of
            // rewinding, and an attribute bag on one would only ever be filled in
            // wrong.
            self::Service => [],
        };
    }

    /**
     * @return array<int, string>
     */
    public function requiredAttributes(): array
    {
        return array_keys(array_filter(
            $this->attributeSchema(),
            static fn (array $field) => $field['required'],
        ));
    }

    /**
     * Whether a variant of this type can carry attributes at all.
     */
    public function hasAttributes(): bool
    {
        return $this->attributeSchema() !== [];
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
