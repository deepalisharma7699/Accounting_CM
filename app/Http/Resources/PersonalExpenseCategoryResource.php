<?php

namespace App\Http\Resources;

use App\Models\PersonalExpenseCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One personal category. `expense_count` is attached by the service; null
 * means nobody asked, zero means nothing is filed under it.
 *
 * @mixin PersonalExpenseCategory
 */
class PersonalExpenseCategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'is_active' => $this->is_active,
            'display_order' => $this->display_order,
            'expense_count' => $this->resource->expense_count ?? null,
        ];
    }
}
