<?php

namespace App\Http\Resources;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One entry on the trail — M13.
 *
 * The actor is sent as the *copy taken at the time*, with the live user
 * alongside it only where they still exist. A client that showed the current
 * name would rewrite history every time somebody changed theirs, and would show
 * an empty cell for everybody who has left.
 *
 * ## Why this reads `$entry` and not `$this`
 *
 * Every other resource in this application proxies straight through
 * `$this->column`. This one cannot, and the reason is a genuine name collision:
 * `JsonResource::$resource` is the framework's own property holding the wrapped
 * model, and `audit_logs` has a column called `resource` too. `$this->resource`
 * therefore returns the AuditLog rather than the kind of record it describes,
 * and `$this->resource->value` reaches `Model::__call` and explodes.
 *
 * Renaming the column to dodge it was the alternative and it is the worse one —
 * `resource` is the right word, it appears in the query string, in the enum and
 * in the index, and bending the schema around one framework property would leave
 * every one of those slightly wrong. Taking the model out into a local once,
 * here, is the smaller price.
 *
 * @mixin AuditLog
 */
class AuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var AuditLog $entry */
        $entry = $this->resource;

        return [
            'id' => $entry->id,
            'at' => $entry->created_at?->toIso8601String(),

            'action' => $entry->action->value,
            'action_label' => $entry->action->label(),

            'resource' => $entry->resource->value,
            'resource_label' => $entry->resource->label(),
            'resource_id' => $entry->resource_id,
            // Where the screens can link back to. Null where no page addresses
            // one record — see AuditResource::route().
            'resource_route' => $entry->resource->route(),

            // What the record was called when it was touched, not what it is
            // called now. An account renamed from "Petty Cash" to "Cash in Hand"
            // leaves a trail that still reads as it read then.
            'label' => $entry->label,

            'actor' => [
                'id' => $entry->actor_id,
                'name' => $entry->actorLabel(),
                // Null once the user has been deleted — which is the case the
                // copied name exists for, so the row stays readable either way.
                'email' => $entry->relationLoaded('actor') ? $entry->actor?->email : null,
                'exists' => $entry->actor_id !== null
                    && $entry->relationLoaded('actor')
                    && $entry->actor !== null,
            ],

            // A list rather than the stored map, sorted by field name: MySQL's
            // JSON type re-orders keys, so the map's own order is arbitrary.
            // Empty for a creation, which carries no snapshot by design — the
            // record itself is one.
            'changes' => $entry->changedFields(),

            'context' => $entry->context,
        ];
    }
}
