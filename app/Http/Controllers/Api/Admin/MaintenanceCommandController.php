<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Models\AdminCommandAudit;
use App\Services\Admin\CommandRunnerForbidden;
use App\Services\Admin\CommandRunnerService;
use App\Services\Admin\CommandRunnerUnknown;
use App\Services\Admin\CommandRunnerValidation;
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
        $query = AdminCommandAudit::query()->orderByDesc('id');

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
     * Returns the static schedule declared in bootstrap/app.php as a
     * list of `{command, cron_time, description}`. Computing the next
     * execution time would require pulling the framework scheduler
     * into request-time, which is overkill for an ops widget.
     */
    public function schedulerStatus(Request $request): JsonResponse
    {
        return response()->json([
            'data' => [
                ['command' => 'kb:prune-embedding-cache', 'cron_time' => '03:10', 'description' => 'Embedding cache retention (LRU).'],
                ['command' => 'chat-log:prune', 'cron_time' => '03:20', 'description' => 'Chat log retention (default 90d).'],
                ['command' => 'kb:prune-deleted', 'cron_time' => '03:30', 'description' => 'Hard-delete soft-deleted KB docs past retention.'],
                ['command' => 'kb:rebuild-graph', 'cron_time' => '03:40', 'description' => 'Recompute kb_nodes + kb_edges from canonical frontmatter.'],
                ['command' => 'queue:prune-failed --hours=48', 'cron_time' => '04:00', 'description' => 'Rotate the failed_jobs table.'],
                ['command' => 'kb:prune-orphan-files --dry-run', 'cron_time' => '04:40', 'description' => 'Dry-run orphan scan.'],
                ['command' => 'admin-audit:prune --days=365', 'cron_time' => '04:30', 'description' => 'Rotate admin_command_audit (Phase H2).'],
                ['command' => 'admin-nonces:prune', 'cron_time' => '04:50', 'description' => 'Purge expired/used admin_command_nonces.'],
            ],
        ]);
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
