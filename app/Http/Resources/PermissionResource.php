<?php

namespace App\Http\Resources;

use App\Models\Permission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Permission
 */
class PermissionResource extends JsonResource
{
    /**
     * The one field on a permission whose name collides with the resource class.
     *
     * `$this->resource` on a JsonResource is the *wrapped model*, not a
     * forwarded attribute — the magic `__get` that makes `$this->action` work
     * never runs for a property the class already has. Written the obvious way,
     * this field emitted the whole Permission model, pivot row and timestamps
     * included: a client reading `permission.resource` got an object where a
     * name belongs, so grouping grants by resource produced one group per
     * permission and the `*`/`*` wildcard never matched (§6.3 as well — the
     * pivot is internal detail nobody asked for).
     *
     * Hence the local: the model is taken out of the property once, and every
     * field below is read off it rather than off `$this`.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Permission $permission */
        $permission = $this->resource;

        return [
            'id' => $permission->id,
            'action' => $permission->action,
            'resource' => $permission->resource,
            'key' => $permission->key(),
            'description' => $permission->description,
        ];
    }
}
