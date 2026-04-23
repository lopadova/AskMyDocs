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
        |
        | To serve KB docs from S3 set KB_FILESYSTEM_DISK=s3 in .env — this
        | routes ingestion through the fully-configured "s3" disk below
        | (which reads AWS_* env vars for credentials and bucket). Do NOT
        | set KB_DISK_DRIVER=s3 on its own: the "kb" disk entry above does
        | not carry S3 credential keys.
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

        /*
        |----------------------------------------------------------------------
        | KB raw disk (OmegaWiki-inspired raw → curated → canonical pipeline)
        |----------------------------------------------------------------------
        |
        | Holds unprocessed PDFs / Word / notes awaiting promotion to the
        | canonical KB. Separated from the "kb" (canonical) disk so a bad
        | raw ingest cannot corrupt already-promoted markdown. Uses default
        | Laravel filesystem permissions (0755/0644).
        |
        */

        'kb-raw' => [
            'driver' => 'local',
            'root' => env('KB_RAW_DISK_ROOT', storage_path('app/kb-raw')),
            'throw' => false,
            'visibility' => 'private',
        ],

        /*
        |----------------------------------------------------------------------
        | Cloudflare R2 (S3-compatible)
        |----------------------------------------------------------------------
        |
        | Zero-egress-fee S3-compatible storage. Point AWS_ENDPOINT at
        | https://<account-id>.r2.cloudflarestorage.com and set
        | KB_FILESYSTEM_DISK=r2 to route KB ingestion here.
        |
        */

        'r2' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION', 'auto'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => false,
            'throw' => false,
        ],

        /*
        |----------------------------------------------------------------------
        | Google Cloud Storage
        |----------------------------------------------------------------------
        |
        | Requires: composer require superbalist/laravel-google-cloud-storage
        | Inert until the package is installed and env configured — Laravel
        | will ignore this block as long as nothing resolves the "gcs" disk.
        |
        */

        'gcs' => [
            'driver' => 'gcs',
            'project_id' => env('GOOGLE_CLOUD_PROJECT_ID'),
            'key_file' => env('GOOGLE_CLOUD_KEY_FILE'),
            'bucket' => env('GOOGLE_CLOUD_BUCKET'),
            'path_prefix' => env('GOOGLE_CLOUD_PATH_PREFIX'),
            'storage_api_uri' => env('GOOGLE_CLOUD_STORAGE_API_URI'),
        ],

        /*
        |----------------------------------------------------------------------
        | MinIO (self-hosted, S3-compatible)
        |----------------------------------------------------------------------
        |
        | Useful for air-gapped / on-prem deployments. Note the
        | use_path_style_endpoint=true requirement.
        |
        */

        'minio' => [
            'driver' => 's3',
            'key' => env('MINIO_ACCESS_KEY'),
            'secret' => env('MINIO_SECRET_KEY'),
            'region' => env('MINIO_REGION', 'us-east-1'),
            'bucket' => env('MINIO_BUCKET', 'askmydocs'),
            'endpoint' => env('MINIO_ENDPOINT', 'http://localhost:9000'),
            'use_path_style_endpoint' => true,
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
