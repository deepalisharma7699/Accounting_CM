<?php

namespace App\Services\Onboarding;

use App\Exceptions\Onboarding\OpeningBalanceException;

/**
 * What the resolver decided about one declaration.
 *
 * Three cases, and the middle one is the module's whole answer to "re-importing
 * the same file does not double the balances": a row whose target already has an
 * opening balance is *skipped*, reported, and counted — not refused, and not
 * quietly posted a second time.
 */
enum RowOutcome: string
{
    /** Resolved, and it will post. */
    case Ready = 'ready';

    /**
     * The variant, party or account it names already carries an opening
     * balance, so this row would declare the same fact twice.
     *
     * Deliberately not an error. Running the same file again is a reasonable
     * thing to do when the first attempt was interrupted, and telling somebody
     * their file is broken when it is merely already in would send them off to
     * "fix" it — which is how a workshop ends up with two go-live positions.
     */
    case Skipped = 'skipped';

    /**
     * Nobody can act on it as it stands: an unknown account, an item with no
     * type to create it under, a figure that is not a number.
     *
     * One of these refuses the whole import. See
     * {@see OpeningBalanceException::planHasErrors()}.
     */
    case Error = 'error';

    public function label(): string
    {
        return match ($this) {
            self::Ready => 'Will post',
            self::Skipped => 'Already declared',
            self::Error => 'Needs fixing',
        };
    }
}
