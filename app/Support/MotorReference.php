<?php

namespace App\Support;

/**
 * The three tables the trade looks up.
 *
 * Standard engineering values, not facts about this shop — which is why they
 * are code rather than rows in config/shop.php. Nobody edits these; they are
 * the same in Charkhi Dadri as they are anywhere else, and they are the same
 * in both languages, so only the column headings are translated.
 *
 * They are on the site because a winder halfway through a job should not have
 * to hunt for them, and because a page that is useful to the trade is read by
 * the trade. It is the cheapest genuinely useful thing the shopfront does.
 */
final class MotorReference
{
    /**
     * Output rating against approximate full load current.
     *
     * kW is the exact conversion (1 HP = 0.746 kW), rounded to the value the
     * makers actually print. The current is indicative for a three-phase 415 V
     * motor at roughly 0.85 power factor and 0.88 efficiency — close enough to
     * size a starter against, and the page says in as many words that the
     * nameplate is what a starter is actually set from.
     *
     * @return list<array{hp: string, kw: string, amps: string}>
     */
    public static function ratings(): array
    {
        return [
            ['hp' => '0.5', 'kw' => '0.37', 'amps' => '1.1'],
            ['hp' => '1', 'kw' => '0.75', 'amps' => '1.9'],
            ['hp' => '2', 'kw' => '1.5', 'amps' => '3.5'],
            ['hp' => '3', 'kw' => '2.2', 'amps' => '5.0'],
            ['hp' => '5', 'kw' => '3.7', 'amps' => '7.9'],
            ['hp' => '7.5', 'kw' => '5.5', 'amps' => '11.5'],
            ['hp' => '10', 'kw' => '7.5', 'amps' => '15.5'],
            ['hp' => '15', 'kw' => '11', 'amps' => '22'],
            ['hp' => '20', 'kw' => '15', 'amps' => '30'],
            ['hp' => '25', 'kw' => '18.5', 'amps' => '36'],
            ['hp' => '30', 'kw' => '22', 'amps' => '43'],
            ['hp' => '40', 'kw' => '30', 'amps' => '57'],
            ['hp' => '50', 'kw' => '37', 'amps' => '70'],
            ['hp' => '60', 'kw' => '45', 'amps' => '85'],
            ['hp' => '75', 'kw' => '55', 'amps' => '103'],
            ['hp' => '100', 'kw' => '75', 'amps' => '140'],
        ];
    }

    /**
     * Standard Wire Gauge against bare copper diameter in millimetres.
     *
     * The working range for motor winding — below 18 SWG is busbar territory
     * and above 40 is too fine to handle on a hand winder. Bare diameter: the
     * enamel adds roughly 0.02–0.05 mm depending on the grade, which the page
     * states because it is the difference between a slot that fills and one
     * that does not.
     *
     * @return list<array{swg: string, mm: string}>
     */
    public static function wireGauges(): array
    {
        return [
            ['swg' => '18', 'mm' => '1.219'],
            ['swg' => '19', 'mm' => '1.016'],
            ['swg' => '20', 'mm' => '0.914'],
            ['swg' => '21', 'mm' => '0.813'],
            ['swg' => '22', 'mm' => '0.711'],
            ['swg' => '23', 'mm' => '0.610'],
            ['swg' => '24', 'mm' => '0.559'],
            ['swg' => '25', 'mm' => '0.508'],
            ['swg' => '26', 'mm' => '0.457'],
            ['swg' => '27', 'mm' => '0.417'],
            ['swg' => '28', 'mm' => '0.376'],
            ['swg' => '29', 'mm' => '0.345'],
            ['swg' => '30', 'mm' => '0.315'],
            ['swg' => '31', 'mm' => '0.295'],
            ['swg' => '32', 'mm' => '0.274'],
            ['swg' => '33', 'mm' => '0.254'],
            ['swg' => '34', 'mm' => '0.234'],
            ['swg' => '35', 'mm' => '0.213'],
            ['swg' => '36', 'mm' => '0.193'],
            ['swg' => '37', 'mm' => '0.173'],
            ['swg' => '38', 'mm' => '0.152'],
            ['swg' => '39', 'mm' => '0.132'],
            ['swg' => '40', 'mm' => '0.122'],
        ];
    }

    /**
     * The deep-groove ball bearings that actually turn up in motor end shields.
     *
     * Dimensions are the ISO standard ones, in millimetres: bore, outside
     * diameter, width. ZZ is metal shielded and 2RS rubber sealed — the same
     * bearing either way, which is why the suffix is explained on the page
     * rather than listed as a separate row.
     *
     * @return list<array{ref: string, bore: string, outer: string, width: string}>
     */
    public static function bearings(): array
    {
        return [
            ['ref' => '6201', 'bore' => '12', 'outer' => '32', 'width' => '10'],
            ['ref' => '6202', 'bore' => '15', 'outer' => '35', 'width' => '11'],
            ['ref' => '6203', 'bore' => '17', 'outer' => '40', 'width' => '12'],
            ['ref' => '6204', 'bore' => '20', 'outer' => '47', 'width' => '14'],
            ['ref' => '6205', 'bore' => '25', 'outer' => '52', 'width' => '15'],
            ['ref' => '6206', 'bore' => '30', 'outer' => '62', 'width' => '16'],
            ['ref' => '6207', 'bore' => '35', 'outer' => '72', 'width' => '17'],
            ['ref' => '6208', 'bore' => '40', 'outer' => '80', 'width' => '18'],
            ['ref' => '6303', 'bore' => '17', 'outer' => '47', 'width' => '14'],
            ['ref' => '6304', 'bore' => '20', 'outer' => '52', 'width' => '15'],
            ['ref' => '6305', 'bore' => '25', 'outer' => '62', 'width' => '17'],
            ['ref' => '6306', 'bore' => '30', 'outer' => '72', 'width' => '19'],
            ['ref' => '6307', 'bore' => '35', 'outer' => '80', 'width' => '21'],
            ['ref' => '6308', 'bore' => '40', 'outer' => '90', 'width' => '23'],
        ];
    }
}
