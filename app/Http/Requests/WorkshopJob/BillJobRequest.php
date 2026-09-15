<?php

namespace App\Http\Requests\WorkshopJob;

use App\Enums\PaymentMode;
use App\Http\Requests\Transaction\Concerns\CarriesClientRef;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Raising the invoice off a job — the last step of §16 to §18.
 *
 * Everything is optional. Sending `{}` bills the job exactly as its unbilled
 * parts stand, which is what the "Generate bill" button on the job screen does;
 * everything here is an override for the operator standing in front of the
 * customer, who has better information than a job card written last week.
 *
 * ## Why `items` is accepted at all
 *
 * Because the counter screen is where a bill is finally agreed, and a price that
 * could not be changed there would mean the bill was written twice — once
 * properly and once as a "miscellaneous adjustment" line. It has a real cost,
 * stated where it lands: replacing the lines wholesale breaks the pairing
 * between parts and invoice lines, so {@see \App\Services\Workshop\JobService::bill()}
 * marks nothing as billed. That is the safe way to be wrong — the parts stay
 * visible on the job rather than a bearing silently disappearing off it.
 *
 * The `client_ref` is the same one every other document carries — a retry after
 * a timeout must not turn one repair into two invoices.
 */
class BillJobRequest extends FormRequest
{
    use CarriesClientRef;

    /** Matches DECIMAL(15, 2). */
    private const MAX_AMOUNT = '9999999999999.99';

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
            'date' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:500'],

            ...$this->clientRefRules(),

            // Absent means "bill the job as it stands", which is the normal case.
            'items' => ['sometimes', 'array', 'min:1', 'max:50'],
            'items.*.item_id' => ['nullable', 'integer', 'min:1'],
            'items.*.variant_id' => ['nullable', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'numeric', 'decimal:0,3', 'gt:0'],
            'items.*.unit_price' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:'.self::MAX_AMOUNT],
            'items.*.discount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:'.self::MAX_AMOUNT],
            'items.*.discount_percent' => [
                'nullable', 'numeric', 'min:0', 'max:100', 'prohibits:items.*.discount',
            ],
            'items.*.price_includes_tax' => ['nullable', 'boolean'],
            'items.*.memo' => ['nullable', 'string', 'max:255'],

            // Money off the whole repair, apportioned across the lines before
            // tax exactly as it is on any other bill.
            'bill_discount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:'.self::MAX_AMOUNT],
            'bill_discount_percent' => [
                'nullable', 'numeric', 'min:0', 'max:100', 'prohibits:bill_discount',
            ],

            // What was collected as the motor went out. Optional, like any bill's:
            // a regular customer's repair goes out on account.
            'payments' => ['nullable', 'array', 'max:20'],
            'payments.*.mode' => ['required', Rule::enum(PaymentMode::class)],
            'payments.*.amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:'.self::MAX_AMOUNT],
            'payments.*.reference' => ['nullable', 'string', 'max:100'],

            /*
            | Who did the work - M22, and a rewind is the canonical case for it.
            | "Ramesh fitted it, Sunil wound it" is a sentence about a job before
            | it is one about a counter sale, and until C4 this endpoint dropped
            | it silently because the only screen that could reach it sent the
            | shared bill document's whole payload and this class named none of
            | these keys.
            |
            | Shape only, as everywhere else: which trades this workshop asks
            | about and whether the person is on its staff list belong to
            | WorkAttributionService.
            */
            'staff' => ['nullable', 'array', 'max:10'],
            'staff.*.designation_id' => ['required', 'integer', 'min:1'],
            // Nullable, and the null is load-bearing - it means "this box is
            // empty", which a correction has to be able to say.
            'staff.*.employee_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.*.discount_percent.prohibits' => 'Give a line discount in rupees or as a percentage, not both.',
            'bill_discount_percent.prohibits' => 'Give the bill discount in rupees or as a percentage, not both.',
        ];
    }

    /**
     * Only what was actually sent, so an absent key means "use the job's own"
     * rather than "use nothing".
     *
     * @return array<string, mixed>
     */
    public function overrides(): array
    {
        $overrides = [];

        if ($this->filled('date')) {
            $overrides['date'] = (string) $this->string('date');
        }

        if ($this->filled('notes')) {
            $overrides['notes'] = trim((string) $this->string('notes'));
        }

        if ($this->clientRef() !== null) {
            $overrides['client_ref'] = $this->clientRef();
        }

        if ($this->has('items')) {
            $overrides['items'] = array_map(fn (array $line) => [
                'item_id' => ($line['item_id'] ?? null) === '' ? null : ($line['item_id'] ?? null),
                'variant_id' => ($line['variant_id'] ?? null) === '' ? null : ($line['variant_id'] ?? null),
                'quantity' => $line['quantity'] ?? 0,
                'unit_price' => $line['unit_price'] ?? 0,
                'discount' => ($line['discount'] ?? null) === '' ? null : ($line['discount'] ?? null),
                'discount_percent' => ($line['discount_percent'] ?? null) === ''
                    ? null
                    : ($line['discount_percent'] ?? null),
                /*
                | Absent means "ask the item", which is what the job's own parts
                | rely on - billPayloadFor() sends no flag at all. Sent
                | explicitly it wins, because a shop selling parts at the price
                | printed on the box still quotes a rewind before tax.
                */
                'price_includes_tax' => array_key_exists('price_includes_tax', $line)
                    && $line['price_includes_tax'] !== null && $line['price_includes_tax'] !== ''
                    ? filter_var($line['price_includes_tax'], FILTER_VALIDATE_BOOLEAN)
                    : null,
                'memo' => $line['memo'] ?? null,
            ], array_values((array) $this->input('items', [])));
        }

        foreach (['bill_discount', 'bill_discount_percent'] as $field) {
            if ($this->filled($field)) {
                $overrides[$field] = $this->input($field);
            }
        }

        if ($this->has('staff')) {
            $overrides['staff'] = array_map(fn (array $pair) => [
                'designation_id' => $pair['designation_id'] ?? null,
                'employee_id' => ($pair['employee_id'] ?? null) === '' ? null : ($pair['employee_id'] ?? null),
            ], array_values((array) $this->input('staff', [])));
        }

        if ($this->has('payments')) {
            $overrides['payments'] = array_map(fn (array $split) => [
                'mode' => $split['mode'] ?? null,
                'amount' => $split['amount'] ?? 0,
                'reference' => $split['reference'] ?? null,
            ], array_values((array) $this->input('payments', [])));
        }

        return $overrides;
    }
}
