<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Models\McpServer;
use App\Support\TenantContext;
use App\Mcp\Client\McpHandshakeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * v5.0/W1 — MCP server registry admin surface.
 *
 * W1 delivers a writable, tenant-scoped catalog so an operator can:
 *  - create MCP registrations
 *  - trigger a handshake
 *  - tune enabled tools
 *  - disable / delete servers
 *
 * Each action remains behind `can:manageMcpTools` (super-admin by
 * default) and `auth:sanctum`. No per-user policy is needed at
 * this phase because the registry is an infra control plane.
 */
final class McpServersAdminController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly McpHandshakeService $handshakeService,
    ) {}

    public function index(): JsonResponse
    {
        $tenantId = $this->tenantContext->current();
        $servers = McpServer::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $servers->map(fn (McpServer $server): array => $this->serialize($server))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = $this->tenantContext->current();
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'transport' => ['required', 'string', 'in:stdio,sse,http'],
            'endpoint' => ['required', 'string', 'max:500'],
            'auth_config' => ['nullable', 'array'],
            'auth_config.*' => ['required'],
            'enabled_tools' => ['nullable', 'array'],
            'enabled_tools.*' => ['string', 'max:100'],
        ]);

        $server = McpServer::create([
            'tenant_id' => $tenantId,
            'name' => $validated['name'],
            'transport' => $validated['transport'],
            'endpoint' => $validated['endpoint'],
            'auth_config_encrypted' => $this->encryptAuthConfig($validated['auth_config'] ?? null),
            'enabled_tools_json' => $validated['enabled_tools'] ?? ['*'],
            'status' => McpServer::STATUS_PENDING,
            'created_by' => $request->user()->getAuthIdentifier(),
        ]);

        return response()->json([
            'data' => $this->serialize($server),
        ], 201);
    }

    public function handshake(int $id): JsonResponse
    {
        $server = $this->findForTenant($id);

        try {
            $this->handshakeService->refresh($server);
            $server->refresh();
        } catch (\Throwable $exception) {
            $persisted = $server->forceFill([
                'status' => McpServer::STATUS_ERRORED,
                'last_handshake_at' => now(),
                'handshake_response_json' => [
                    'status' => 'error',
                    'message' => $exception->getMessage(),
                    'transport' => $server->transport,
                ],
            ])->save();

            if (! $persisted) {
                return response()->json(['error' => 'Failed to persist handshake error state.'], 500);
            }

            return response()->json([
                'data' => $this->serialize($server->fresh()),
                'error' => 'MCP handshake failed.',
            ], 502);
        }

        return response()->json([
            'data' => $this->serialize($server),
        ]);
    }

    public function updateEnabledTools(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'enabled_tools' => ['required', 'array'],
            'enabled_tools.*' => ['string', 'max:100'],
        ]);

        $server = $this->findForTenant($id);
        if (! $server->forceFill(['enabled_tools_json' => $validated['enabled_tools']])->save()) {
            abort(500, 'Failed to persist enabled tools update.');
        }

        return response()->json([
            'data' => $this->serialize($server),
        ]);
    }

    public function disable(int $id): JsonResponse
    {
        $server = $this->findForTenant($id);
        if (! $server->forceFill(['status' => McpServer::STATUS_DISABLED])->save()) {
            abort(500, 'Failed to persist server disable.');
        }

        return response()->json([
            'data' => $this->serialize($server),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $server = $this->findForTenant($id);
        $server->delete();

        return response()->json(null, 204);
    }

    private function findForTenant(int $id): McpServer
    {
        $tenantId = $this->tenantContext->current();
        $server = McpServer::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->first();

        if ($server === null) {
            throw new NotFoundHttpException('MCP server not found.');
        }

        return $server;
    }

    private function encryptAuthConfig(?array $authConfig): ?string
    {
        if ($authConfig === null || $authConfig === []) {
            return null;
        }

        $encoded = json_encode($authConfig, JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw ValidationException::withMessages([
                'auth_config' => 'Unable to serialize authentication config.',
            ]);
        }

        return Crypt::encryptString($encoded);
    }

    private function serialize(McpServer $server): array
    {
        return [
            'id' => $server->id,
            'name' => $server->name,
            'transport' => $server->transport,
            'endpoint' => $server->endpoint,
            'enabled_tools' => $server->enabled_tools_json ?? [],
            'status' => $server->status,
            'last_handshake_at' => $server->last_handshake_at?->toIso8601String(),
            'handshake_response' => $server->handshake_response_json,
            'created_at' => $server->created_at?->toIso8601String(),
            'updated_at' => $server->updated_at?->toIso8601String(),
        ];
    }
}
