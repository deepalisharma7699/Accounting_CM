<?php

namespace App\Http\Resources;

use App\Models\ChartOfAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ChartOfAccount
 */
class ChartOfAccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            // Which side increases this account. Sent so the back-office can
            // render a ledger without re-implementing the rule.
            'normal_balance' => $this->normalBalance()->value,
            'is_balance_sheet' => $this->type->isBalanceSheet(),
            // A stable handle for the engine's accounts — clients can key off
            // it, and it is null for accounts the workshop added itself.
            'system_key' => $this->system_key?->value,
            'is_system' => $this->isSystem(),
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
