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
            'items.*.memo' => ['nullable', 'string', 'max:255'],

            // What was collected as the motor went out. Optional, like any bill's:
            // a regular customer's repair goes out on account.
            'payments' => ['nullable', 'array', 'max:20'],
            'payments.*.mode' => ['required', Rule::enum(PaymentMode::class)],
            'payments.*.amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:'.self::MAX_AMOUNT],
            'payments.*.reference' => ['nullable', 'string', 'max:100'],
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
                'memo' => $line['memo'] ?? null,
            ], array_values((array) $this->input('items', [])));
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
