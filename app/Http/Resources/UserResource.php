<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Services\Rbac\AuthorizationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * Whether to include the user's effective permission list. Off by default
     * so listing 100 users does not resolve 100 permission sets.
     */
    protected bool $withPermissions = false;

    public function withPermissions(bool $value = true): static
    {
        $this->withPermissions = $value;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'status' => $this->status->value,
            // The password hash is excluded at the model level (#[Hidden]) and
            // is never referenced here.
            'role' => $this->whenLoaded(
                'customRole',
                fn () => $this->customRole === null ? null : [
                    'id' => $this->customRole->id,
                    'name' => $this->customRole->name,
                    'slug' => $this->customRole->slug,
                    'is_system_role' => (bool) $this->customRole->is_system_role,
                ]
            ),
            'permissions' => $this->when(
                $this->withPermissions,
                fn () => app(AuthorizationService::class)->grantsForUser($this->resource)
            ),
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
