<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Services\Kb\AutoWiki\WikiIndexBuilder;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * v8.11/P4 — HTTP surface (R44) of Auto-Wiki indices + operation log:
 *   POST /api/admin/kb/wiki-index        { project_key? }  → rebuild
 *   GET  /api/admin/kb/wiki-index                          → hub + project rows
 *   GET  /api/admin/kb/wiki-operations  ?project_key=&limit= → operation log
 * Delegates to {@see WikiIndexBuilder}; tenant-scoped (R30), RBAC-gated by the
 * admin KB route group (R32 matrix entry).
 */
final class KbWikiIndexController extends Controller
{
    public function __construct(
        private readonly WikiIndexBuilder $builder,
        private readonly TenantContext $tenants,
    ) {}

    public function rebuild(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_key' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $result = $this->builder->rebuild(
            $this->tenants->current(),
            $validated['project_key'] ?? null,
        );

        return response()->json(['data' => $result]);
    }

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->builder->hub($this->tenants->current())]);
    }

    public function operations(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_key' => ['sometimes', 'nullable', 'string', 'max:255'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:500'],
        ]);

        $log = $this->builder->operationLog(
            $this->tenants->current(),
            $validated['project_key'] ?? null,
            (int) ($validated['limit'] ?? 50),
        );

        return response()->json(['data' => $log]);
    }
}
