<?php

namespace App\Exceptions\Storage;

use App\Enums\AttachmentKind;
use App\Exceptions\ApiException;

/**
 * An upload the application will not take — M14.
 *
 * A 422 rather than a 400: the request is well formed, and the file is the
 * problem. Every message here names the limit or the list it fell foul of,
 * because "unsupported file" tells somebody standing at a counter with a phone
 * nothing they can act on, and the next thing they do is try the same file again.
 */
class FileRejectedException extends ApiException
{
    public static function unsupportedType(AttachmentKind $kind, string $mimeType): self
    {
        $accepted = implode(', ', array_map(
            fn (string $type) => explode('/', $type)[1] ?? $type,
            $kind->mimeTypes(),
        ));

        return new self(
            message: sprintf(
                'A %s has to be one of: %s. This file is %s.',
                strtolower($kind->label()),
                $accepted,
                $mimeType,
            ),
            status: 422,
            errorCode: 'FILE_TYPE_UNSUPPORTED',
            details: ['field' => 'file', 'kind' => $kind->value, 'accepted' => $kind->mimeTypes()],
        );
    }

    public static function tooLarge(AttachmentKind $kind, int $bytes): self
    {
        $limit = round($kind->maxBytes() / 1024 / 1024, 1);
        $actual = round($bytes / 1024 / 1024, 1);

        return new self(
            message: sprintf(
                'That file is %s MB and the limit for a %s is %s MB. '.
                'A photograph taken at a lower resolution is usually enough to read an invoice.',
                $actual,
                strtolower($kind->label()),
                $limit,
            ),
            status: 422,
            errorCode: 'FILE_TOO_LARGE',
            details: ['field' => 'file', 'max_bytes' => $kind->maxBytes(), 'size_bytes' => $bytes],
        );
    }

    public static function empty(): self
    {
        return new self(
            message: 'That file is empty. Nothing was uploaded.',
            status: 422,
            errorCode: 'FILE_EMPTY',
            details: ['field' => 'file'],
        );
    }

    /**
     * The upload arrived damaged — PHP's own upload handling failed, usually a
     * connection dropped part-way or a limit in the web server rather than in
     * this application.
     */
    public static function unreadable(): self
    {
        return new self(
            message: 'That upload did not arrive in one piece. Please try it again.',
            status: 422,
            errorCode: 'FILE_UNREADABLE',
            details: ['field' => 'file'],
        );
    }
}
