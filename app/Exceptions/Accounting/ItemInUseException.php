<?php

namespace App\Exceptions\Accounting;

use App\Exceptions\ApiException;

/**
 * An attempt to delete an item something still points at.
 *
 * The same rule as an account and a party, for the same reason: a bill line whose
 * item vanished loses the name that explains it. "3 × 4,200" is a number; "3 ×
 * Copper Winding Wire 22 SWG" is a record.
 *
 * Two things point at an item. Its own variants are deleted with it — a variant
 * has no meaning apart from its family — so that refusal is about not throwing
 * away somebody's work unasked. M8's stock movements are the harder one: they
 * are the workshop's record of what it bought and sold, and `restrictOnDelete`
 * on `stock_movements` backs the refusal for anything that does not come through
 * the service.
 */
class ItemInUseException extends ApiException
{
    /**
     * An item that has been bought or sold.
     *
     * Not a typo caught early — a record with history behind it. The message
     * says what archiving actually preserves, because "you cannot delete this"
     * without an alternative is a dead end.
     */
    public static function hasStockHistory(int $id, string $name, int $movements): self
    {
        return new self(
            message: sprintf(
                '%s has %d stock movement%s behind it. Deleting it would leave those quantities with nothing '.
                'to explain them — archive it instead if you have stopped dealing in it, and the history stays '.
                'readable.',
                $name,
                $movements,
                $movements === 1 ? '' : 's',
            ),
            status: 409,
            errorCode: 'ITEM_IN_USE',
            details: [
                'item_id' => $id,
                'movement_count' => $movements,
                'archive_instead' => true,
            ],
        );
    }

    /**
     * An item that appears on a bill.
     *
     * Not the same check as the stock one, and not redundant with it: an hour of
     * labour is billed and moves no stock at all, so a service item can have a
     * whole year of invoices behind it and no movements whatsoever.
     */
    public static function hasBillLines(int $id, string $name, int $lines): self
    {
        return new self(
            message: sprintf(
                '%s appears on %d bill line%s. Deleting it would leave those invoices with nothing to explain '.
                'them — archive it instead if you have stopped offering it, and everything already billed '.
                'stays readable.',
                $name,
                $lines,
                $lines === 1 ? '' : 's',
            ),
            status: 409,
            errorCode: 'ITEM_IN_USE',
            details: [
                'item_id' => $id,
                'bill_line_count' => $lines,
                'archive_instead' => true,
            ],
        );
    }

    /**
     * A variant that has been bought or sold. Same rule, one level down.
     */
    public static function variantHasStockHistory(int $id, string $label): self
    {
        return new self(
            message: sprintf(
                '%s has stock movements behind it. Deleting it would leave those quantities with nothing to '.
                'explain them — archive the variant instead, and everything already recorded stays intact.',
                $label,
            ),
            status: 409,
            errorCode: 'ITEM_VARIANT_IN_USE',
            details: [
                'variant_id' => $id,
                'archive_instead' => true,
            ],
        );
    }

    public static function hasVariants(int $id, string $name, int $count): self
    {
        return new self(
            message: sprintf(
                '%s has %d variant%s. Deleting the item would delete %s too — archive it instead if you have '.
                'stopped dealing in it, and everything already recorded stays intact.',
                $name,
                $count,
                $count === 1 ? '' : 's',
                $count === 1 ? 'that' : 'them all',
            ),
            status: 409,
            errorCode: 'ITEM_IN_USE',
            details: [
                'item_id' => $id,
                'variant_count' => $count,
                // The alternative, named: an error that refuses without saying
                // what to do instead is a dead end.
                'archive_instead' => true,
            ],
        );
    }
}
