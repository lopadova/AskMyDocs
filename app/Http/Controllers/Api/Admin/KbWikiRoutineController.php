<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Routines\WikiRoutineService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * Admin surface for the Auto-Wiki maintenance routine (v8.39/W5, ADR 0033
 * §8). Thin controller: everything delegates to {@see WikiRoutineService}
 * — the same core the CLI (`kb:wiki-routine`) and the MCP read tool
 * (`KbWikiRoutineStatusTool`) use.
 *
 * Auth: `auth:sanctum` + `tenant.authorize` + `role:admin|super-admin`
 * (route group, same stack as the other `kb/*` admin surfaces). Tenant
 * comes from `TenantContext` (session/header), never from request input —
 * SEC-IDOR-001.
 *
 * R43 both states: `index()` (read) always answers, even when the flag is
 * off (`{disabled: true}` — matches every other read surface this plan has
 * shipped: `KbGetExportTool`, `KbReviewStatusTool`, `KbOcrStatusTool`).
 * `run()` (mutating) requires the flag on.
 */
final class KbWikiRoutineController extends Controller
{
    public function __construct(
        private readonly WikiRoutineService $routines,
        private readonly TenantContext $tenant,
    ) {
    }

    /** GET /api/admin/kb/wiki-routine */
    public function index(): JsonResponse
    {
        if (! $this->routines->enabled()) {
            return $this->disabledResponse();
        }

        return response()->json(['data' => $this->routines->status($this->tenant->current())]);
    }

    /** POST /api/admin/kb/wiki-routine/run */
    public function run(): JsonResponse
    {
        if (! $this->routines->enabled()) {
            return $this->disabledResponse();
        }

        return response()->json(['data' => $this->routines->run($this->tenant->current())], 202);
    }

    private function disabledResponse(): JsonResponse
    {
        return response()->json(['disabled' => true, 'flag' => 'KB_WIKI_ROUTINE_ENABLED'], Response::HTTP_OK);
    }
}
