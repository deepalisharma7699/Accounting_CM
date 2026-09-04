<?php

namespace App\Http\Requests\Party;

use App\Enums\PartyRole;
use App\Models\Tenant;
use App\Services\Accounting\PartyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shape only. Whether the name is already taken, whether the GSTIN duplicates
 * another party's and what the state code should be are all
 * {@see PartyService}'s business — the same rules have
 * to hold for M11's importer and M15's capture agent, neither of which comes
 * through a form request.
 */
class StorePartyRequest extends FormRequest
{
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
            /*
            | A name that survives leaving the browser.
            |
            | The web UI escapes this correctly, so markup in it is not an
            | injection — it is a name that reads as `<script>alert(1)</script>`
            | on every statement, remittance advice and export the workshop ever
            | sends out, and one that a CSV or a PDF renderer is entitled to
            | treat differently from HTML. Control characters are worse: they
            | are invisible in the box that accepted them and corrupt the line
            | they land on.
            |
            | Refused rather than stripped, because silently saving a different
            | name than the one somebody typed is its own bug — and the
            | whitespace that is merely untidy has already been folded to
            | ordinary spaces in prepareForValidation().
            */
            'name' => ['required', 'string', 'min:2', 'max:150', 'not_regex:/[\x00-\x1F\x7F<>]/u'],

            // At least one role, because a party belonging to neither list
            // would accumulate a balance nobody could navigate to.
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => [Rule::enum(PartyRole::class)],

            // Structure, not checksum — see Tenant::GSTIN_PATTERN for why. Not
            // unique either: branches of one business share a GSTIN, and the
            // duplicate is reported back as a warning instead.
            'gstin' => ['nullable', 'string', 'size:15', 'regex:'.Tenant::GSTIN_PATTERN],

            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Every run of whitespace folded to one ordinary space before
        // the rules see the name: a pasted name is commonly untidy rather than
        // hostile, and refusing it for a line break nobody can see would be a
        // refusal nobody could act on.
        if ($this->filled('name')) {
            $this->merge([
                'name' => trim((string) preg_replace('/\s+/u', ' ', (string) $this->input('name'))),
            ]);
        }

        // Upper-cased before the pattern is applied, so a GSTIN typed in lower
        // case is accepted rather than failing a regex the user cannot see.
        if ($this->filled('gstin')) {
            $this->merge(['gstin' => strtoupper(trim((string) $this->input('gstin')))]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.not_regex' => 'A name cannot contain < or > or hidden control characters.',
            'roles.required' => 'Say whether this party is a customer, a vendor, or both.',
            'roles.min' => 'Say whether this party is a customer, a vendor, or both.',
            'gstin.size' => 'A GSTIN is exactly 15 characters.',
            'gstin.regex' => 'That does not look like a GSTIN — the format is 2 digits, then a PAN, then 3 characters.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'name' => trim((string) $this->string('name')),
            'roles' => array_values((array) $this->input('roles', [])),
            'gstin' => $this->filled('gstin') ? (string) $this->string('gstin') : null,
            'phone' => $this->filled('phone') ? trim((string) $this->string('phone')) : null,
            'email' => $this->filled('email') ? trim((string) $this->string('email')) : null,
            'address' => $this->filled('address') ? trim((string) $this->string('address')) : null,
            'notes' => $this->filled('notes') ? trim((string) $this->string('notes')) : null,
        ];
    }
}
