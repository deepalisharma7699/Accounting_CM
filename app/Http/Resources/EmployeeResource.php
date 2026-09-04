<?php

namespace App\Http\Resources;

use App\Models\Employee;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One member of staff — M22.
 *
 * ## The two attached figures, and why their absence means something
 *
 * `advance` is what is currently out with this person, and `attendance` is how
 * their month has gone. Both are attached to the model by the controller rather
 * than computed here, because each takes one query for a whole page and a
 * resource can only see one row at a time.
 *
 * Both are **null when nobody asked**, and that is deliberate. A zeroed advance
 * on a page that never fetched one would tell a reader the account is clear when
 * in fact nothing was looked at — the same mistake `PartyResource` refuses to
 * make with `outstanding`, and the same reason.
 *
 * @mixin Employee
 */
class EmployeeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,

            'designation_id' => $this->designation_id,
            'designation' => $this->whenLoaded(
                'designation',
                fn () => $this->designation === null ? null : [
                    'id' => $this->designation->id,
                    'name' => $this->designation->name,
                ],
            ),

            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'notes' => $this->notes,

            'salary_basis' => $this->salary_basis->value,
            'salary_basis_label' => $this->salary_basis->label(),
            'salary_basis_short' => $this->salary_basis->shortLabel(),
            'rate_label' => $this->salary_basis->rateLabel(),
            // A decimal string, never a JSON number — a JSON number is parsed
            // straight back into a float by every client that receives it. See
            // Money.
            'pay_rate' => Money::of($this->pay_rate ?? 0)->amount(),

            'joined_on' => $this->joined_on?->toDateString(),
            'left_on' => $this->left_on?->toDateString(),
            'has_left' => $this->hasLeft(),

            'is_active' => $this->is_active,

            // `{paid, recovered, outstanding}`, or null where the caller did not
            // ask — `with_advances=1`. See the class note.
            'advance' => $this->resource->advance ?? null,

            // How many days of each status were marked in the period the caller
            // asked about, plus the period itself so a client can label the
            // column without assuming which month it got.
            'attendance' => $this->resource->attendance ?? null,

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
