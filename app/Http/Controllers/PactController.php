<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pact\HttpBinding;

/** Authentication is ready; no PACT transport/contract is installed in this app. */
final class PactController
{
    public function unavailable(Request $request): JsonResponse
    {
        if (config('pact-auth.transport_enabled') && interface_exists(HttpBinding::class) && app()->bound(HttpBinding::class)) {
            if ($request->isMethod('POST') && ! in_array(config('queue.connections.'.config('pact-agent.connection', 'pact_agents').'.driver'), ['database', 'redis', 'sqs', 'beanstalkd'], true)) {
                return response()->json(['error' => 'pact_async_queue_required'], 503);
            }
            $headers = [];
            foreach ($request->headers->all() as $key => $values) $headers[$key] = $values[0] ?? '';
            [$status, $responseHeaders, $body] = app(HttpBinding::class)->handle($request->method(), '/'.$request->path(), $headers, $request->getContent());
            return response()->json($body, $status, $responseHeaders);
        }
        // Do not advertise an Agent Card, A2A interface, contract or successful
        // task before a real adapter can validate and execute that contract.
        return response()->json(['error' => 'pact_transport_unavailable',
            'error_description' => 'PactAuth authorization is available; PACT delegation is not implemented.',
            'producer_id' => config('pact-auth.producer_id'), 'supported_contracts' => []], 501);
    }
}
