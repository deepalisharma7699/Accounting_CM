<?php

namespace App\Services\Onboarding;

use App\Enums\OpeningRowKind;
use App\Support\Money;
use App\Support\Quantity;

/**
 * One line of a go-live declaration, exactly as it arrived.
 *
 * Nothing here has been resolved, matched or validated beyond its shape — this
 * is what the spreadsheet said, held as a value object so the parser and the API
 * hand the resolver the same thing. What it turns out to *mean* is
 * {@see PlannedRow}.
 *
 * Every field is a trimmed string or null, including the numbers. They are
 * parsed into {@see Money} and {@see Quantity} at the
 * point of use rather than here, so a malformed figure is reported as the row it
 * came from instead of failing the whole file.
 */
final class OpeningRow
{
    private function __construct(
        public readonly OpeningRowKind $kind,
        /** The item, the party, or nothing for an account balance. */
        public readonly ?string $name,
        /** The specification, for a stock row: "6204", "22 SWG", "5 HP / 3 ph / 1440". */
        public readonly ?string $variant,
        /**
         * The category the row names, needed only when the item is new.
         *
         * The raw text from the file rather than a resolved record: the column
         * used to hold one of four fixed words and now holds whatever the shop
         * calls its categories, so what is valid is a question for the database
         * and is asked in OpeningBalanceService.
         */
        public readonly ?string $categoryName,
        public readonly ?string $quantity,
        public readonly ?string $unitCost,
        public readonly ?string $amount,
        /** An account code or name, for a balance row: "1010" or "Cash in Hand". */
        public readonly ?string $account,
        public readonly ?string $side,
        public readonly ?string $gstin,
        public readonly ?string $reference,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function from(array $data): self
    {
        return new self(
            kind: OpeningRowKind::tryFrom(strtolower(trim((string) ($data['kind'] ?? '')))) ?? OpeningRowKind::Balance,
            name: self::text($data['name'] ?? null),
            variant: self::text($data['variant'] ?? null),
            categoryName: self::text($data['type'] ?? null),
            quantity: self::number($data['quantity'] ?? null),
            unitCost: self::number($data['unit_cost'] ?? null),
            amount: self::number($data['amount'] ?? null),
            account: self::text($data['account'] ?? null),
            side: self::text($data['side'] ?? null),
            gstin: self::upper($data['gstin'] ?? null),
            reference: self::text($data['reference'] ?? null),
        );
    }

    /**
     * Whether the `kind` cell named something this product understands.
     *
     * Kept separate from {@see from()}, which defaults rather than throws, so a
     * file with one mistyped word reports that one row instead of refusing the
     * whole import. The resolver turns this into a row-level error.
     */
    public static function statesAKnownKind(mixed $kind): bool
    {
        return OpeningRowKind::tryFrom(strtolower(trim((string) $kind))) !== null;
    }

    /**
     * The canonical form, for the import fingerprint.
     *
     * Every value normalised the same way it was parsed, so the same file
     * submitted twice hashes identically while a file whose figures were
     * corrected does not — which is the whole point of the check.
     *
     * @return array<int, string>
     */
    public function fingerprintParts(): array
    {
        return [
            $this->kind->value,
            strtolower((string) $this->name),
            strtolower((string) $this->variant),
            strtolower((string) $this->categoryName),
            (string) $this->quantity,
            (string) $this->unitCost,
            (string) $this->amount,
            strtolower((string) $this->account),
            strtolower((string) $this->side),
            (string) $this->gstin,
        ];
    }

    /**
     * True when the row says nothing at all — a trailing blank line, or the
     * spacer people leave between sections of a spreadsheet.
     */
    public function isBlank(): bool
    {
        return $this->name === null
            && $this->account === null
            && $this->amount === null
            && $this->quantity === null;
    }

    /* ---------------------------------------------------------------------
     | Normalisation
     |-------------------------------------------------------------------- */

    private static function text(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    private static function upper(mixed $value): ?string
    {
        $value = self::text($value);

        return $value === null ? null : strtoupper($value);
    }

    /**
     * A figure, with the punctuation a spreadsheet puts in it taken back out.
     *
     * "₹1,24,500.00" is what an Indian accounting package exports and what
     * somebody typing from a printed statement writes. Refusing it would send
     * people back to re-key a file the product could perfectly well read — and
     * they would re-key it into a text editor, which is where the transcription
     * errors come from.
     *
     * Returned as a string and never as a float: the parse into whole paise
     * happens in {@see Money}, once, where it is tested.
     */
    private static function number(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '') {
            return null;
        }

        $value = str_replace(['₹', ',', ' ', "\u{00a0}"], '', $value);

        // A figure in brackets is a negative, in every accounting export there
        // has ever been. Kept as a minus so the row's own validation reports it,
        // rather than being silently made positive here.
        if (preg_match('/^\((.+)\)$/', $value, $matches) === 1) {
            $value = '-'.$matches[1];
        }

        return $value === '' ? null : $value;
    }
}
