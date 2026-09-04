<?php

namespace App\Http\Resources;

use App\Models\Unit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the Unit Master.
 *
 * `is_fractional` is sent although it is derivable from `decimals`, because it is
 * the question every client actually asks — "may somebody type 2.5 here?" — and
 * a client deriving it would be a third place that knows the rule.
 *
 * `usage` is present only where the caller asked for it. It costs four counting
 * queries per unit, so the listing does without and the one screen where somebody
 * is deciding what to delete pays for it.
 *
 * @mixin Unit
 */
class UnitResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            // Write-once. Every quantity ever recorded points at it, so the API
            // sends it and never accepts a change to it.
            'code' => $this->code,

            'label' => $this->label,
            'symbol' => $this->symbol,
            'kind' => $this->kind,

            'decimals' => (int) $this->decimals,
            'is_fractional' => $this->isFractional(),

            // Seeded with the system. Renameable, archivable, never deletable —
            // the client greys the delete control rather than offering an action
            // that can only be refused.
            'is_system' => (bool) $this->is_system,
            'is_active' => (bool) $this->is_active,
            'display_order' => (int) $this->display_order,

            'usage' => $this->whenNotNull($this->usage ?? null),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
