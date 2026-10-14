<?php

namespace App\Http\Resources;

use App\Models\JobKind;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One kind of thing a workshop takes in.
 *
 * @mixin JobKind
 */
class JobKindResource extends JsonResource
{
    /**
     * How many jobs are filed under it, attached by the controller rather than
     * counted here — one grouped query for the page instead of one per row.
     */
    public function __construct($resource, private readonly ?int $jobs = null)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'is_active' => (bool) $this->is_active,
            'is_system' => (bool) $this->is_system,
            'display_order' => (int) $this->display_order,

            /*
            | The question set, twice over, and each has one reader.
            |
            | `fields` is the editable list — every definition, inactive ones
            | included, because the master's own screen has to be able to switch
            | one back on. `attributes` is the same set resolved into the shape
            | `components/attribute-fields.js` draws, active only, which is what
            | the intake form reads. A screen that had to build the second from
            | the first would be a second implementation of the resolver.
            */
            'fields' => JobKindFieldResource::collection(
                $this->whenLoaded('fields', fn () => $this->resolvedAttributes(false)),
            ),
            'attributes' => (object) $this->attributeSchema(),

            'jobs' => $this->when($this->jobs !== null, $this->jobs),
        ];
    }
}
