<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Resources\Admin\Logs\ActivityLogResource;
use App\Http\Resources\Admin\Logs\AuditLogResource;
use App\Http\Resources\Admin\Logs\ChatLogResource;
use App\Http\Resources\Admin\Logs\FailedJobResource;
use App\Models\AdminCommandAudit;
use App\Models\ChatLog;
use App\Models\KbCanonicalAudit;
use App\Services\Admin\Exceptions\LogFileNotFoundException;
use App\Services\Admin\Exceptions\LogFileUnreadableException;
use App\Services\Admin\LogTailService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Padosoft\PiiRedactor\Strategies\RedactionStrategy;
use Padosoft\PiiRedactor\Strategies\TokeniseStrategy;
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
        } catch (LogFileNotFoundException $e) {
            // Copilot #7 fix: distinct exception types replace the
            // previous message-prefix sniffing branch. The mapping
            // stays stable when copy changes ("Log file not found"
            // → "Missing file" — refactor safe).
            return response()->json(['message' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        } catch (LogFileUnreadableException $e) {
            // R4 — I/O failure surfaces loudly as 500, not faked as success.
            return response()->json(['message' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
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
     * POST /api/admin/logs/chat/{id}/detokenize
     *
     * v4.1/W4.1.D — operator-driven round-trip from a tokenised
     * `chat_logs.question` / `chat_logs.answer` back to the original
     * PII text. Surfaces ONLY when:
     *
     *   1. The package's `tokenise` strategy is configured (otherwise
     *      there's no `pii_token_maps` content to reverse) — 422.
     *   2. The caller carries the Spatie permission named in
     *      `kb.pii_redactor.detokenize_permission` (default
     *      `pii.detokenize`) — 403 otherwise.
     *
     * Audit shape: every 200 (success) or 403 (rejected by permission
     * gate) writes an `admin_command_audit` row. The 422 strategy-
     * mismatch preflight is a config-stage error (no row matched, no
     * action taken), and is intentionally NOT audited — the SPA
     * surfaces it as a static "this deploy does not retain originals"
     * note rather than a per-request operator action.
     *
     * R30 — `chat_logs` is tenant-aware; the row lookup is scoped to
     * the active tenant so an admin in tenant A cannot detokenise a
     * chat row owned by tenant B by guessing its id.
     *
     * Response shape (200): `{ id, question, answer }` — same scalar
     * fields as the chat-show drawer, but with the `[tok:*:*]` literals
     * substituted by their plaintext originals from `pii_token_maps`.
     */
    public function chatDetokenize(Request $request, int $id): JsonResponse
    {
        $strategy = app(RedactionStrategy::class);
        if (! $strategy instanceof TokeniseStrategy) {
            // Mask / Hash / Drop are one-way — there's nothing to
            // reverse. Surface a 422 so the SPA can show "this deploy
            // does not retain originals" instead of pretending success.
            return response()->json([
                'message' => 'PII detokenisation requires the `tokenise` strategy.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $permission = (string) config('kb.pii_redactor.detokenize_permission', 'pii.detokenize');
        $user = $request->user();
        $hasPermission = $user !== null
            && method_exists($user, 'can')
            && $user->can($permission);

        $tenantId = app(TenantContext::class)->current();
        $log = ChatLog::query()->forTenant($tenantId)->findOrFail($id);

        if (! $hasPermission) {
            // Audit the rejection too — abuse attempts are visible
            // in the same trail as successful unmasks.
            AdminCommandAudit::query()->create([
                'user_id' => $user?->id,
                'command' => 'pii.detokenize',
                'args_json' => ['chat_log_id' => $id],
                'status' => AdminCommandAudit::STATUS_REJECTED,
                'error_message' => "Missing permission: {$permission}",
                'started_at' => now(),
                'completed_at' => now(),
                'client_ip' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
            ]);

            return response()->json([
                'message' => "Forbidden: missing {$permission} permission.",
            ], Response::HTTP_FORBIDDEN);
        }

        $question = is_string($log->question) ? $strategy->detokeniseString($log->question) : null;
        $answer = is_string($log->answer) ? $strategy->detokeniseString($log->answer) : null;

        AdminCommandAudit::query()->create([
            'user_id' => $user?->id,
            'command' => 'pii.detokenize',
            'args_json' => ['chat_log_id' => $id],
            'status' => AdminCommandAudit::STATUS_COMPLETED,
            'started_at' => now(),
            'completed_at' => now(),
            'client_ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
        ]);

        return response()->json([
            'id' => $log->id,
            'question' => $question,
            'answer' => $answer,
        ]);
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
