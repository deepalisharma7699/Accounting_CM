<?php

namespace App\Services\Workshop;

/**
 * The kinds of thing a workshop is given to start with, and what each asks.
 *
 * ## Why these, and why they are not the catalogue's
 *
 * Because they are the things a customer carries or wheels in. The bench's Kind
 * list used to be the catalogue's categories, so it offered Part, Bulk material,
 * Bearing and Wire — which nobody brings in for repair — and offered no cooler,
 * fan, mixer or submersible at all, because a repair shop does not stock the
 * things it repairs. See {@see \App\Models\JobKind}.
 *
 * ## What is deliberately not asked
 *
 * Brand, model, serial number, the complaint, the date it came in and the date
 * it was promised are **columns on the job**, because every kind of thing has
 * them. A question set that repeated any of those would be a second place to
 * type one fact, and the two would disagree on the card that mattered. So a
 * kind asks only what is specific to that kind of thing: a fan has a sweep and
 * a motor has a frame, and neither means anything about the other.
 *
 * ## Seeded, and deliberately not protected
 *
 * `is_system` is off on every row here, unlike the four categories that
 * replaced `ItemType`. Those are referenced by products and posted documents,
 * so they may only ever be archived. These are a starting guess at what a
 * particular workshop repairs, and a shop that never touches a washing machine
 * should be able to remove the row rather than carry it about switched off. The
 * real protection is the one that matters and it is not this flag:
 * {@see JobKindService::delete()} refuses any kind a job is filed under.
 */
final class JobKindDefaults
{
    /**
     * @return array<int, array{name: string, description: string, attributes: array<int, array<string, mixed>>}>
     */
    public static function kinds(): array
    {
        return [
            [
                'name' => 'Motor',
                'description' => 'Induction motors off a machine, a pump or a bench — rewinding, bearing work, testing.',
                'attributes' => [
                    ['key' => 'hp', 'label' => 'Rating', 'data_type' => 'decimal', 'unit_code' => 'hp'],
                    ['key' => 'phase', 'label' => 'Phase', 'data_type' => 'dropdown', 'unit_code' => 'phase', 'options' => ['1', '3']],
                    ['key' => 'rpm', 'label' => 'Speed', 'data_type' => 'number', 'unit_code' => 'rpm'],
                    ['key' => 'frame', 'label' => 'Frame size', 'data_type' => 'text'],
                    ['key' => 'mounting', 'label' => 'Mounting', 'data_type' => 'dropdown', 'options' => ['foot', 'flange', 'face']],
                ],
            ],
            [
                'name' => 'Submersible pump',
                'description' => 'Borewell sets that come up out of the ground — the wet end and the motor together.',
                'attributes' => [
                    ['key' => 'hp', 'label' => 'Rating', 'data_type' => 'decimal', 'unit_code' => 'hp'],
                    ['key' => 'phase', 'label' => 'Phase', 'data_type' => 'dropdown', 'unit_code' => 'phase', 'options' => ['1', '3']],
                    ['key' => 'stages', 'label' => 'Stages', 'data_type' => 'number'],
                    ['key' => 'head', 'label' => 'Head', 'data_type' => 'decimal', 'unit_code' => 'metre'],
                    ['key' => 'delivery_size', 'label' => 'Delivery size', 'data_type' => 'text', 'unit_code' => 'mm'],
                ],
            ],
            [
                'name' => 'Monoblock pump',
                'description' => 'Surface pumps — the motor and the wet end on one shaft.',
                'attributes' => [
                    ['key' => 'hp', 'label' => 'Rating', 'data_type' => 'decimal', 'unit_code' => 'hp'],
                    ['key' => 'phase', 'label' => 'Phase', 'data_type' => 'dropdown', 'unit_code' => 'phase', 'options' => ['1', '3']],
                    ['key' => 'suction_size', 'label' => 'Suction size', 'data_type' => 'text', 'unit_code' => 'mm'],
                    ['key' => 'delivery_size', 'label' => 'Delivery size', 'data_type' => 'text', 'unit_code' => 'mm'],
                ],
            ],
            [
                'name' => 'Ceiling fan',
                'description' => 'Ceiling and wall fans — rewinding, bearings, a capacitor, a set of blades.',
                'attributes' => [
                    ['key' => 'sweep', 'label' => 'Sweep', 'data_type' => 'number', 'unit_code' => 'mm'],
                    ['key' => 'capacitor', 'label' => 'Capacitor', 'data_type' => 'decimal', 'unit_code' => 'microfarad'],
                    ['key' => 'blades', 'label' => 'Blades', 'data_type' => 'number'],
                ],
            ],
            [
                'name' => 'Cooler',
                'description' => 'Air coolers — the fan motor, the pump, the body.',
                'attributes' => [
                    ['key' => 'cooler_type', 'label' => 'Type', 'data_type' => 'dropdown', 'options' => ['desert', 'personal', 'window', 'tower']],
                    ['key' => 'tank_capacity', 'label' => 'Tank capacity', 'data_type' => 'decimal', 'unit_code' => 'litre'],
                    ['key' => 'has_pump', 'label' => 'Water pump fitted', 'data_type' => 'boolean'],
                ],
            ],
            [
                'name' => 'Mixer grinder',
                'description' => 'Mixers, grinders and food processors — armature, brushes, couplers.',
                'attributes' => [
                    ['key' => 'wattage', 'label' => 'Wattage', 'data_type' => 'number', 'unit_code' => 'watt'],
                    ['key' => 'jars', 'label' => 'Jars received', 'data_type' => 'number'],
                ],
            ],
            [
                'name' => 'Washing machine',
                'description' => 'Washing machines — the wash motor, the spin motor, the timer.',
                'attributes' => [
                    ['key' => 'machine_type', 'label' => 'Type', 'data_type' => 'dropdown', 'options' => ['semi-automatic', 'fully-automatic top load', 'fully-automatic front load']],
                    ['key' => 'capacity', 'label' => 'Capacity', 'data_type' => 'decimal', 'unit_code' => 'kg'],
                ],
            ],
            [
                'name' => 'Water heater',
                'description' => 'Geysers — element, thermostat, tank.',
                'attributes' => [
                    ['key' => 'capacity', 'label' => 'Capacity', 'data_type' => 'decimal', 'unit_code' => 'litre'],
                    ['key' => 'wattage', 'label' => 'Wattage', 'data_type' => 'number', 'unit_code' => 'watt'],
                ],
            ],
        ];
    }
}
