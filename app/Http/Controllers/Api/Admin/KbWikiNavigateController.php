<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Services\Kb\AutoWiki\WikiNavigator;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * v8.11/P6 — HTTP surface (R44) of agentic graph-navigation:
 *   POST /api/admin/kb/wiki-navigate { project_key, seeds?[], depth? }
 * With seeds → BFS from them; without → anchor-driven from the project index.
 * Delegates to {@see WikiNavigator}; tenant-scoped (R30), RBAC-gated by the admin
 * KB route group (R32 matrix entry).
 */
final class KbWikiNavigateController extends Controller
{
    public function __construct(
        private readonly WikiNavigator $navigator,
        private readonly TenantContext $tenants,
    ) {}

    public function navigate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_key' => ['required', 'string', 'max:255'],
            'seeds' => ['sometimes', 'array', 'max:100'],
            'seeds.*' => ['string', 'max:255'],
            'depth' => ['sometimes', 'integer', 'min:1', 'max:5'],
        ]);

        $depth = isset($validated['depth']) ? (int) $validated['depth'] : null;
        $seeds = $validated['seeds'] ?? [];

        $result = $seeds === []
            ? $this->navigator->navigateFromAnchors($this->tenants->current(), $validated['project_key'], $depth)
            : $this->navigator->navigate($this->tenants->current(), $validated['project_key'], $seeds, $depth);

        return response()->json(['data' => $result]);
    }
}
