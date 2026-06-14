<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Services\Kb\AutoWiki\WikiLinter;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * v8.11/P5 — HTTP surface (R44) of Auto-Wiki lint:
 *   GET  /api/admin/kb/wiki-lint      ?project_key=  → report
 *   POST /api/admin/kb/wiki-lint/fix  { project_key } → apply safe auto-fixes
 * Delegates to {@see WikiLinter}; tenant-scoped (R30), RBAC-gated by the admin
 * KB route group (R32 matrix entry).
 */
final class KbWikiLintController extends Controller
{
    public function __construct(
        private readonly WikiLinter $linter,
        private readonly TenantContext $tenants,
    ) {}

    public function report(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_key' => ['required', 'string', 'max:255'],
        ]);

        return response()->json([
            'data' => $this->linter->lint($this->tenants->current(), $validated['project_key']),
        ]);
    }

    public function fix(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_key' => ['required', 'string', 'max:255'],
        ]);

        return response()->json([
            'data' => $this->linter->fix($this->tenants->current(), $validated['project_key']),
        ]);
    }
}
