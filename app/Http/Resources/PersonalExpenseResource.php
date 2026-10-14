<?php

namespace App\Http\Resources;

use App\Models\PersonalExpense;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One personal diary entry. The amount is a decimal string, never a JSON
 * number, for the reason every amount in this API is.
 *
 * @mixin PersonalExpense
 */
class PersonalExpenseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'date' => $this->date?->toDateString(),
            'category_id' => $this->category_id,
            'category' => $this->whenLoaded('category', fn () => $this->category?->name),
            'amount' => (string) $this->amount,
            'paid_by' => $this->paid_by?->value,
            'paid_by_label' => $this->paid_by?->label(),
            'notes' => $this->notes,
            'created_by' => $this->whenLoaded('author', fn () => $this->author?->name),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
