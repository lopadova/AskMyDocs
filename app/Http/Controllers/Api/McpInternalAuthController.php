<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\McpServer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Crypt;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * v5.0/W1 — internal callbacks consumed by the Node sidecar.
 *
 * These endpoints are intentionally thin. Once the sidecar is deployed,
 * Node posts to `/api/mcp/credentials` to read decrypted auth config and
 * can post to `/api/mcp/internal-auth` to validate its possession of the
 * shared token.
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

    public function credentials(Request $request): JsonResponse
    {
        $this->ensureInternalRequesterAuthorized($request);

        $request->validate([
            'tenant_id' => ['required', 'string', 'max:50'],
            'mcp_server_id' => ['required', 'integer'],
        ]);

        $tenantId = $request->input('tenant_id');
        if (! preg_match('/^[a-z0-9_-]{1,50}$/', (string) $tenantId)) {
            throw new NotFoundHttpException('Invalid tenant id.');
        }

        $server = McpServer::query()
            ->where('tenant_id', $tenantId)
            ->where('id', (int) $request->input('mcp_server_id'))
            ->first();

        if ($server === null) {
            throw new NotFoundHttpException('MCP server not found.');
        }

        $encrypted = $server->getAttribute('auth_config_encrypted');
        if (! is_string($encrypted) || $encrypted === '') {
            return response()->json(['data' => null]);
        }

        return response()->json([
            'data' => [
                'transport' => $server->transport,
                'endpoint' => $server->endpoint,
                'auth_config' => $this->decryptAuthConfig($encrypted),
                'enabled_tools' => $server->enabled_tools_json ?? [],
            ],
        ]);
    }

    private function decryptAuthConfig(string $encrypted): array
    {
        try {
            $decoded = json_decode(Crypt::decryptString($encrypted), true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function ensureInternalRequesterAuthorized(Request $request): void
    {
        $expectedToken = (string) config('mcp.internal_auth_token', '');
        if ($expectedToken === '') {
            if ($request->user() === null) {
                throw new AccessDeniedHttpException('Missing internal auth token and no authenticated user.');
            }

            return;
        }

        $providedToken = (string) $request->header('X-MCP-Internal-Token', '');
        if (! hash_equals($expectedToken, $providedToken)) {
            throw new AccessDeniedHttpException('Invalid MCP internal token.');
        }
    }
}
