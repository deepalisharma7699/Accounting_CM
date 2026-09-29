<?php

namespace App\Http\Resources;

use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Role
 */
class RoleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'is_system_role' => (bool) $this->is_system_role,
            // null is a platform role — the platform administrator's own panel.
            // Anything else is one workshop's own, and reaches that workshop's
            // people and the platform acting inside it, nobody else.
            'tenant_id' => $this->tenant_id,
            'scope' => $this->tenant_id === null ? 'platform' : 'workshop',
            'tenant' => $this->whenLoaded(
                'tenant',
                fn () => $this->tenant === null ? null : [
                    'id' => $this->tenant->id,
                    'name' => $this->tenant->name,
                    'slug' => $this->tenant->slug,
                ],
            ),
            /*
            | Whether the caller may change it, so a screen never offers a
            | control the API would refuse. A caller only ever sees roles in
            | their own scope — the repository sees to that — so the whole
            | question reduces to whether this is a system role. Kept as its own
            | field rather than left for a screen to derive from
            | `is_system_role`, because what makes a role untouchable is the
            | API's to decide and the screen's to follow.
            */
            'editable' => ! $this->is_system_role,
            'permissions' => PermissionResource::collection($this->whenLoaded('permissions')),
            'users_count' => $this->whenCounted('users'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
