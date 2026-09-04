<?php

namespace App\Http\Resources;

use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One line of a stock card.
 *
 * `balance_quantity` and `balance_value` are the position *after* this movement,
 * supplied by {@see \App\Services\Inventory\StockLedgerService::cardFor()} rather
 * than stored on the row — a running balance in a column would be wrong the
 * moment a back-dated movement was inserted before it.
 *
 * @mixin StockMovement
 */
class StockMovementResource extends JsonResource
{
    /**
     * @param  array<string, mixed>  $balance
     */
    public function __construct(StockMovement $movement, private readonly array $balance = [])
    {
        parent::__construct($movement);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'date' => $this->date->toDateString(),

            'type' => $this->type->value,
            'type_label' => $this->type->label(),

            // What the type cannot say on its own — a purchase reversal, which is
            // stored as an `adjust` and was otherwise indistinguishable from a
            // physical count. Null on every other movement, so a card that reads
            // `source_label ?? type_label` is unchanged everywhere else.
            'source_label' => $this->whenLoaded('transaction', fn () => $this->sourceLabel()),

            // Signed, exactly as stored: the direction is the sign, and a client
            // that had to reconstruct it from the type would get `adjust` wrong.
            'quantity' => $this->quantityValue()->amount(),
            'unit_cost' => $this->unitCostMoney()->amount(),
            'value' => $this->valueMoney()->amount(),

            'memo' => $this->memo,

            'transaction' => $this->whenLoaded('transaction', fn () => $this->transaction === null ? null : [
                'id' => $this->transaction->id,
                // The number people actually quote to each other. Without it a
                // stock card could name the document only by a database id,
                // which is not what is printed on the paper in their hand.
                'doc_no' => $this->transaction->doc_no,
                'type' => $this->transaction->type->value,
                'type_label' => $this->transaction->type->label(),
                'status' => $this->transaction->status->value,
                // The document this one cancels, where it cancels one — what
                // makes a reversal followable back to what it reversed.
                'reverses_id' => $this->transaction->reverses_id,
                'notes' => $this->transaction->notes,
            ]),

            'balance_quantity' => $this->balance['quantity'] ?? null,
            'balance_value' => $this->balance['value'] ?? null,
            'balance_average_cost' => $this->balance['average_cost'] ?? null,
        ];
    }
}
