<?php

namespace App\Http\Resources;

use App\Models\StaffDesignation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the Designation Master — M22.
 *
 * `employee_count` is attached by the service rather than counted here, because
 * a resource sees one row at a time and counting per row would be a query per
 * designation. Its absence is meaningful: null means nobody asked, and zero
 * means nobody holds it — which is the difference between "we do not know
 * whether this can be archived" and "it can".
 *
 * @mixin StaffDesignation
 */
class StaffDesignationResource extends JsonResource
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

            // Whether a sale asks for this trade by name — M22. Published rather
            // than assumed, because the sale form draws one picker per row that
            // carries it and knows nothing else about what the trades are.
            'track_on_sales' => $this->track_on_sales,
            'employee_count' => $this->resource->employee_count ?? null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
