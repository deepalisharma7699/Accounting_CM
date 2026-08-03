<?php

namespace App\Http\Resources;

use App\Models\JournalEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One ledger line.
 *
 * Amounts go out as decimal *strings*. A JSON number is parsed back into a
 * binary float by every client that receives it, which is how ₹0.10 becomes
 * 0.09999999999999998 in a browser and a total ends up one paisa out.
 *
 * @mixin JournalEntry
 */
class JournalEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'transaction_id' => $this->transaction_id,
            'line_no' => $this->line_no,
            'date' => $this->date->toDateString(),

            'account_id' => $this->account_id,
            'account' => $this->whenLoaded('account', fn () => [
                'id' => $this->account->id,
                'code' => $this->account->code,
                'name' => $this->account->name,
                'type' => $this->account->type->value,
                'normal_balance' => $this->account->normalBalance()->value,
            ]),

            'debit' => $this->debitMoney()->amount(),
            'credit' => $this->creditMoney()->amount(),
            'side' => $this->side()->value,
            'memo' => $this->memo,

            // Present only on a ledger read, where the service has walked the
            // entries in order — a per-row serialiser cannot compute a number
            // that depends on every entry before it.
            'running_balance' => $this->whenNotNull($this->getAttribute('running_balance')),

            'transaction' => $this->whenLoaded('transaction', fn () => [
                'id' => $this->transaction->id,
                'type' => $this->transaction->type->value,
                'status' => $this->transaction->status->value,
                'source' => $this->transaction->source->value,
                'notes' => $this->transaction->notes,
            ]),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
