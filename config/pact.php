<?php

$contractDirectory = base_path('vendor/pact-protocol/laravel/resources/contracts/agent-chat');
$diagnostic = (bool) env('PACT_CLIENT_DIAGNOSTIC', false);

return [
    'enabled' => (bool) env('PACT_CLIENT_ENABLED', true),
    'auth' => [
        'guard' => 'web',
        'provider' => 'users',
        'middleware' => [\App\Pact\Native\ConsentScope::class],
    ],
    'handler' => $diagnostic ? null : \App\Pact\Native\ChatHandler::class,
    'contract_path' => $diagnostic ? null : $contractDirectory.'/contract.json',
    'contract_registry' => $diagnostic ? null : $contractDirectory.'/registry.json',
    'state' => [
        'restore' => (bool) env('PACT_STATE_RESTORE', false),
        'disk' => env('PACT_STATE_DISK', 'pact-state'),
        'object' => env('PACT_STATE_OBJECT'),
    ],
];
