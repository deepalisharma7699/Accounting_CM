<?php

namespace App\Exceptions\Accounting;

use App\Exceptions\ApiException;

/**
 * More was billed than the shelf holds — M17, decision D6.
 *
 * A refusal rather than a warning, which reverses what
 * `docs/inventory-module.md` originally argued. The reasoning for the old rule
 * still stands for the workshop it described — one that bills on Tuesday and
 * enters the supplier's invoice on Friday — so the refusal is a *setting*
 * (`tenants.allow_negative_stock`) rather than a new absolute, and this
 * exception says so in as many words. Nobody should have to read the source to
 * find out there is a way through.
 *
 * The message is the brief's own: *"Only 5 PCS available in stock."*
 */
class InsufficientStockException extends ApiException
{
    public static function forVariant(
        string $label,
        string $available,
        string $unit,
        string $wanted,
    ): self {
        return new self(
            message: sprintf(
                'Only %s %s available in stock for %s, and %s was billed. Enter the purchase that brought '.
                'it in, or allow negative stock in Workspace settings.',
                $available,
                $unit,
                $label,
                $wanted,
            ),
            status: 422,
            errorCode: 'STOCK_INSUFFICIENT',
            details: [
                'variant' => $label,
                'available' => $available,
                'unit' => $unit,
                'requested' => $wanted,
            ],
        );
    }
}
