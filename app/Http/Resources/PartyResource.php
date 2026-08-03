<?php

namespace App\Http\Resources;

use App\Enums\PartyRole;
use App\Models\Party;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A party, and — when the caller asked for it and is allowed to see it — what
 * they owe.
 *
 * The position is attached to the model by the controller rather than computed
 * here, because it takes one query for a whole page and a resource can only see
 * one row at a time. Its absence is meaningful: `outstanding` is null when it
 * was not fetched, and a zero-filled object when the party genuinely owes
 * nothing. Reporting the first as the second would tell a reader the account is
 * settled when nobody looked.
 *
 * @mixin Party
 */
class PartyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,

            'roles' => $this->roles ?? [],
            'role_labels' => array_map(
                static fn (PartyRole $role) => $role->label(),
                $this->roleSet(),
            ),
            'is_customer' => $this->isCustomer(),
            'is_vendor' => $this->isVendor(),

            'gstin' => $this->gstin,
            'state_code' => $this->state_code,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'notes' => $this->notes,

            'is_active' => $this->is_active,

            // Amounts are decimal strings, never JSON numbers — a JSON number
            // is parsed straight back into a float by every client that
            // receives it. See Money.
            'outstanding' => $this->resource->outstanding ?? null,

            // What has gone through the relationship rather than what is left
            // of it: billed and received against a customer, purchased and paid
            // against a vendor. Same rows as `outstanding`, summed gross rather
            // than netted, so the two can never tell different stories.
            'lifetime' => $this->resource->lifetime ?? null,

            // When they were last dealt with. Null throughout for a party
            // nobody has traded with, and null as a whole when the caller did
            // not ask — `with_activity=1`.
            'activity' => $this->resource->activity ?? null,

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
