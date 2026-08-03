<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Disk
    |--------------------------------------------------------------------------
    |
    | Where stored files live. Defaults to the `documents` disk defined in
    | config/filesystems.php, which is S3-compatible in production and a private
    | local directory in development.
    |
    | Never a public disk, and the application will not let it be one: an
    | invoice carries a customer's name, address and GSTIN, and a bucket that
    | serves objects by URL to anybody who has the URL is a bucket whose
    | contents are one leaked link away from public. Reads go through a signed,
    | expiring URL or through the application — see FileStorageService.
    |
    */

    'disk' => env('ATTACHMENTS_DISK', 'documents'),

    /*
    |--------------------------------------------------------------------------
    | Signed URL Lifetime
    |--------------------------------------------------------------------------
    |
    | How long a temporary read URL stays valid, in minutes. Short on purpose:
    | the URL is a bearer credential for one object, and a link pasted into a
    | chat message should stop working long before anybody thinks to worry
    | about it. Long enough to open a large photograph on a bad connection,
    | which is the only thing it has to survive.
    |
    */

    'url_ttl_minutes' => (int) env('ATTACHMENTS_URL_TTL', 10),

    /*
    |--------------------------------------------------------------------------
    | Kinds
    |--------------------------------------------------------------------------
    |
    | Per AttachmentKind: the media types accepted, and the ceiling in bytes.
    |
    | An allow-list, not a block-list. A block-list is a list of the attacks
    | somebody has already thought of; this is a statement about what the
    | product can actually use, and everything outside it is refused without
    | anybody having had to anticipate it.
    |
    | Limits are per kind because the honest number differs: a phone photograph
    | of an invoice is a couple of megabytes, a minute of speech is a fraction
    | of that, and one global limit set high enough for the largest would let
    | somebody push a 20 MB file in as "audio".
    |
    */

    'kinds' => [

        'invoice_image' => [
            'mime_types' => ['image/jpeg', 'image/png', 'image/webp', 'image/heic'],
            'max_bytes' => (int) env('ATTACHMENTS_MAX_IMAGE_BYTES', 8 * 1024 * 1024),
        ],

        'audio' => [
            // What a browser's MediaRecorder actually produces, plus the two
            // formats a phone is likely to hand over.
            'mime_types' => ['audio/webm', 'audio/ogg', 'audio/mpeg', 'audio/mp4', 'audio/wav', 'audio/x-wav'],
            'max_bytes' => (int) env('ATTACHMENTS_MAX_AUDIO_BYTES', 4 * 1024 * 1024),
        ],

        'document' => [
            'mime_types' => ['application/pdf'],
            'max_bytes' => (int) env('ATTACHMENTS_MAX_DOCUMENT_BYTES', 10 * 1024 * 1024),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Extensions
    |--------------------------------------------------------------------------
    |
    | The extension an object is stored under, chosen from its *verified* media
    | type rather than from the filename the client sent. A file arriving as
    | `invoice.jpg.php` is stored as `.jpg` or refused; it is never written to
    | disk under the name it came with.
    |
    | The original name is kept on the row, for display and for downloads.
    |
    */

    'extensions' => [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/heic' => 'heic',
        'audio/webm' => 'weba',
        'audio/ogg' => 'ogg',
        'audio/mpeg' => 'mp3',
        'audio/mp4' => 'm4a',
        'audio/wav' => 'wav',
        'audio/x-wav' => 'wav',
        'application/pdf' => 'pdf',
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Run Retention
    |--------------------------------------------------------------------------
    |
    | How many days a finished job run is kept before `jobs:prune` removes it.
    |
    | Failures are kept far longer than successes, and the asymmetry is the
    | point: a successful upload from three weeks ago is answered by the
    | attachment itself, while a failure from three weeks ago is the only record
    | that the work was ever attempted. Neither number is an audit-log retention
    | — `audit_logs` is never pruned, because a trail with an expiry date
    | answers "who changed this" with "we no longer know".
    |
    */

    'retention' => [
        'succeeded_days' => (int) env('JOBS_KEEP_SUCCEEDED_DAYS', 7),
        'failed_days' => (int) env('JOBS_KEEP_FAILED_DAYS', 90),
    ],

];
