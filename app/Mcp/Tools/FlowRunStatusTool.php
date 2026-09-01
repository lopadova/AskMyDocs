<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Flow\Definitions\IngestDocumentFlow;
use App\Flow\Definitions\PromotionFlow;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Schema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Padosoft\LaravelFlow\Dashboard\FlowDashboardReadModel;
use Padosoft\LaravelFlow\Dashboard\Pagination;
use Padosoft\LaravelFlow\Dashboard\RunFilter;
use Padosoft\LaravelFlow\Dashboard\RunSummary;

/**
 * MCP read surface (R44) for Flow run health.
 *
 * The third surface over one core. PHP already has `flow:runs` and the engine
 * itself; HTTP has the flow-admin cockpit. Both read through
 * {@see FlowDashboardReadModel}, and so does this — deliberately, rather than
 * querying the tables directly.
 *
 * That choice is what makes the tenant boundary hold here. The host wires
 * `App\Flow\Admin\TenantScopedDashboardReads` onto that read model, so every
 * query it issues is already constrained to the active tenant (R30). A tool
 * that reached for `DB::table('flow_runs')` instead would have to re-derive
 * the same scoping, and would be the place it eventually drifted.
 *
 * Read-only by construction: the read model exposes no writes. Nothing here
 * can start, cancel, replay or approve a run — those stay behind the cockpit's
 * per-row authorizer, where a human is present. Autonomous replay of a
 * business workflow is outside the agent trust boundary.
 *
 * OFF path (R43): flow persistence is opt-in and default-off, so the tables
 * may legitimately not exist. That returns an empty, well-formed payload with
 * `persistence_enabled: false` rather than throwing — the caller can tell
 * "nothing ran" from "nothing is recorded", which a bare empty list could not.
 *
 * Both branches emit the SAME key set (R27), so a caller parses one shape and
 * reads `persistence_enabled` to interpret it. Only the values differ: counts
 * are null when nothing is recorded, never zero. Zero is a measurement, and a
 * dashboard renders "0 failed" as healthy — which is the opposite of what an
 * unrecorded corpus means. {@see \Tests\Feature\Mcp\FlowRunStatusToolShapeTest}
 * holds both halves of that contract.
 */
#[Description('Report Flow run health for the active tenant: aggregate counts (total, running, paused, failed, compensated, pending approvals, webhook outbox backlog) plus the most recent runs with their status, duration and failed step. Read-only; cannot start, cancel, replay or approve anything.')]
#[IsReadOnly]
#[IsIdempotent]
class FlowRunStatusTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()
                ->description('How many recent runs to list (1–50), newest first.')
                ->default(10),
            'status' => $schema->string()
                ->description("Optional status filter, e.g. 'failed', 'running', 'paused', 'succeeded'."),
            // The examples are the definition constants themselves, not string
            // literals: a literal here is a value an MCP consumer will try, so
            // it has to be a name that actually resolves. The first draft said
            // 'kb.ingest-document', which no definition has ever been called.
            'definition_name' => $schema->string()
                ->description(sprintf(
                    "Optional flow name filter, e.g. '%s' or '%s'. Names come from the nine definitions registered in FlowServiceProvider.",
                    IngestDocumentFlow::NAME,
                    PromotionFlow::NAME,
                )),
        ];
    }

    public function handle(Request $request, FlowDashboardReadModel $reader): Response
    {
        $limit = max(1, min(50, (int) ($request->get('limit') ?? 10)));

        if (! $this->persistenceIsRecording()) {
            return Response::json([
                'persistence_enabled' => false,
                'note' => 'Flow persistence is disabled or its tables are absent, so no run history is recorded. Runs still execute.',
                // Same key set as the recording branch (R27): a caller parses
                // one shape and reads `persistence_enabled` to interpret it,
                // rather than discovering which keys exist by path.
                //
                // Null rather than zero, deliberately. Zeros here are a lie a
                // dashboard will render: "0 failed" reads as healthy, when the
                // truth is that nothing is being recorded and the real count is
                // unknown. Null cannot be mistaken for a measurement.
                'totals' => null,
                'matching_runs' => null,
                'recent_runs' => [],
            ]);
        }

        $filter = new RunFilter(
            definitionName: $this->nullableString($request->get('definition_name')),
            status: $this->nullableString($request->get('status')),
        );

        $page = $reader->listRuns($filter, new Pagination(1, $limit));
        $kpis = $reader->kpis();

        return Response::json([
            'persistence_enabled' => true,
            // Present and null so the key set matches the disabled branch —
            // there is nothing to explain when the numbers are real.
            'note' => null,
            'totals' => [
                'runs' => $kpis->totalRuns,
                'running' => $kpis->runningRuns,
                'paused' => $kpis->pausedRuns,
                'failed' => $kpis->failedRuns,
                'compensated' => $kpis->compensatedRuns,
                'pending_approvals' => $kpis->pendingApprovals,
                'webhook_outbox_pending' => $kpis->webhookOutboxPending,
                'webhook_outbox_failed' => $kpis->webhookOutboxFailed,
            ],
            'matching_runs' => $page->total,
            'recent_runs' => array_map(
                fn (RunSummary $run): array => [
                    'id' => $run->id,
                    'flow' => $run->definitionName,
                    'status' => $run->status,
                    'dry_run' => $run->dryRun,
                    'failed_step' => $run->failedStep,
                    'compensated' => $run->compensated,
                    'duration_ms' => $run->durationMs,
                    'started_at' => $run->startedAt?->format(DATE_ATOM),
                    'finished_at' => $run->finishedAt?->format(DATE_ATOM),
                ],
                $page->items,
            ),
        ]);
    }

    /**
     * Every table the recording branch actually reads.
     *
     * `listRuns()` needs only `flow_runs`, but `kpis()` also counts pending
     * approvals and the webhook outbox — and those live in a DIFFERENT
     * migration (2026_05_09_145344) from `flow_runs` (…_145342), so "some of
     * the flow tables exist" is a reachable state rather than a hypothetical.
     * Guarding on `flow_runs` alone would take the recording branch and then
     * throw inside `kpis()`, which is the opposite of what this tool promises.
     *
     * Deliberately NOT the full flow schema: `flow_run_nodes`,
     * `flow_definitions`, `flow_node_children` and `flow_node_cache` are never
     * read here, and requiring them would refuse to report on a deployment
     * this tool can serve perfectly well.
     *
     * @var list<string>
     */
    private const REQUIRED_TABLES = [
        'flow_runs',
        'flow_approvals',
        'flow_webhook_outbox',
    ];

    /**
     * Both halves matter. The config flag can be on while the tables are
     * absent (a deployment that enabled persistence without migrating), and
     * reading that as "recording" would throw on the first query instead of
     * reporting the state.
     */
    private function persistenceIsRecording(): bool
    {
        if (! (bool) config('laravel-flow.persistence.enabled', false)) {
            return false;
        }

        foreach (self::REQUIRED_TABLES as $table) {
            if (! Schema::hasTable($table)) {
                return false;
            }
        }

        return true;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
