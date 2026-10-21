<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/** Authentication is ready; no PACT transport/contract is installed in this app. */
final class PactController
{
    public function unavailable(): JsonResponse
    {
        // Do not advertise an Agent Card, A2A interface, contract or successful
        // task before a real adapter can validate and execute that contract.
        return response()->json(['error' => 'pact_transport_unavailable',
            'error_description' => 'PactAuth authorization is available; PACT delegation is not implemented.',
            'producer_id' => config('pact-auth.producer_id'), 'supported_contracts' => []], 501);
    }
}
