<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        /*
        |----------------------------------------------------------------------
        | documents — M14's object storage
        |----------------------------------------------------------------------
        |
        | Where photographed invoices and recorded audio live. Its own disk
        | rather than a folder on `local` or a reuse of `s3`, for three reasons
        | that all point the same way:
        |
        |   1. It is *private*, always. There is no `public` disk equivalent and
        |      there must not be: an invoice carries a customer's name, address
        |      and GSTIN, and a bucket that serves objects to anybody holding the
        |      URL is one leaked link away from publishing them. Reads go through
        |      a signed, expiring URL or through the application — never a static
        |      path. `visibility` is stated rather than left to default so a
        |      driver change cannot quietly make objects world-readable.
        |
        |   2. `throw` is true, unlike every other disk here. Elsewhere a failed
        |      write returns false and the caller decides; here a write that
        |      silently did nothing would leave a row pointing at an object that
        |      does not exist, and the workshop would find out when they needed
        |      the invoice. M14's premise is that a stored file is really stored,
        |      and an exception is the only way to keep that promise. (The row is
        |      still not trusted until a queued job has read the object back —
        |      see AttachmentStatus.)
        |
        |   3. It swaps between local development and S3-compatible production
        |      through one variable, so nothing in the application ever names a
        |      driver. Any S3-compatible endpoint works: AWS, Cloudflare R2,
        |      Backblaze, MinIO — set AWS_ENDPOINT and AWS_USE_PATH_STYLE_ENDPOINT.
        |
        */
        'documents' => env('ATTACHMENTS_DRIVER', 'local') === 's3'
            ? [
                'driver' => 's3',
                'key' => env('AWS_ACCESS_KEY_ID'),
                'secret' => env('AWS_SECRET_ACCESS_KEY'),
                'region' => env('AWS_DEFAULT_REGION'),
                'bucket' => env('ATTACHMENTS_BUCKET', env('AWS_BUCKET')),
                'endpoint' => env('AWS_ENDPOINT'),
                'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
                'visibility' => 'private',
                'throw' => true,
                'report' => false,
            ]
            : [
                'driver' => 'local',
                // Under storage/app/private, which is outside the web root. A
                // local disk has no signed URLs, so FileStorageService streams
                // through the application instead — see temporaryUrl().
                'root' => storage_path('app/private/documents'),
                'serve' => false,
                'throw' => true,
                'report' => false,
            ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
