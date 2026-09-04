<?php

namespace App\Support\Units;

/**
 * One unit, resolved: what it is called, what it prints as, and whether a part of
 * one means anything.
 *
 * ## Why this exists rather than the Eloquent model
 *
 * `$item->base_uom` is read on every row of every listing, every bill line and
 * every stock movement. Returning a model would mean a query — or a relation to
 * remember to eager-load — for a fact that changes about once a year and fits in
 * memory whole. {@see UnitRegistry} loads the workshop's units once per request
 * and hands out these.
 *
 * ## Why the surface is exactly the old enum's
 *
 * This replaces `App\Enums\UnitOfMeasure`, and it is deliberately shaped so that
 * every call site already written against that enum — `->value`, `->label()`,
 * `->symbol()`, `->isFractional()`, `->quantityScale()` — keeps working with no
 * edit at all. The catalogue's vocabulary moved from code into a table; what the
 * rest of the application asks of a unit did not change.
 */
final class UnitDefinition implements \Stringable
{
    /**
     * @param  string  $value  The stored code — 'piece', 'kg'. Named `value` and
     *                         not `code` so `$item->base_uom->value` still reads
     *                         the way it did when this was an enum.
     * @param  int  $decimals  The places a quantity in this unit is recorded to,
     *                         and the whole of the fractional rule.
     */
    public function __construct(
        public readonly string $value,
        public readonly string $label,
        public readonly string $symbol,
        public readonly string $kind = 'other',
        public readonly int $decimals = 0,
        public readonly bool $isKnown = true,
    ) {}

    /**
     * The stored code, so anything that stringifies a unit gets what the column
     * holds rather than a class name.
     *
     * Chosen over the symbol deliberately: the audit trail passes attribute
     * values through `(string)`, and a snapshot reading "pc" would not match the
     * `piece` in `items.base_uom` that it is a record of. The symbol is what
     * {@see symbol()} is for, and every display path asks for it by name.
     */
    public function __toString(): string
    {
        return $this->value;
    }

    public function label(): string
    {
        return $this->label;
    }

    /**
     * The short form a bill line and a stock report print.
     */
    public function symbol(): string
    {
        return $this->symbol;
    }

    /**
     * Whether a part of one is a meaningful quantity.
     *
     * 2.5 kg of copper is ordinary; 2.5 bearings is a mistake somebody should be
     * told about before it reaches the stock ledger. One fact, derived from the
     * scale rather than stored beside it, so the two can never disagree.
     */
    public function isFractional(): bool
    {
        return $this->decimals > 0;
    }

    /**
     * The decimal places a quantity in this unit is recorded to.
     *
     * `stock_movements.quantity` is a single DECIMAL(15,3), so the scale is
     * uniform in the database; this is what *validation* allows, which is the
     * layer where a fractional bearing should be caught.
     */
    public function quantityScale(): int
    {
        return $this->decimals;
    }

    /**
     * A unit whose code is not in the workshop's list.
     *
     * Reachable in one situation: a document posted long ago naming a unit the
     * workshop has since removed. `UnitService` refuses to delete a unit anything
     * points at, so this is the belt to that braces — and it echoes the code
     * rather than inventing a label, because "kg" printed on an old invoice is
     * still more use to a reader than "Unknown".
     *
     * Permissive on scale, deliberately. An unrecognised unit should not be the
     * reason a legitimate quantity is refused; the guard against a fractional
     * bearing is the unit that actually exists, not this one.
     */
    public static function unknown(string $code): self
    {
        return new self(
            value: $code,
            label: $code,
            symbol: $code,
            kind: 'other',
            decimals: 3,
            isKnown: false,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'value' => $this->value,
            'label' => $this->label,
            'symbol' => $this->symbol,
            'kind' => $this->kind,
            'decimals' => $this->decimals,
            'is_fractional' => $this->isFractional(),
        ];
    }
}
