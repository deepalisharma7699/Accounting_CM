<?php

namespace App\Exceptions\Storage;

use App\Exceptions\ApiException;
use Throwable;

/**
 * Object storage would not take the file, or would not give it back — M14.
 *
 * A 503 rather than a 500, and rather than a 4xx: the caller did nothing wrong
 * and the request will very likely work in a minute, which is precisely what a
 * 503 says and what neither of the others does. It also tells a client whether
 * to offer "try again" — the difference between an outage and a rejected file is
 * the difference between waiting and taking a different photograph.
 *
 * Note that this is *not* how a failed verification surfaces. A file that stored
 * cleanly and then failed to read back is recorded on the attachment as
 * `failed`, on a screen, where somebody can see it — not raised at a caller who
 * has already had their 201 and gone.
 */
class StorageUnavailableException extends ApiException
{
    public static function writing(?Throwable $previous = null): self
    {
        return new self(
            message: 'The file store is not accepting uploads at the moment. '.
                'Nothing was saved — please try again shortly.',
            status: 503,
            errorCode: 'STORAGE_UNAVAILABLE',
            previous: $previous,
        );
    }

    public static function reading(?Throwable $previous = null): self
    {
        return new self(
            message: 'That file could not be fetched from the file store. Please try again shortly.',
            status: 503,
            errorCode: 'STORAGE_UNAVAILABLE',
            previous: $previous,
        );
    }
}
