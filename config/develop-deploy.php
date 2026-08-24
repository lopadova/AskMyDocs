<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Develop deployment safety gate
    |--------------------------------------------------------------------------
    |
    | This must be enabled only on the Laravel Cloud environment attached to
    | the develop branch. Destructive deployment directives also require the
    | separate environment identity below to match a non-production value.
    |
    */
    'enabled' => (bool) env('DEVELOP_DEPLOY_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Cloud environment identity
    |--------------------------------------------------------------------------
    |
    | Laravel Cloud reserves APP_ENV=production for optimized runtime
    | behaviour, including on test environments. This separate value names
    | the Cloud environment whose destructive directives are being enabled.
    |
    */
    'environment' => env('DEVELOP_DEPLOY_ENVIRONMENT'),

    'allowed_environments' => [
        'local',
        'development',
        'develop',
        'staging',
        'testing',
    ],

    /*
    |--------------------------------------------------------------------------
    | Develop fixture credentials
    |--------------------------------------------------------------------------
    |
    | All deterministic develop-only identities receive this password when
    | DevelopSeeder runs. Keep it in Laravel Cloud's secret environment
    | variables; there is deliberately no source-controlled default.
    |
    */
    'seed' => [
        'password' => env('DEVELOP_SEED_PASSWORD'),
    ],
];
