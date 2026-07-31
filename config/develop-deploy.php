<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Develop deployment safety gate
    |--------------------------------------------------------------------------
    |
    | This must be enabled only on the Laravel Cloud environment attached to
    | the develop branch. Destructive deployment directives remain forbidden
    | when APP_ENV is production, even if this flag is accidentally copied.
    |
    */
    'enabled' => (bool) env('DEVELOP_DEPLOY_ENABLED', false),

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
