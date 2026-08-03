<?php

namespace App\Enums;

/**
 * How a transaction came to exist.
 *
 * Provenance is kept because the three routes carry very different levels of
 * trust, and a transaction list that cannot tell them apart makes an AI
 * mistake indistinguishable from a typo. M12's worklists filter on it.
 *
 * Only `manual` is reachable today; the other two are recorded here so the
 * column and its meaning are settled before anything writes to it — the same
 * reason the Phase 2 accounts are seeded in {@see SystemAccount}.
 */
enum TransactionSource: string
{
    /** Typed into a form by a person, who is accountable for it. */
    case Manual = 'manual';

    /** A CSV or Excel import — opening balances, M11. */
    case Import = 'import';

    /**
     * Extracted by the capture agent from text, speech or a photograph, M15.
     * Never posted without a person authorising the draft.
     */
    case Ai = 'ai';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual entry',
            self::Import => 'Imported',
            self::Ai => 'AI capture',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
