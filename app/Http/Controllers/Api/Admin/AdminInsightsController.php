<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Models\AdminCommandAudit;
use App\Models\AdminInsightsSnapshot;
use App\Models\KnowledgeDocument;
use App\Services\Admin\AiInsightsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Phase I — admin AI insights controller.
 *
 * Four endpoints, all read-only against the snapshot table EXCEPT
 * `compute` which dispatches the compute command (sync via
 * Artisan::call — the compute pass takes seconds, not minutes, and
 * the rate-limit is strict so the queue backlog stays tame).
 *
 * Every endpoint is behind Sanctum + `role:admin|super-admin` at the
 * route layer; the `compute` endpoint adds a `permission:commands.destructive`
 * gate because recomputing burns provider quota and is therefore
 * destructive of that budget.
 *
 * The per-document AI suggestions endpoint is the exception to the
 * "snapshot-first" design — it calls `suggestTagsForDocument` on
 * demand so the KB Meta tab can render without waiting for the next
 * 05:00 run. Rate-limit lives at the route.
 */
class AdminInsightsController extends Controller
{
    public function __construct(
        private readonly AiInsightsService $insights,
    ) {}

    /**
     * GET /api/admin/insights/latest
     *
     * Returns the most recent snapshot, or 404 with a hint when no
     * row has been computed yet (fresh install).
     */
    public function latest(): JsonResponse
    {
        $row = AdminInsightsSnapshot::query()
            ->orderByDesc('snapshot_date')
            ->first();

        if ($row === null) {
            return response()->json([
                'message' => 'No insights snapshot has been computed yet.',
                'hint' => 'Run `php artisan insights:compute` or POST /api/admin/insights/compute to populate.',
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'data' => $this->shape($row),
        ]);
    }

    /**
     * GET /api/admin/insights/{date}
     *
     * Snapshot for a specific day. 404 when the date is missing or
     * malformed — we intentionally do NOT 422 on bad input because
     * the SPA uses this to "navigate dates"; a 404 is the same UX as
     * "that day has no data".
     */
    public function byDate(string $date): JsonResponse
    {
        try {
            $parsed = Carbon::parse($date)->startOfDay();
        } catch (Throwable) {
            return response()->json([
                'message' => 'Invalid date. Use YYYY-MM-DD.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $row = AdminInsightsSnapshot::query()
            ->whereDate('snapshot_date', $parsed->toDateString())
            ->first();

        if ($row === null) {
            return response()->json([
                'message' => "No insights snapshot for {$parsed->toDateString()}.",
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'data' => $this->shape($row),
        ]);
    }

    /**
     * POST /api/admin/insights/compute
     *
     * Recompute on demand (super-admin only; rate-limited at the route
     * level via throttle:3,5). Writes an AdminCommandAudit row to
     * mirror the maintenance-wizard telemetry — the ops timeline stays
     * unified. Returns HTTP 202 with the audit id.
     */
    public function compute(Request $request): JsonResponse
    {
        $user = $request->user();
        $audit = AdminCommandAudit::create([
            'user_id' => $user?->id,
            'command' => 'insights:compute',
            'args_json' => ['force' => true],
            'status' => AdminCommandAudit::STATUS_STARTED,
            'started_at' => Carbon::now(),
            'client_ip' => (string) ($request->ip() ?? ''),
            'user_agent' => (string) ($request->userAgent() ?? ''),
        ]);

        try {
            $exit = Artisan::call('insights:compute', ['--force' => true]);
            $audit->update([
                'status' => $exit === 0
                    ? AdminCommandAudit::STATUS_COMPLETED
                    : AdminCommandAudit::STATUS_FAILED,
                'exit_code' => $exit,
                'stdout_head' => mb_substr(Artisan::output(), 0, 1000),
                'completed_at' => Carbon::now(),
            ]);
        } catch (Throwable $e) {
            $audit->update([
                'status' => AdminCommandAudit::STATUS_FAILED,
                'exit_code' => 1,
                'error_message' => mb_substr($e->getMessage(), 0, 1000),
                'completed_at' => Carbon::now(),
            ]);
            Log::error('AdminInsightsController::compute failed.', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Insights compute failed.',
                'audit_id' => $audit->id,
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return response()->json([
            'message' => 'Insights compute dispatched.',
            'audit_id' => $audit->id,
        ], Response::HTTP_ACCEPTED);
    }

    /**
     * GET /api/admin/insights/document/{document}/ai-suggestions
     *
     * Per-doc AI-suggested tags for the KB Meta tab. Computes on the
     * fly (one LLM call). Rate-limit lives at the route (throttle:6,1)
     * so a quick tabbing-through pattern doesn't melt the provider
     * quota.
     */
    public function documentSuggestions(Request $request, int $documentId): JsonResponse
    {
        // Explicit `withTrashed()` lookup — we use `{documentId}` (not
        // `{document}`) in the route so the admin group's binding shim
        // doesn't preempt the viewer-403 middleware chain. Soft-deleted
        // docs are still valid targets for tag suggestions (the Meta
        // tab opens on trashed docs for forensic inspection).
        $doc = KnowledgeDocument::withTrashed()->find($documentId);
        if ($doc === null) {
            return response()->json([
                'message' => 'Document not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        try {
            $tags = $this->insights->suggestTagsForDocument($doc);
        } catch (Throwable $e) {
            Log::warning('AdminInsightsController::documentSuggestions failed.', [
                'document_id' => $documentId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'AI suggestions unavailable (provider error).',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return response()->json([
            'data' => [
                'document_id' => (int) $doc->id,
                'slug' => $doc->slug,
                'tags_proposed' => $tags,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function shape(AdminInsightsSnapshot $row): array
    {
        return [
            'id' => (int) $row->id,
            'snapshot_date' => $row->snapshot_date?->toDateString(),
            'suggest_promotions' => $row->suggest_promotions,
            'orphan_docs' => $row->orphan_docs,
            'suggested_tags' => $row->suggested_tags,
            'coverage_gaps' => $row->coverage_gaps,
            'stale_docs' => $row->stale_docs,
            'quality_report' => $row->quality_report,
            'computed_at' => $row->computed_at?->toIso8601String(),
            'computed_duration_ms' => $row->computed_duration_ms,
        ];
    }
}
