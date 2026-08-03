<?php

namespace App\Enums;

/**
 * How a thing is counted.
 *
 * A rewinding workshop deals in three genuinely different kinds of quantity, and
 * conflating them is how a stock ledger stops making sense: motors and bearings
 * are counted in whole pieces, copper wire and varnish are measured out
 * continuously, and labour is billed by time. The unit decides whether "2.5" is a
 * legitimate quantity or a typo — which is why {@see isFractional()} exists here
 * rather than as a rule scattered through M8's stock movements and M9's bill
 * lines.
 *
 * A unit is fixed once an item exists, for the same reason an account's type is:
 * changing "each" to "kilogram" would silently reinterpret every quantity ever
 * recorded against it.
 */
enum UnitOfMeasure: string
{
    /* Counted */
    case Piece = 'piece';
    case Set = 'set';
    case Coil = 'coil';

    /* Measured */
    case Kilogram = 'kg';
    case Metre = 'metre';
    case Litre = 'litre';

    /* Time */
    case Hour = 'hour';

    public function label(): string
    {
        return match ($this) {
            self::Piece => 'Piece',
            self::Set => 'Set',
            self::Coil => 'Coil',
            self::Kilogram => 'Kilogram',
            self::Metre => 'Metre',
            self::Litre => 'Litre',
            self::Hour => 'Hour',
        };
    }

    /**
     * The short form a bill line and a stock report print.
     */
    public function symbol(): string
    {
        return match ($this) {
            self::Piece => 'pc',
            self::Set => 'set',
            self::Coil => 'coil',
            self::Kilogram => 'kg',
            self::Metre => 'm',
            self::Litre => 'L',
            self::Hour => 'hr',
        };
    }

    /**
     * Whether a part of one is a meaningful quantity.
     *
     * 2.5 kg of copper is ordinary; 2.5 bearings is a mistake somebody should be
     * told about before it reaches the stock ledger. M8 and M9 both need this, so
     * it is stated once.
     */
    public function isFractional(): bool
    {
        return match ($this) {
            self::Kilogram, self::Metre, self::Litre, self::Hour => true,
            self::Piece, self::Set, self::Coil => false,
        };
    }

    /**
     * The decimal places a quantity in this unit is recorded to.
     *
     * `stock_movement.quantity` is a single DECIMAL column in M8, so the scale is
     * uniform in the database; this is what the *validation* allows, which is the
     * layer where a fractional bearing should be caught.
     */
    public function quantityScale(): int
    {
        return $this->isFractional() ? 3 : 0;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
