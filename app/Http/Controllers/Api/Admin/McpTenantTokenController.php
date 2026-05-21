<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Models\McpTenantToken;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class McpTenantTokenController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function index(): JsonResponse
    {
        $tenantId = $this->tenantContext->current();

        $rows = McpTenantToken::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => $rows->map(fn (McpTenantToken $row): array => $this->serialize($row))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = $this->tenantContext->current();
        $validated = $request->validate([
            'label' => ['required', 'string', 'max:120'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'scopes' => ['nullable', 'array'],
            'scopes.*' => ['string', 'max:100'],
        ]);

        $plainToken = 'askmd_'.Str::random(48);
        $tokenHash = hash('sha256', $plainToken);

        $row = McpTenantToken::query()->create([
            'tenant_id' => $tenantId,
            'label' => $validated['label'],
            'token_hash' => $tokenHash,
            'token_last4' => substr($plainToken, -4),
            'scopes_json' => $validated['scopes'] ?? ['mcp:read', 'mcp:tools:propose'],
            'created_by' => $request->user()?->getAuthIdentifier(),
            'expires_at' => $validated['expires_at'] ?? null,
        ]);

        return response()->json([
            'data' => $this->serialize($row),
            'plain_token' => $plainToken,
        ], 201);
    }

    public function revoke(int $id): JsonResponse
    {
        $row = $this->findForTenant($id);
        if ($row->revoked_at === null) {
            $row->forceFill(['revoked_at' => now()])->save();
        }

        return response()->json([
            'data' => $this->serialize($row->fresh()),
        ]);
    }

    private function findForTenant(int $id): McpTenantToken
    {
        $tenantId = $this->tenantContext->current();

        $row = McpTenantToken::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->first();

        if ($row === null) {
            throw new NotFoundHttpException('MCP tenant token not found.');
        }

        return $row;
    }

    private function serialize(McpTenantToken $row): array
    {
        return [
            'id' => $row->id,
            'label' => $row->label,
            'token_last4' => $row->token_last4,
            'scopes' => $row->scopes_json ?? [],
            'created_by' => $row->created_by,
            'last_used_at' => $row->last_used_at?->toIso8601String(),
            'expires_at' => $row->expires_at?->toIso8601String(),
            'revoked_at' => $row->revoked_at?->toIso8601String(),
            'created_at' => $row->created_at?->toIso8601String(),
            'updated_at' => $row->updated_at?->toIso8601String(),
        ];
    }
}

