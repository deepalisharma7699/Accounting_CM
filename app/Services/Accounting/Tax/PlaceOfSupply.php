<?php

namespace App\Services\Accounting\Tax;

/**
 * Whether a supply is within one state or across two.
 *
 * The single fact that decides whether GST splits into CGST and SGST or is
 * charged as IGST — and it is decided by two two-digit state codes, nothing
 * else. The workshop's comes from its GSTIN; the counterparty's comes from
 * theirs.
 *
 * ## When the counterparty's state is unknown
 *
 * It is treated as **intra-state**, and that is the correct default rather than
 * a shrug. A party with no GSTIN is almost always an unregistered walk-in — the
 * farmer whose pump motor is being rewound — and an unregistered recipient's
 * place of supply is where the goods are handed over, which is the workshop's own
 * counter. Defaulting the other way would put IGST on the counter sale that is
 * the trade's most common document, and IGST charged where CGST and SGST were due
 * is a correction the department has to be asked for.
 *
 * A workshop that has not set its *own* GSTIN has no basis to charge inter-state
 * tax at all, so that case is intra-state too.
 */
final class PlaceOfSupply
{
    private function __construct(
        public readonly ?string $supplierState,
        public readonly ?string $recipientState,
        public readonly bool $interState,
    ) {}

    public static function between(?string $supplierState, ?string $recipientState): self
    {
        $supplier = self::normalise($supplierState);
        $recipient = self::normalise($recipientState);

        return new self(
            $supplier,
            $recipient,
            $supplier !== null && $recipient !== null && $supplier !== $recipient,
        );
    }

    /**
     * A supply with no counterparty state on record — the counter sale.
     */
    public static function domestic(?string $supplierState = null): self
    {
        return new self(self::normalise($supplierState), null, false);
    }

    /**
     * The shape a document already used, restated — M18.
     *
     * For a credit note, and only for a credit note. The shape of the tax on a
     * return has to be the shape the original invoice charged, because the pair
     * has to net out on the return that reports both. Deriving it afresh from the
     * party's state code would get that wrong the day somebody corrects a
     * customer's address: a February invoice charged CGST + SGST would be
     * credited back as IGST, and neither figure on the GST return would be
     * right.
     *
     * The states are carried through unchanged so the document can still print
     * where the supply was; only the split is pinned.
     */
    public static function matching(self $original, bool $interState): self
    {
        return new self($original->supplierState, $original->recipientState, $interState);
    }

    public function isInterState(): bool
    {
        return $this->interState;
    }

    public function isIntraState(): bool
    {
        return ! $this->interState;
    }

    /**
     * What the invoice should print above the tax columns.
     */
    public function label(): string
    {
        return $this->interState ? 'Inter-state (IGST)' : 'Intra-state (CGST + SGST)';
    }

    /**
     * A state code is two digits. Anything else — a blank, a stray space, a
     * half-typed GSTIN — is "not on record" rather than a third kind of supply.
     */
    private static function normalise(?string $code): ?string
    {
        $code = trim((string) $code);

        return preg_match('/^\d{2}$/', $code) === 1 ? $code : null;
    }

    /**
     * @return array{supplier_state: string|null, recipient_state: string|null, inter_state: bool, label: string}
     */
    public function toArray(): array
    {
        return [
            'supplier_state' => $this->supplierState,
            'recipient_state' => $this->recipientState,
            'inter_state' => $this->interState,
            'label' => $this->label(),
        ];
    }
}
