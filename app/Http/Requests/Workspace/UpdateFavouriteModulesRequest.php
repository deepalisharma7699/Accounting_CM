<?php

namespace App\Http\Requests\Workspace;

use App\Support\Modules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An owner choosing which module cards sit at the top of the workshop's home
 * screen.
 *
 * ## Why this is not a field on UpdateWorkspaceRequest
 *
 * Two reasons, and the second is the load-bearing one.
 *
 * It is a *replacement* rather than a patch. Every other field on that request
 * is edited on the Settings screen and sent only when the owner touched it —
 * which is the whole point of `payload()` there. This is one list, starred from
 * the cards themselves, and what is sent is always the complete list: "the
 * favourites are now these". A PATCH semantic on an array would leave no way to
 * express "none", because an absent key and an empty one would have to mean
 * different things.
 *
 * And it has to announce nothing. `PATCH /workspace` tells the data bus that
 * `ledger` moved, correctly — the financial year and `books_start_date` on that
 * screen decide what every held report *means*. Starring a card moves nothing at
 * all, and sending it down that path would mark every held statement and every
 * Insights panel stale on each click. See the row for this path in
 * resources/js/data-bus.js, which sits ahead of `/workspace` on purpose.
 *
 * The authority is the same either way — UPDATE:WORKSPACE, enforced on the
 * route. This is the workshop's own home screen, so the person who configures
 * the workshop configures it.
 */
class UpdateFavouriteModulesRequest extends FormRequest
{
    /**
     * How many cards may sit above the grid.
     *
     * A cap rather than none, because the feature is "the few I use every day":
     * a favourites row holding fourteen of the nineteen cards is a second copy
     * of the grid above the grid, which is worse than the tall grid it was asked
     * for to fix. Eight is two rows on a phone and one on anything larger.
     */
    public const MAX = 8;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'favourite_modules' => ['present', 'array', 'max:'.self::MAX],
            /*
            | Checked against the registry, exactly as the fragment route checks
            | the key in its URL. Not merely tidiness: an unchecked key would sit
            | in the column for ever matching no card, so the cap would be spent
            | on a favourite that can never appear.
            */
            'favourite_modules.*' => ['string', Rule::in(array_keys(Modules::all()))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'favourite_modules.max' => 'Up to '.self::MAX.' cards can be starred. Remove one first.',
            'favourite_modules.*.in' => 'That is not a module on the home screen.',
        ];
    }

    /**
     * The list, de-duplicated and re-indexed.
     *
     * `array_values` is not cosmetic: a list with a hole in it encodes to a JSON
     * *object* rather than an array, and the column would come back as
     * `{"0":"sales","2":"stock"}` — which every reader here would then have to
     * tolerate.
     *
     * @return array<int, string>
     */
    public function favourites(): array
    {
        /** @var array<int, string> $keys */
        $keys = $this->input('favourite_modules', []);

        return array_values(array_unique(array_map('strval', $keys)));
    }
}
