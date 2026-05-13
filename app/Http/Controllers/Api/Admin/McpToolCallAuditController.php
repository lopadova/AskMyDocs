<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Models\McpToolCallAudit;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;

/**
 * v5.0/W7 audit trail for MCP tool calls (phase 1 stub).
 *
 * The action is intentionally read-only and tenant-scoped so the
 * dashboard can show usage + failures without leaking cross-tenant
 * rows.
 */
final class McpToolCallAuditController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'in:ok,error,timeout,denied'],
            'tool_name' => ['nullable', 'string', 'max:100'],
            'mcp_server_id' => ['nullable', 'integer'],
            'user_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'limit' => ['nullable', 'integer', 'between:1,200'],
        ]);

        $tenantId = $this->tenantContext->current();
        $query = McpToolCallAudit::query()
            ->with(['user:id,name', 'mcpServer:id,name'])
            ->where('tenant_id', $tenantId)
            ->orderByDesc('created_at');

        if (($validated['status'] ?? null) !== null) {
            $query->where('status', $validated['status']);
        }

        if (($validated['tool_name'] ?? null) !== null) {
            $query->where('tool_name', $validated['tool_name']);
        }

        if (($validated['mcp_server_id'] ?? null) !== null) {
            $query->where('mcp_server_id', (int) $validated['mcp_server_id']);
        }

        if (($validated['user_id'] ?? null) !== null) {
            $query->where('user_id', (int) $validated['user_id']);
        }

        if (($validated['from'] ?? null) !== null) {
            $from = Carbon::parse($validated['from'])->startOfDay();
            $query->where('created_at', '>=', $from);
        }

        if (($validated['to'] ?? null) !== null) {
            $to = Carbon::parse($validated['to'])->endOfDay();
            $query->where('created_at', '<=', $to);
        }

        $limit = $validated['limit'] ?? 50;
        $rows = $query->limit($limit)->get();

        return response()->json([
            'data' => $rows->map(fn (McpToolCallAudit $row): array => [
                'id' => $row->id,
                'tenant_id' => $row->tenant_id,
                'status' => $row->status,
                'tool_name' => $row->tool_name,
                'duration_ms' => $row->duration_ms,
                'result_hash' => $row->result_hash,
                'created_at' => $row->created_at?->toIso8601String(),
                'user' => $row->user?->only(['id', 'name']),
                'mcp_server' => $row->mcpServer?->only(['id', 'name']),
                'conversation_id' => $row->conversation_id,
                'message_id' => $row->message_id,
            ])->values(),
        ]);
    }
}
