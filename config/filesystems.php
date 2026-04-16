<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Laravel supports many drivers via the Flysystem PHP package. All KB
    | markdown files are read through the disk configured under the "kb" key
    | so the ingestion pipeline is storage-agnostic (local, S3, MinIO, etc.).
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app'),
            'throw' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
        ],

        /*
        |----------------------------------------------------------------------
        | Knowledge Base disk
        |----------------------------------------------------------------------
        |
        | Used by DocumentIngestor / kb:ingest command to locate markdown
        | sources. Swap the driver to 's3' (see example below) to serve KB
        | content from an S3 bucket — no application code changes required.
        |
        */

        'kb' => [
            'driver' => env('KB_DISK_DRIVER', 'local'),
            'root' => env('KB_DISK_ROOT', storage_path('app/kb')),
            'throw' => false,
        ],

        /*
        |----------------------------------------------------------------------
        | S3 example
        |----------------------------------------------------------------------
        |
        | Requires: composer require league/flysystem-aws-s3-v3 "^3.0"
        | Set KB_DISK_DRIVER=s3 in .env to activate.
        |
        */

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
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
