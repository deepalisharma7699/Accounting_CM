<?php

namespace App\Enums;

/**
 * What a stored file *is* — M14, and the ground M15 stands on.
 *
 * Three kinds, and each carries its own allow-list of media types and its own
 * size ceiling, read from `config/attachments.php` so an operator can raise a
 * limit without a deployment. The kind is declared by the uploader and then
 * checked against the bytes: a caller who says "invoice image" and sends a PDF
 * is refused, because the kind is what decides how M15 will try to read it.
 *
 * ## Why an allow-list and not a block-list
 *
 * This is the second piece of user-supplied data the product accepts at all —
 * M11's CSV was the first, and it got the smallest parser that would do the job
 * for exactly this reason. A block-list of dangerous types is a list of the
 * attacks somebody has already thought of. An allow-list of three image formats,
 * two audio formats and PDF is a statement about what the product can use, and
 * everything else is refused without anybody having to have anticipated it.
 *
 * The extension stored on disk is derived from the *verified* media type, never
 * from the filename the client sent. A file called `invoice.jpg.php` is stored
 * as a `.jpg` or refused; it is never stored under the name it arrived with.
 */
enum AttachmentKind: string
{
    /** A photograph or scan of a purchase invoice — M15.7's input. */
    case InvoiceImage = 'invoice_image';

    /** Push-to-talk capture — M15.6's input, kept raw so a bad transcription can be re-read. */
    case Audio = 'audio';

    /** Anything else a workshop wants attached to its books: a PDF bill, a delivery note. */
    case Document = 'document';

    public function label(): string
    {
        return match ($this) {
            self::InvoiceImage => 'Invoice image',
            self::Audio => 'Audio',
            self::Document => 'Document',
        };
    }

    /**
     * The media types this kind accepts.
     *
     * @return array<int, string>
     */
    public function mimeTypes(): array
    {
        /** @var array<int, string> $types */
        $types = config("attachments.kinds.{$this->value}.mime_types", []);

        return $types;
    }

    /**
     * The ceiling, in bytes.
     *
     * Per kind rather than one global limit, because the honest number is
     * different for each: a phone photograph of an invoice is a couple of
     * megabytes, and a minute of voice is a fraction of that. One limit set high
     * enough for the largest would let somebody push a 20 MB file in as "audio".
     */
    public function maxBytes(): int
    {
        return (int) config("attachments.kinds.{$this->value}.max_bytes", 5 * 1024 * 1024);
    }

    /**
     * The extension to store this media type under — derived from the verified
     * type, never from the name the client sent.
     */
    public function extensionFor(string $mimeType): string
    {
        /** @var array<string, string> $map */
        $map = config('attachments.extensions', []);

        return $map[$mimeType] ?? 'bin';
    }

    public function accepts(string $mimeType): bool
    {
        return in_array($mimeType, $this->mimeTypes(), true);
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Published to the client so an upload control's `accept` attribute and its
     * size warning come from the server. A copy in the browser is right until
     * somebody raises a limit, and then it refuses files the API would take.
     *
     * @return array<int, array{value: string, label: string, mime_types: array<int, string>, max_bytes: int}>
     */
    public static function catalogue(): array
    {
        return array_map(fn (self $kind) => [
            'value' => $kind->value,
            'label' => $kind->label(),
            'mime_types' => $kind->mimeTypes(),
            'max_bytes' => $kind->maxBytes(),
        ], self::cases());
    }
}
