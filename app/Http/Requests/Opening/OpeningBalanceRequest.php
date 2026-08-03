<?php

namespace App\Http\Requests\Opening;

use App\Enums\ItemType;
use App\Enums\OpeningRowKind;
use App\Services\Onboarding\OpeningBalanceService;
use App\Services\Onboarding\OpeningCsvParser;
use App\Services\Onboarding\OpeningRow;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * A go-live declaration, arriving either as a pasted file or as typed rows.
 *
 * Two shapes on one request, because they are the same thing said two ways: the
 * screen offers a spreadsheet upload for a workshop that has one and a grid for
 * a workshop that does not, and both end up as {@see OpeningRow}s before
 * anything looks at them. Validating them separately would mean two vocabularies
 * for one document.
 *
 * **Shape only.** Whether an item exists, whether a party already has an opening
 * balance, whether a quantity is legal for its unit and whether the resulting
 * entry balances are all {@see OpeningBalanceService}'s business — the plan is
 * rendered from the same resolution that commits it, and a rule enforced here
 * would apply to neither the preview nor M15's future capture path.
 */
class OpeningBalanceRequest extends FormRequest
{
    /** Matches DECIMAL(15, 2). */
    private const MAX_AMOUNT = '9999999999999.99';

    /**
     * A cap on the file, not on the workshop.
     *
     * Generous — a workshop with more than a thousand opening declarations has a
     * stock system already and is not this product's onboarding case — and it is
     * here rather than in the service because it is a request-size limit, which
     * is exactly what a form request is for.
     */
    private const MAX_ROWS = 2000;

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
            // Optional: it defaults to the workshop's books_start_date, which is
            // what an opening balance is as at. Stated explicitly where a
            // workshop has not set one yet.
            'date' => ['nullable', 'date_format:Y-m-d'],
            'filename' => ['nullable', 'string', 'max:255'],

            // The whole file, pasted or uploaded and read into a string by the
            // controller. 4 MB of CSV is far more than MAX_ROWS of declarations.
            'csv' => ['nullable', 'string', 'max:4194304'],

            'rows' => ['nullable', 'array', 'max:'.self::MAX_ROWS],
            'rows.*.kind' => ['required', 'string', 'in:'.implode(',', OpeningRowKind::values())],
            'rows.*.name' => ['nullable', 'string', 'max:200'],
            'rows.*.variant' => ['nullable', 'string', 'max:200'],
            'rows.*.type' => ['nullable', 'string', 'in:'.implode(',', ItemType::values())],
            'rows.*.quantity' => ['nullable', 'numeric', 'decimal:0,3'],
            'rows.*.unit_cost' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:'.self::MAX_AMOUNT],
            'rows.*.amount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:'.self::MAX_AMOUNT],
            'rows.*.account' => ['nullable', 'string', 'max:120'],
            'rows.*.side' => ['nullable', 'string', 'in:debit,credit'],
            'rows.*.gstin' => ['nullable', 'string', 'max:15'],
            'rows.*.reference' => ['nullable', 'string', 'max:180'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->filled('csv') && ! $this->filled('rows')) {
                $validator->errors()->add(
                    'rows',
                    'Send either a file to read or the rows themselves — there is nothing here to declare.',
                );
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rows.*.kind.in' => 'Each row has to say what it declares: stock, receivable, payable or balance.',
            'rows.*.quantity.decimal' => 'Quantities go to three decimal places at most.',
            'date.date_format' => 'Give the go-live date as YYYY-MM-DD.',
            'rows.max' => 'That is more than :max rows. Split the file, or ask about a bulk load.',
        ];
    }

    /**
     * The declarations, whichever way they arrived, each with the line number a
     * message should quote.
     *
     * Typed rows are numbered from 2 rather than from 1, so that "row 4" means
     * the same thing whether the user is looking at a spreadsheet — where line 1
     * is the header — or at the grid on the screen, whose first data row is
     * shown as row 2 for exactly this reason.
     *
     * @return array<int, array{0: int, 1: OpeningRow}>
     */
    public function declarations(OpeningCsvParser $parser): array
    {
        if ($this->filled('csv')) {
            return $parser->parse((string) $this->input('csv'));
        }

        $rows = [];

        foreach (array_values((array) $this->input('rows', [])) as $index => $row) {
            $parsed = OpeningRow::from((array) $row);

            if ($parsed->isBlank()) {
                continue;
            }

            $rows[] = [$index + 2, $parsed];
        }

        return $rows;
    }

    /**
     * The go-live date, or null to take the workshop's own.
     *
     * Named `declaredOn` rather than `date`, because `Request::date()` already
     * exists with a different signature and overriding it would break every
     * other caller of it in the framework.
     */
    public function declaredOn(): ?string
    {
        return $this->filled('date') ? (string) $this->string('date') : null;
    }

    public function filename(): ?string
    {
        return $this->filled('filename') ? trim((string) $this->string('filename')) : null;
    }
}
