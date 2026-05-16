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
            // v7.0/W6.3 — `status` was widened from ENUM to
            // string(32) so the package can emit `transport_error`
            // (and future strings). Drop the strict `in:` allowlist
            // here so operators can filter by ANY status the
            // package or host code legitimately writes.
            'status' => ['nullable', 'string', 'max:32'],
            'tool_name' => ['nullable', 'string', 'max:100'],
            // The SPA filter bar sends `server_id` (canonical FE
            // name); the original controller validated only
            // `mcp_server_id` (canonical DB name) so the SPA "Server"
            // filter was a silent no-op. Accept BOTH names — FE
            // takes precedence when both are passed.
            'server_id' => ['nullable', 'integer'],
            'mcp_server_id' => ['nullable', 'integer'],
            'user_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            // The SPA drives pagination via `page` + `per_page` and
            // reads a `meta.*` block from the response. `limit` is
            // kept for legacy non-paginated callers (existing tests,
            // CLI exports) and behaves as an alias for `per_page`
            // on page 1.
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,200'],
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

        $serverId = $validated['server_id'] ?? $validated['mcp_server_id'] ?? null;
        if ($serverId !== null) {
            $query->where('mcp_server_id', (int) $serverId);
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

        $perPage = (int) ($validated['per_page'] ?? $validated['limit'] ?? 50);
        $page = (int) ($validated['page'] ?? 1);
        $paginator = $query->paginate(perPage: $perPage, page: $page);

        return response()->json([
            'data' => $paginator->getCollection()->map(fn (McpToolCallAudit $row): array => [
                'id' => $row->id,
                'tenant_id' => $row->tenant_id,
                'status' => $row->status,
                'tool_name' => $row->tool_name,
                'duration_ms' => $row->duration_ms,
                'result_hash' => $row->result_hash,
                'created_at' => $row->created_at?->toIso8601String(),
                // Flat shape matches the SPA's `McpAuditEntry` TS
                // type (`user_id`, `user_name`, `mcp_server_id`,
                // `mcp_server_name`). Without these the SPA shows
                // `'—'` for both columns instead of the actual user
                // / server.
                'user_id' => $row->user_id,
                'user_name' => $row->user?->name,
                'mcp_server_id' => $row->mcp_server_id,
                'mcp_server_name' => $row->mcpServer?->name ?? $row->mcp_server_name,
                'conversation_id' => $row->conversation_id,
                'message_id' => $row->message_id,
                // Keep the nested shape so any non-FE consumer that
                // already reads `row.user.name` / `row.mcp_server.name`
                // continues working — additive change per R27.
                'user' => $row->user?->only(['id', 'name']),
                'mcp_server' => $row->mcpServer?->only(['id', 'name']),
            ])->values(),
            'meta' => [
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }
}
