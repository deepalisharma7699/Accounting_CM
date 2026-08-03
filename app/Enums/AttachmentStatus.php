<?php

namespace App\Enums;

/**
 * Whether a stored file has been confirmed to be there — M14.
 *
 * The distinction matters more than it looks. Writing to object storage is a
 * network call to somebody else's machine, and it can report success while the
 * object is not readable — a wrong bucket, a permission that applies to writes
 * and not reads, a region that has not caught up. A row that said "stored" on
 * the strength of the write returning cleanly would be a workshop believing it
 * has a photograph of an invoice it cannot open, which is the failure that only
 * shows up when the invoice is needed.
 *
 * So the upload records `pending`, a queued job reads the object back and checks
 * its size and its digest, and only that promotes it to `ready`.
 */
enum AttachmentStatus: string
{
    /** Written, not yet read back. */
    case Pending = 'pending';

    /** Read back, and the bytes are the bytes that were sent. */
    case Ready = 'ready';

    /**
     * The object is missing, truncated or different. The row is kept rather than
     * removed: "we tried to store this and could not" is what somebody needs to
     * see, and a row that deleted itself would present as a file nobody ever
     * uploaded.
     */
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Checking',
            self::Ready => 'Stored',
            self::Failed => 'Not stored',
        };
    }

    public function isUsable(): bool
    {
        return $this === self::Ready;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
