<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Services\Kb\AutoWiki\WikiMaintainer;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * v8.11/P9 — HTTP surface (R44) of scheduled wiki maintenance:
 *   POST /api/admin/kb/wiki-maintain { project_key?, fix?, backfill? }
 * Runs the maintenance sweep on-demand. Delegates to {@see WikiMaintainer};
 * tenant-scoped (R30), RBAC-gated by the admin KB route group (R32 matrix entry).
 */
final class KbWikiMaintainController extends Controller
{
    public function __construct(
        private readonly WikiMaintainer $maintainer,
        private readonly TenantContext $tenants,
    ) {}

    public function maintain(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_key' => ['sometimes', 'nullable', 'string', 'max:255'],
            'fix' => ['sometimes', 'boolean'],
            'backfill' => ['sometimes', 'integer', 'min:0', 'max:500'],
        ]);

        $result = $this->maintainer->maintain(
            $this->tenants->current(),
            $validated['project_key'] ?? null,
            (bool) ($validated['fix'] ?? false),
            isset($validated['backfill']) ? (int) $validated['backfill'] : null,
        );

        return response()->json(['data' => $result]);
    }
}
