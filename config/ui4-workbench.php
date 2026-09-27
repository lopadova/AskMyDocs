<?php

return [
    'route_prefix' => 'api/workbench',
    // The workbench is part of the authenticated SPA. Keeping it in the web
    // group preserves the host session and CSRF protections; tenant.authorize
    // then validates the SPA's selected tenant against the signed-in user.
    'middleware' => ['web', 'auth:sanctum', 'tenant.authorize'],
    'demo_mode' => false,
    'manifest_paths' => [
        resource_path('manifests/ui4'),
        base_path('vendor/ui4/laravel-workbench/resources/manifests'),
    ],
    'voice' => [
        'model' => env('UI4_OPENAI_REALTIME_MODEL', 'gpt-realtime-2.1'),
        // AskMyDocs owns its existing realtime agent. UI4's standalone voice
        // adapter stays unavailable unless a future host bridge is designed.
        'api_key' => null,
    ],
];
