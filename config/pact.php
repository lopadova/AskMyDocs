<?php

$contractDirectory = base_path('vendor/pact-protocol/laravel/resources/contracts/agent-chat');
$diagnostic = (bool) env('PACT_CLIENT_DIAGNOSTIC', false);
$queueConnection = env('PACT_QUEUE_CONNECTION') ?: null;
$queueName = env('PACT_QUEUE') ?: null;
$defaultQueueConnection = env('QUEUE_CONNECTION', 'sync');

if ($queueConnection === null && $queueName === null && is_string($defaultQueueConnection)
    && ! in_array($defaultQueueConnection, ['', 'sync', 'null', 'deferred', 'background'], true)) {
    // A queue name alone lets the SDK inherit Laravel's default connection.
    $queueName = env('AGENT_QUEUE', 'agent');
}

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
    'queue' => [
        'connection' => $queueConnection,
        'name' => $queueName,
    ],
    'state' => [
        'restore' => env('PACT_STATE_RESTORE'),
        'disk' => env('PACT_STATE_DISK'),
        'object' => env('PACT_STATE_OBJECT'),
    ],
];
