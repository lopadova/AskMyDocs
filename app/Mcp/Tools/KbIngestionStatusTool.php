<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Services\Admin\IngestionObservabilityService;
use App\Support\TenantContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * v8.21 (Ciclo 2) — MCP read surface (R44) for ingestion / sync observability.
 *
 * The third surface over the same core as the HTTP `ingestion/queue` +
 * `connectors/{id}/sync-runs` endpoints and the `ingestion:status` command:
 * queue depths (backlog) + recent per-account sync runs, tenant-scoped (R30).
 * Degrades cleanly when nothing has run yet (R43 — an empty roster is a valid
 * answer, never an error).
 */
#[Description('Report this tenant\'s ingestion/sync health: pending-job depth for the connector-sync / kb-ingest / default queues, plus the most recent connector sync runs (account label, status, discovered/failed counts, duration). Read-only.')]
#[IsReadOnly]
#[IsIdempotent]
class KbIngestionStatusTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()
                ->description('Max recent sync runs to return (1–100).')
                ->default(20),
        ];
    }

    public function handle(Request $request, IngestionObservabilityService $service, TenantContext $tenants): Response
    {
        $limit = max(1, min(100, (int) ($request->get('limit') ?? 20)));

        return Response::json([
            'tenant_id' => $tenants->current(),
            'queues' => $service->queueDepths(),
            'recent_runs' => $service->recentRuns($limit),
        ]);
    }
}
