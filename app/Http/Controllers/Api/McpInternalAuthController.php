<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * v5.0/W1 → v7.0/W6.3.B — thin token-presence probe.
 *
 * The richer `credentials()` callback was removed in v7.0/W6.3.B —
 * it existed only to feed the Node MCP sidecar (also retired in
 * W6.3.B) with decrypted upstream auth config. With the sidecar
 * gone there is no legitimate consumer; leaving the endpoint live
 * keeps a latent decrypted-secret pathway reachable whenever
 * `MCP_INTERNAL_AUTH_TOKEN` is empty (the controller would fall
 * back to `request->user()` and any authenticated user could read
 * the decrypted config).
 *
 * Only the token-presence probe survives so deployment scripts +
 * health-check tooling that still post to `/api/mcp/internal-auth`
 * don't break suddenly. v7.0/W6.3.C will drop this endpoint along
 * with `MCP_INTERNAL_AUTH_TOKEN`.
 */
final class McpInternalAuthController extends Controller
{
    public function verify(Request $request): JsonResponse
    {
        $expectedToken = (string) config('mcp.internal_auth_token', '');
        if ($expectedToken === '') {
            return response()->json(['ok' => true, 'message' => 'No token guard configured.']);
        }

        $providedToken = (string) $request->header('X-MCP-Internal-Token', '');
        if (hash_equals($expectedToken, $providedToken)) {
            return response()->json(['ok' => true]);
        }

        return response()->json(['ok' => false], 401);
    }
}
