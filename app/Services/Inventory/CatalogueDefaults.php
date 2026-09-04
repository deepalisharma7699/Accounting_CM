<?php

namespace App\Services\Inventory;

/**
 * The units and categories a workshop starts with, declared once.
 *
 * Three callers need this list and they must not disagree: the migration that
 * backfills existing workshops, the provisioner that sets up a new one, and the
 * test suite. A second copy would drift, and the drift shows up as a workshop
 * whose catalogue is missing the unit every other workshop has.
 *
 * ## What is "system" and what is not
 *
 * The seven units and four categories that came from the original `UnitOfMeasure`
 * and `ItemType` enums are marked `is_system`. That flag means one thing only:
 * **they may be switched off but never deleted.** Quantities and products already
 * recorded refer to them, and a `piece` row deleted out of the units table would
 * leave every `'piece'` on every posted invoice meaning nothing.
 *
 * Everything else seeded here is an ordinary row. A workshop that never sells
 * anything by the metre can delete Metre outright, provided nothing points at it.
 *
 * ## Why the categories are only the original four
 *
 * Seeding "Bearing", "Capacitor" and "LED Light" would be inventing a business.
 * A motor shop and a garment shop need different categories and neither should
 * find the other's waiting for them. What they get instead is
 * {@see templates()} — ready-made definitions the admin *applies* from the
 * Category Master when they want one, which is a choice rather than an
 * assumption.
 */
class CatalogueDefaults
{
    /**
     * The units every workshop starts with.
     *
     * The first seven are the old enum, verbatim, including their exact codes,
     * symbols and scales — `piece` must still mean what it meant, and `kg` must
     * still accept three decimals. The rest are the trade's: a capacitor is
     * measured in µF and a pump in LPM, and neither could be expressed before.
     *
     * `decimals` is the whole of the fractional rule: 0 means a part of one is a
     * mistake somebody should be told about, and anything above 0 means it is
     * ordinary. See the units migration.
     *
     * @return array<int, array{code: string, label: string, symbol: string, kind: string, decimals: int, is_system: bool}>
     */
    public static function units(): array
    {
        return [
            /* ---- The original enum. Referenced by data; never deletable. ---- */
            ['code' => 'piece', 'label' => 'Piece', 'symbol' => 'pc', 'kind' => 'count', 'decimals' => 0, 'is_system' => true],
            ['code' => 'set', 'label' => 'Set', 'symbol' => 'set', 'kind' => 'count', 'decimals' => 0, 'is_system' => true],
            ['code' => 'coil', 'label' => 'Coil', 'symbol' => 'coil', 'kind' => 'count', 'decimals' => 0, 'is_system' => true],
            ['code' => 'kg', 'label' => 'Kilogram', 'symbol' => 'kg', 'kind' => 'weight', 'decimals' => 3, 'is_system' => true],
            ['code' => 'metre', 'label' => 'Metre', 'symbol' => 'm', 'kind' => 'length', 'decimals' => 3, 'is_system' => true],
            ['code' => 'litre', 'label' => 'Litre', 'symbol' => 'L', 'kind' => 'volume', 'decimals' => 3, 'is_system' => true],
            ['code' => 'hour', 'label' => 'Hour', 'symbol' => 'hr', 'kind' => 'time', 'decimals' => 3, 'is_system' => true],

            /* ---- Counting and packing ---- */
            ['code' => 'box', 'label' => 'Box', 'symbol' => 'box', 'kind' => 'count', 'decimals' => 0, 'is_system' => false],
            ['code' => 'pack', 'label' => 'Pack', 'symbol' => 'pack', 'kind' => 'count', 'decimals' => 0, 'is_system' => false],
            ['code' => 'pair', 'label' => 'Pair', 'symbol' => 'pr', 'kind' => 'count', 'decimals' => 0, 'is_system' => false],

            /* ---- Weight, length, volume ---- */
            ['code' => 'gram', 'label' => 'Gram', 'symbol' => 'g', 'kind' => 'weight', 'decimals' => 3, 'is_system' => false],
            ['code' => 'cm', 'label' => 'Centimetre', 'symbol' => 'cm', 'kind' => 'length', 'decimals' => 3, 'is_system' => false],
            ['code' => 'mm', 'label' => 'Millimetre', 'symbol' => 'mm', 'kind' => 'length', 'decimals' => 3, 'is_system' => false],
            ['code' => 'ml', 'label' => 'Millilitre', 'symbol' => 'ml', 'kind' => 'volume', 'decimals' => 3, 'is_system' => false],

            /*
            | Electrical and mechanical.
            |
            | These are mostly *attribute* units rather than stock units — nobody
            | holds four µF on a shelf — but they live in the same table because
            | an attribute names its unit by the same code an item does, and two
            | tables of units would be two lists to keep in step.
            */
            ['code' => 'hp', 'label' => 'Horsepower', 'symbol' => 'HP', 'kind' => 'electrical', 'decimals' => 3, 'is_system' => false],
            ['code' => 'kw', 'label' => 'Kilowatt', 'symbol' => 'kW', 'kind' => 'electrical', 'decimals' => 3, 'is_system' => false],
            ['code' => 'watt', 'label' => 'Watt', 'symbol' => 'W', 'kind' => 'electrical', 'decimals' => 3, 'is_system' => false],
            ['code' => 'volt', 'label' => 'Volt', 'symbol' => 'V', 'kind' => 'electrical', 'decimals' => 3, 'is_system' => false],
            ['code' => 'ampere', 'label' => 'Ampere', 'symbol' => 'A', 'kind' => 'electrical', 'decimals' => 3, 'is_system' => false],
            ['code' => 'hertz', 'label' => 'Hertz', 'symbol' => 'Hz', 'kind' => 'electrical', 'decimals' => 3, 'is_system' => false],
            ['code' => 'microfarad', 'label' => 'Microfarad', 'symbol' => 'µF', 'kind' => 'electrical', 'decimals' => 3, 'is_system' => false],

            /* ---- Ratings that are their own unit ---- */
            ['code' => 'rpm', 'label' => 'Revolutions per minute', 'symbol' => 'RPM', 'kind' => 'other', 'decimals' => 0, 'is_system' => false],
            ['code' => 'lpm', 'label' => 'Litres per minute', 'symbol' => 'LPM', 'kind' => 'other', 'decimals' => 3, 'is_system' => false],
            ['code' => 'phase', 'label' => 'Phase', 'symbol' => 'ph', 'kind' => 'other', 'decimals' => 0, 'is_system' => false],
            ['code' => 'swg', 'label' => 'Standard wire gauge', 'symbol' => 'SWG', 'kind' => 'other', 'decimals' => 0, 'is_system' => false],
            ['code' => 'lumen', 'label' => 'Lumen', 'symbol' => 'lm', 'kind' => 'other', 'decimals' => 0, 'is_system' => false],
            ['code' => 'kelvin', 'label' => 'Kelvin', 'symbol' => 'K', 'kind' => 'other', 'decimals' => 0, 'is_system' => false],
        ];
    }

    /**
     * The four categories that were `ItemType`, as rows.
     *
     * The codes are the enum's values verbatim — 'motor', 'part', 'bulk_material',
     * 'service' — which is what lets the backfill match every existing item to its
     * category without a lookup table, and what lets anything still reading the
     * old value keep meaning the same thing.
     *
     * The attribute keys are `ItemType::attributeSchema()`'s keys verbatim too,
     * for the reason that matters most: every value already sitting in an
     * `item_variants.attributes` bag has to still validate afterwards. `hp` stays
     * `hp`.
     *
     * One deliberate omission. The old `part` schema carried a `brand` attribute;
     * brand is now a column on `items`, because every trade asks whose a thing is
     * and only one of the four types could record it. The backfill moves any
     * stored value across — see the backfill migration.
     *
     * @return array<int, array{code: string, name: string, description: string, holds_stock: bool, uses_sac_code: bool, default_unit_code: string, attributes: array<int, array<string, mixed>>}>
     */
    public static function categories(): array
    {
        return [
            [
                'code' => 'motor',
                'name' => 'Motor',
                'description' => 'Finished motors, identified by their electrical rating rather than a part number.',
                'holds_stock' => true,
                'uses_sac_code' => false,
                'default_unit_code' => 'piece',
                'attributes' => [
                    ['key' => 'hp', 'label' => 'Rating', 'data_type' => 'decimal', 'unit_code' => 'hp', 'is_required' => true],
                    ['key' => 'phase', 'label' => 'Phase', 'data_type' => 'dropdown', 'unit_code' => 'phase', 'is_required' => true, 'options' => ['1', '3']],
                    ['key' => 'rpm', 'label' => 'Speed', 'data_type' => 'number', 'unit_code' => 'rpm', 'is_required' => true],
                    ['key' => 'frame', 'label' => 'Frame size', 'data_type' => 'text', 'is_required' => false],
                    ['key' => 'mounting', 'label' => 'Mounting', 'data_type' => 'dropdown', 'is_required' => false, 'options' => ['foot', 'flange', 'face']],
                ],
            ],
            [
                'code' => 'part',
                'name' => 'Part',
                'description' => 'Bought-in components: bearings, fans, terminal blocks, capacitors.',
                'holds_stock' => true,
                'uses_sac_code' => false,
                'default_unit_code' => 'piece',
                'attributes' => [
                    ['key' => 'size', 'label' => 'Size', 'data_type' => 'text', 'is_required' => true],
                    ['key' => 'material', 'label' => 'Material', 'data_type' => 'text', 'is_required' => false],
                ],
            ],
            [
                'code' => 'bulk_material',
                'name' => 'Bulk material',
                'description' => 'Consumed by weight or length rather than counted — copper wire, varnish, insulation.',
                'holds_stock' => true,
                'uses_sac_code' => false,
                'default_unit_code' => 'kg',
                'attributes' => [
                    ['key' => 'gauge', 'label' => 'Gauge', 'data_type' => 'text', 'unit_code' => 'swg', 'is_required' => true],
                    ['key' => 'grade', 'label' => 'Grade', 'data_type' => 'text', 'is_required' => false],
                ],
            ],
            [
                'code' => 'service',
                'name' => 'Service',
                'description' => 'Labour: rewinding, testing, a site visit. Produced at the moment it is sold, so it holds no stock.',
                // The one category that cannot hold stock, and not as policy: an
                // hour is produced at the moment it is sold, so an opening
                // balance of forty hours would be inventing an asset.
                'holds_stock' => false,
                'uses_sac_code' => true,
                'default_unit_code' => 'hour',
                // An hour of rewinding is an hour of rewinding. An attribute bag
                // on one would only ever be filled in wrong.
                'attributes' => [],
            ],
        ];
    }

    /**
     * Ready-made category definitions an admin can apply from the Category
     * Master, rather than typing six attributes by hand.
     *
     * Not seeded — offered. A garment shop should not find "Capacitor" in its
     * catalogue because the product was written for a motor workshop, and a motor
     * workshop should not have to type out µF and Voltage to prove the system
     * works. Applying one creates an ordinary category the admin can then edit or
     * delete; nothing here is privileged.
     *
     * @return array<int, array{code: string, name: string, description: string, holds_stock: bool, uses_sac_code: bool, default_unit_code: string, attributes: array<int, array<string, mixed>>}>
     */
    public static function templates(): array
    {
        return [
            [
                'code' => null,
                'name' => 'Bearing',
                'description' => 'Ball, roller and tapered bearings, identified by their three dimensions.',
                'holds_stock' => true,
                'uses_sac_code' => false,
                'default_unit_code' => 'piece',
                'attributes' => [
                    ['key' => 'inner_diameter', 'label' => 'Inner diameter', 'data_type' => 'decimal', 'unit_code' => 'mm', 'is_required' => true],
                    ['key' => 'outer_diameter', 'label' => 'Outer diameter', 'data_type' => 'decimal', 'unit_code' => 'mm', 'is_required' => true],
                    ['key' => 'width', 'label' => 'Width', 'data_type' => 'decimal', 'unit_code' => 'mm', 'is_required' => false],
                    ['key' => 'bearing_type', 'label' => 'Bearing type', 'data_type' => 'dropdown', 'is_required' => false, 'options' => ['Deep groove', 'Tapered', 'Needle', 'Spherical', 'Thrust']],
                ],
            ],
            [
                'code' => null,
                'name' => 'Capacitor',
                'description' => 'Start and run capacitors, identified by capacitance and working voltage.',
                'holds_stock' => true,
                'uses_sac_code' => false,
                'default_unit_code' => 'piece',
                'attributes' => [
                    ['key' => 'capacitance', 'label' => 'Capacitance', 'data_type' => 'decimal', 'unit_code' => 'microfarad', 'is_required' => true],
                    ['key' => 'voltage', 'label' => 'Voltage', 'data_type' => 'number', 'unit_code' => 'volt', 'is_required' => true],
                    ['key' => 'capacitor_type', 'label' => 'Type', 'data_type' => 'dropdown', 'is_required' => false, 'options' => ['Start', 'Run', 'Dual']],
                ],
            ],
            [
                'code' => null,
                'name' => 'Wire',
                'description' => 'Winding and connecting wire, bought and issued by weight or length.',
                'holds_stock' => true,
                'uses_sac_code' => false,
                'default_unit_code' => 'kg',
                'attributes' => [
                    ['key' => 'gauge', 'label' => 'Gauge', 'data_type' => 'decimal', 'unit_code' => 'swg', 'is_required' => true],
                    ['key' => 'material', 'label' => 'Material', 'data_type' => 'dropdown', 'is_required' => true, 'options' => ['Copper', 'Aluminium']],
                    ['key' => 'insulation', 'label' => 'Insulation type', 'data_type' => 'text', 'is_required' => false],
                ],
            ],
            [
                'code' => null,
                'name' => 'Water pump',
                'description' => 'Monoblock, submersible and centrifugal pumps.',
                'holds_stock' => true,
                'uses_sac_code' => false,
                'default_unit_code' => 'piece',
                'attributes' => [
                    ['key' => 'hp', 'label' => 'Rating', 'data_type' => 'decimal', 'unit_code' => 'hp', 'is_required' => true],
                    ['key' => 'head', 'label' => 'Head', 'data_type' => 'decimal', 'unit_code' => 'metre', 'is_required' => false],
                    ['key' => 'flow_rate', 'label' => 'Flow rate', 'data_type' => 'decimal', 'unit_code' => 'lpm', 'is_required' => false],
                    ['key' => 'voltage', 'label' => 'Voltage', 'data_type' => 'number', 'unit_code' => 'volt', 'is_required' => false],
                    ['key' => 'rpm', 'label' => 'Speed', 'data_type' => 'number', 'unit_code' => 'rpm', 'is_required' => false],
                    ['key' => 'phase', 'label' => 'Phase', 'data_type' => 'dropdown', 'unit_code' => 'phase', 'is_required' => false, 'options' => ['1', '3']],
                ],
            ],
            [
                'code' => null,
                'name' => 'LED light',
                'description' => 'Lamps, panels and fittings, identified by wattage and light output.',
                'holds_stock' => true,
                'uses_sac_code' => false,
                'default_unit_code' => 'piece',
                'attributes' => [
                    ['key' => 'wattage', 'label' => 'Wattage', 'data_type' => 'decimal', 'unit_code' => 'watt', 'is_required' => true],
                    ['key' => 'voltage', 'label' => 'Voltage', 'data_type' => 'number', 'unit_code' => 'volt', 'is_required' => false],
                    ['key' => 'lumens', 'label' => 'Lumens', 'data_type' => 'number', 'unit_code' => 'lumen', 'is_required' => false],
                    ['key' => 'colour_temperature', 'label' => 'Colour temperature', 'data_type' => 'number', 'unit_code' => 'kelvin', 'is_required' => false],
                    ['key' => 'base_type', 'label' => 'Base type', 'data_type' => 'dropdown', 'is_required' => false, 'options' => ['B22', 'E27', 'E14', 'GU10', 'G9']],
                ],
            ],
            [
                'code' => null,
                'name' => 'Apparel',
                'description' => 'Garments, where size and colour are the thing stock is counted by.',
                'holds_stock' => true,
                'uses_sac_code' => false,
                'default_unit_code' => 'piece',
                'attributes' => [
                    ['key' => 'size', 'label' => 'Size', 'data_type' => 'dropdown', 'is_required' => true, 'options' => ['XS', 'S', 'M', 'L', 'XL', 'XXL', '3XL']],
                    ['key' => 'colour', 'label' => 'Colour', 'data_type' => 'text', 'is_required' => true],
                    ['key' => 'fabric', 'label' => 'Fabric', 'data_type' => 'text', 'is_required' => false],
                    ['key' => 'gender', 'label' => 'Gender', 'data_type' => 'dropdown', 'is_required' => false, 'options' => ['Men', 'Women', 'Unisex', 'Kids']],
                ],
            ],
        ];
    }
}
