<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Resources\Admin\Logs\ActivityLogResource;
use App\Http\Resources\Admin\Logs\AuditLogResource;
use App\Http\Resources\Admin\Logs\ChatLogResource;
use App\Http\Resources\Admin\Logs\FailedJobResource;
use App\Models\ChatLog;
use App\Models\KbCanonicalAudit;
use App\Services\Admin\LogTailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase H1 — admin Log Viewer (READ-ONLY).
 *
 * Five tabs — chat logs, canonical audit, application log tail, activity
 * log (Spatie), failed jobs. Every endpoint is paginated (R3) or capped
 * (application tail: max 2000 lines).
 *
 * H1 scope is strictly read-only: NO retry endpoint, NO maintenance
 * wizard, NO command runner. Those live in H2 (the second microphase of
 * Phase H). Keep this controller thin; heavy lifting belongs in the
 * respective services.
 *
 * RBAC is enforced at the route layer via Spatie's `role:admin|super-admin`
 * middleware — this controller only sees already-authorised traffic.
 */
class LogViewerController extends Controller
{
    public function __construct(
        private readonly LogTailService $tail,
    ) {}

    /**
     * GET /api/admin/logs/chat
     *
     * Filters: project / model / min_latency_ms / min_tokens / from / to.
     * All pushed into SQL — R3 forbids loading the whole table into PHP.
     */
    public function chat(Request $request): AnonymousResourceCollection
    {
        $query = ChatLog::query()->orderByDesc('created_at');

        $project = $this->trimString($request->query('project'));
        if ($project !== null) {
            $query->where('project_key', $project);
        }

        $model = $this->trimString($request->query('model'));
        if ($model !== null) {
            $query->where('ai_model', $model);
        }

        $minLatency = $request->query('min_latency_ms');
        if ($minLatency !== null && $minLatency !== '') {
            $query->where('latency_ms', '>=', (int) $minLatency);
        }

        $minTokens = $request->query('min_tokens');
        if ($minTokens !== null && $minTokens !== '') {
            $query->where('total_tokens', '>=', (int) $minTokens);
        }

        $from = $this->trimString($request->query('from'));
        if ($from !== null) {
            $query->where('created_at', '>=', $from);
        }

        $to = $this->trimString($request->query('to'));
        if ($to !== null) {
            $query->where('created_at', '<=', $to);
        }

        return ChatLogResource::collection($query->paginate(20));
    }

    /**
     * GET /api/admin/logs/chat/{id}
     *
     * Single-row drawer payload. No soft-delete semantics — `chat_logs`
     * never has a `deleted_at` column (records are pruned, not trashed).
     */
    public function chatShow(int $id): ChatLogResource
    {
        $log = ChatLog::query()->findOrFail($id);

        return new ChatLogResource($log);
    }

    /**
     * GET /api/admin/logs/canonical-audit
     */
    public function canonicalAudit(Request $request): AnonymousResourceCollection
    {
        $query = KbCanonicalAudit::query()->orderByDesc('created_at');

        $project = $this->trimString($request->query('project'));
        if ($project !== null) {
            $query->where('project_key', $project);
        }

        $event = $this->trimString($request->query('event_type'));
        if ($event !== null) {
            $query->where('event_type', $event);
        }

        $actor = $this->trimString($request->query('actor'));
        if ($actor !== null) {
            $query->where('actor', $actor);
        }

        $from = $this->trimString($request->query('from'));
        if ($from !== null) {
            $query->where('created_at', '>=', $from);
        }

        $to = $this->trimString($request->query('to'));
        if ($to !== null) {
            $query->where('created_at', '<=', $to);
        }

        return AuditLogResource::collection($query->paginate(20));
    }

    /**
     * GET /api/admin/logs/application
     *
     * Returns the tail of the specified log file (max 2000 lines).
     * Error matrix:
     *  - invalid filename (whitelist miss)   → 422
     *  - filename OK but file missing        → 404
     *  - filename OK, file unreadable / I/O  → 500  (R4 loud failure)
     */
    public function application(Request $request): JsonResponse
    {
        $filename = $this->trimString($request->query('file')) ?? 'laravel.log';
        $level = $this->trimString($request->query('level'));
        $tail = (int) $request->query('tail', 500);

        try {
            $result = $this->tail->tail($filename, $tail, $level);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['file' => [$e->getMessage()]],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (RuntimeException $e) {
            // R4 — surface the failure loudly instead of faking success.
            // 404 for a genuinely-missing file, 500 for any other
            // RuntimeException (permissions, unexpected I/O). We
            // distinguish via message prefix because the service
            // throws the same exception class for both cases.
            $status = str_starts_with($e->getMessage(), 'Log file not found')
                ? Response::HTTP_NOT_FOUND
                : Response::HTTP_INTERNAL_SERVER_ERROR;

            return response()->json([
                'message' => $e->getMessage(),
            ], $status);
        }

        return response()->json([
            'file' => $filename,
            'level' => $level,
            'requested_tail' => $tail,
            'lines' => $result['lines'],
            'truncated' => $result['truncated'],
            'total_scanned' => $result['total_scanned'],
        ]);
    }

    /**
     * GET /api/admin/logs/activity
     *
     * `activity_log` is a soft dependency — the controller handles the
     * case where `spatie/laravel-activitylog` is installed but the
     * migration hasn't been run. This keeps the tab usable in a
     * just-cloned environment without forcing operators to install
     * the migration first.
     */
    public function activity(Request $request): JsonResponse|AnonymousResourceCollection
    {
        if (! Schema::hasTable('activity_log')) {
            return response()->json([
                'data' => [],
                'meta' => [
                    'current_page' => 1,
                    'per_page' => 20,
                    'total' => 0,
                    'last_page' => 1,
                ],
                'note' => 'activitylog not installed',
            ]);
        }

        // Build via the query builder so we don't hard-depend on the
        // Activity FQCN (the resource is tolerant of any object shape).
        $query = DB::table('activity_log')->orderByDesc('id');

        $subjectType = $this->trimString($request->query('subject_type'));
        if ($subjectType !== null) {
            $query->where('subject_type', $subjectType);
        }

        $subjectId = $request->query('subject_id');
        if ($subjectId !== null && $subjectId !== '') {
            $query->where('subject_id', (int) $subjectId);
        }

        $causerId = $request->query('causer_id');
        if ($causerId !== null && $causerId !== '') {
            $query->where('causer_id', (int) $causerId);
        }

        return ActivityLogResource::collection($query->paginate(20));
    }

    /**
     * GET /api/admin/logs/failed-jobs
     *
     * Laravel's `failed_jobs` table is unchanged from the framework
     * default — we read straight off it with DB::table() (no Eloquent
     * model needed) and let the resource parse the payload for
     * display-ready fields.
     *
     * Read-only in H1; retry + forget ship in H2's maintenance wizard.
     */
    public function failedJobs(Request $request): JsonResponse|AnonymousResourceCollection
    {
        if (! Schema::hasTable('failed_jobs')) {
            // failed_jobs is a framework table; its absence usually means
            // the queue wasn't migrated. Return an informative empty
            // page rather than 500.
            return response()->json([
                'data' => [],
                'meta' => [
                    'current_page' => 1,
                    'per_page' => 20,
                    'total' => 0,
                    'last_page' => 1,
                ],
                'note' => 'failed_jobs table not installed',
            ]);
        }

        try {
            $paginator = DB::table('failed_jobs')
                ->orderByDesc('id')
                ->paginate(20);
        } catch (\Throwable $e) {
            Log::error('LogViewerController::failedJobs failed', ['exception' => $e]);

            return response()->json([
                'message' => 'Failed to read failed_jobs.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return FailedJobResource::collection($paginator);
    }

    /**
     * Trim a mixed scalar from a query string to either a non-empty
     * string or null. Centralised so every filter treats "" and absent
     * identically.
     */
    private function trimString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
