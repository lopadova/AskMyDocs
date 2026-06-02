<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Models\AdminCommandAudit;
use App\Services\Admin\CommandRunnerForbidden;
use App\Services\Admin\CommandRunnerService;
use App\Services\Admin\CommandRunnerUnknown;
use App\Services\Admin\CommandRunnerValidation;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Phase H2 — admin maintenance command runner.
 *
 * Thin controller. Every security guarantee lives in
 * {@see CommandRunnerService}; this file exists to marshal HTTP
 * request/response shapes and map the service's three exception
 * classes to 404/403/422.
 *
 * Status-code matrix:
 *   - CommandRunnerUnknown      → 404  (whitelist miss)
 *   - CommandRunnerForbidden    → 403  (permission gate)
 *   - CommandRunnerValidation   → 422  (schema / token)
 *   - Throwable during /run      → 500  (audit row already carries `failed`)
 *
 * Rejected-audit rows are written INSIDE the service (so that abuse
 * attempts survive a crashing controller). The controller just surfaces
 * the HTTP response.
 */
class MaintenanceCommandController extends Controller
{
    public function __construct(
        private readonly CommandRunnerService $runner,
    ) {}

    /**
     * GET /api/admin/commands/catalogue
     *
     * Returns the list of whitelisted commands the caller is allowed
     * to run, with their args schema + destructive flag + description.
     * The wizard's Step 1 renders a form derived from `args_schema`.
     *
     * Never leaks commands the caller doesn't have permission for.
     */
    public function catalogue(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['message' => 'Unauthenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        return response()->json([
            'data' => $this->runner->catalogueFor($user),
        ]);
    }

    /**
     * POST /api/admin/commands/preview
     *
     * Body: `{command: string, args: object}`.
     * Writes nothing — pure validation. Returns a `confirm_token` for
     * destructive commands (5m TTL, single-use).
     */
    public function preview(Request $request): JsonResponse
    {
        $payload = $this->validatePayload($request);
        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        try {
            $preview = $this->runner->preview($payload['command'], $payload['args'], $request->user());
        } catch (CommandRunnerUnknown $e) {
            return response()->json(['message' => 'Command not found.'], Response::HTTP_NOT_FOUND);
        } catch (CommandRunnerForbidden $e) {
            return response()->json(['message' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        } catch (CommandRunnerValidation $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => $e->errors,
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json($preview);
    }

    /**
     * POST /api/admin/commands/run
     *
     * Body: `{command: string, args: object, confirm_token?: string}`.
     * Writes an audit row BEFORE invoking Artisan (R10-aligned), flips
     * status to completed|failed after Artisan returns. Rate-limited
     * via route middleware.
     */
    public function run(Request $request): JsonResponse
    {
        $payload = $this->validatePayload($request);
        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        $confirmToken = $request->input('confirm_token');
        if ($confirmToken !== null && ! is_string($confirmToken)) {
            return response()->json([
                'message' => 'confirm_token must be a string.',
                'errors' => ['confirm_token' => ['type']],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $result = $this->runner->run(
                command: $payload['command'],
                args: $payload['args'],
                confirmToken: $confirmToken,
                user: $request->user(),
                clientIp: (string) ($request->ip() ?? ''),
                userAgent: (string) ($request->userAgent() ?? ''),
            );
        } catch (CommandRunnerUnknown $e) {
            $this->runner->rejectAudit(
                $payload['command'],
                $payload['args'],
                $request->user(),
                'unknown command',
                (string) ($request->ip() ?? ''),
                (string) ($request->userAgent() ?? ''),
            );

            return response()->json(['message' => 'Command not found.'], Response::HTTP_NOT_FOUND);
        } catch (CommandRunnerForbidden $e) {
            $this->runner->rejectAudit(
                $payload['command'],
                $payload['args'],
                $request->user(),
                'permission denied',
                (string) ($request->ip() ?? ''),
                (string) ($request->userAgent() ?? ''),
            );

            return response()->json(['message' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        } catch (CommandRunnerValidation $e) {
            // rejectAudit was already written inside the service where
            // relevant (bad token states). For pure schema failures the
            // service has not yet written — log an attempt here too.
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => $e->errors,
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (Throwable $e) {
            // The service already flipped the audit row to `failed`.
            return response()->json([
                'message' => 'Command execution failed.',
                'detail' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return response()->json($result, Response::HTTP_OK);
    }

    /**
     * GET /api/admin/commands/history
     *
     * Paginated AdminCommandAudit rows. Filters: command, status,
     * from, to.
     */
    public function history(Request $request): AnonymousResourceCollection|JsonResponse
    {
        // R30 — admin_command_audit is tenant-aware; without forTenant the
        // history tab leaks every tenant's command audit trail.
        $query = AdminCommandAudit::query()
            ->forTenant(app(TenantContext::class)->current())
            ->orderByDesc('id');

        $command = $this->trim($request->query('command'));
        if ($command !== null) {
            $query->where('command', $command);
        }
        $status = $this->trim($request->query('status'));
        if ($status !== null) {
            $query->where('status', $status);
        }
        $from = $this->trim($request->query('from'));
        if ($from !== null) {
            $query->where('started_at', '>=', $from);
        }
        $to = $this->trim($request->query('to'));
        if ($to !== null) {
            $query->where('started_at', '<=', $to);
        }

        $rows = $query->paginate(20);

        // Lightweight resource shape inline — no dedicated Resource
        // class because the audit row has no computed / derived fields.
        return response()->json([
            'data' => $rows->items(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'per_page' => $rows->perPage(),
                'total' => $rows->total(),
                'last_page' => $rows->lastPage(),
            ],
        ]);
    }

    /**
     * GET /api/admin/commands/scheduler-status
     *
     * Returns the effective host-side schedule as a list of
     * `{command, cron_time, cron_expression, description}`. Both
     * `cron_time` (HH:MM for daily-at-fixed-time slots, raw 5-field
     * cron otherwise) and `cron_expression` (always the raw 5-field
     * cron) are populated. Sourced from `config('askmydocs.schedule')`
     * so env overrides (W2.4 Tier-1) surface in the ops widget
     * instead of drifting from the literal cron times in
     * `bootstrap/app.php`. Composite-gated slots (eval_nightly,
     * ai_act_regulatory_poll) appear only when their upstream env
     * gate is also on — mirrors bootstrap's dual-gate behaviour.
     */
    public function schedulerStatus(Request $request): JsonResponse
    {
        // Description map: operator-facing human strings, kept local
        // to the controller (these aren't configuration values). The
        // SLOT inventory itself comes from `TierOneSchedulerRegistrar::slots()`
        // so a missing description map entry is the only drift surface
        // — the registrar + config + this method now agree on the slot
        // set (Copilot iter-3 #L242 — slot metadata duplication).
        $descriptionMap = [
            'kb_prune_embedding_cache' => 'Embedding cache retention (LRU).',
            'chat_log_prune' => 'Chat log retention (default 90d).',
            'kb_prune_deleted' => 'Hard-delete soft-deleted KB docs past retention.',
            'kb_rebuild_graph' => 'Recompute kb_nodes + kb_edges from canonical frontmatter.',
            'kb_health_recompute' => 'Recompute canonical health snapshot + decision-debt score.',
            'queue_prune_failed' => 'Rotate the failed_jobs table.',
            'kb_prune_orphan_files' => 'Dry-run orphan scan.',
            // Copilot earlier fix: bootstrap/app.php runs `admin-audit:prune`
            // with no `--days` arg (the command reads
            // ADMIN_AUDIT_RETENTION_DAYS from env). Previously this string
            // said `--days=365`, so the UI grid was lying about what the
            // scheduler actually executes.
            'admin_audit_prune' => 'Rotate admin_command_audit (Phase H2).',
            'admin_nonces_prune' => 'Purge expired/used admin_command_nonces.',
            'notifications_prune' => 'Rotate notification_events past retention (W1.5).',
            'compliance_digest_quarterly' => 'Quarterly compliance digest generation (W8.5).',
            'insights_compute' => 'Daily AI-insights snapshot (Phase I).',
            // v8.7/W2 — KB lifecycle.
            'kb_stale_review_sweep' => 'Flag documents untouched beyond the staleness window (v8.7/W2).',
            'notifications_digest_weekly' => 'Weekly per-user notification digest email (v8.7/W2).',
            // v8.7/W5 — Cloud Time Machine retention.
            'kb_prune_archived_versions' => 'Hard-delete archived document versions beyond the retention cap (v8.7/W5).',
            // Composite-gated slots — only registered when the
            // upstream env flag is also on (see bootstrap/app.php).
            // Listed here so they appear in the widget when active.
            'eval_nightly' => 'Nightly eval-harness regression run (live-mode opt-in).',
            'ai_act_regulatory_poll' => 'EU AI Act regulatory-feed daily poll.',
        ];

        $scheduleConfig = (array) config('askmydocs.schedule', []);
        $data = [];

        // Base Tier-1 slots: always reflect `config(...enabled)`.
        foreach (\App\Scheduling\TierOneSchedulerRegistrar::slots() as [$slotKey, $command]) {
            $row = $this->buildSchedulerRow(
                $slotKey,
                $command,
                $descriptionMap,
                $scheduleConfig,
            );
            if ($row !== null) {
                $data[] = $row;
            }
        }

        // Composite-gated slots: ALSO require the upstream env flag.
        // Mirrors bootstrap/app.php so the widget shows exactly what
        // the scheduler will actually run, not just what config says
        // (Copilot iter-4 — eval_nightly + ai_act_regulatory_poll
        // were missing from the response despite being part of the
        // scheduler domain).
        foreach (\App\Scheduling\TierOneSchedulerRegistrar::compositeGatedSlots() as [$slotKey, $command, $upstreamConfigKey]) {
            if (! (bool) config($upstreamConfigKey, false)) {
                continue;
            }
            $row = $this->buildSchedulerRow(
                $slotKey,
                $command,
                $descriptionMap,
                $scheduleConfig,
            );
            if ($row !== null) {
                $data[] = $row;
            }
        }

        return response()->json(['data' => $data]);
    }

    /**
     * Build one row of the scheduler-status response. Returns null
     * when the slot is disabled via `config(...enabled = false)`.
     *
     * R14 + R18 — fails closed on missing cron or missing description
     * (programmer error indicating a registrar / config drift).
     *
     * @param  array<string, string>  $descriptionMap
     * @param  array<string, mixed>   $scheduleConfig
     * @return array<string, mixed>|null
     */
    private function buildSchedulerRow(
        string $slotKey,
        string $command,
        array $descriptionMap,
        array $scheduleConfig,
    ): ?array {
        $slot = (array) ($scheduleConfig[$slotKey] ?? []);
        if (! (bool) ($slot['enabled'] ?? true)) {
            return null;
        }

        if (! array_key_exists($slotKey, $descriptionMap)) {
            throw new \RuntimeException(
                "schedulerStatus: slot `{$slotKey}` has no description entry in this controller — add it to `\$descriptionMap` to match the SLOT inventory."
            );
        }

        $expression = (string) ($slot['cron'] ?? '');
        if ($expression === '') {
            throw new \RuntimeException(
                "schedulerStatus: enabled Tier-1 slot `{$slotKey}` has no `cron` entry in `config('askmydocs.schedule')`. Add it to `config/askmydocs.php` and document the matching `SCHEDULE_*_CRON` env var in `.env.example`."
            );
        }

        return [
            'command' => $command,
            // `cron_time` is the human-readable HH:MM string the
            // existing 60px `SchedulerStatusCard` column was sized
            // for. Falls back to the full expression when the cron
            // isn't a daily-at-fixed-time pattern — operators who
            // set a non-daily cron see the actual expression and
            // can adjust the FE layout if needed (Copilot iter-3
            // #L256 — UI width mismatch). `cron_expression` is
            // always the raw 5-field string so advanced tooling
            // doesn't have to round-trip the conversion.
            'cron_time' => $this->cronToHumanTime($expression),
            'cron_expression' => $expression,
            'description' => $descriptionMap[$slotKey],
        ];
    }

    /**
     * Render a 5-field cron expression as `HH:MM` when it represents
     * a fixed-time daily schedule (e.g. `10 4 * * *` → `04:10`).
     * Returns the raw expression unchanged for any other pattern.
     */
    private function cronToHumanTime(string $expression): string
    {
        $parts = preg_split('/\s+/', trim($expression)) ?: [];
        if (count($parts) !== 5) {
            return $expression;
        }
        [$minute, $hour, $dayOfMonth, $month, $dayOfWeek] = $parts;
        $isDailyFixed = $dayOfMonth === '*'
            && $month === '*'
            && $dayOfWeek === '*'
            && ctype_digit($minute)
            && ctype_digit($hour)
            && (int) $minute <= 59
            && (int) $hour <= 23;

        if (! $isDailyFixed) {
            return $expression;
        }

        return sprintf('%02d:%02d', (int) $hour, (int) $minute);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * @return array{command: string, args: array<string, mixed>}|JsonResponse
     */
    private function validatePayload(Request $request): array|JsonResponse
    {
        $command = $request->input('command');
        if (! is_string($command) || trim($command) === '') {
            return response()->json([
                'message' => 'command is required.',
                'errors' => ['command' => ['required']],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $args = $request->input('args', []);
        if (! is_array($args)) {
            return response()->json([
                'message' => 'args must be an object.',
                'errors' => ['args' => ['type']],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return ['command' => trim($command), 'args' => $args];
    }

    private function trim(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
