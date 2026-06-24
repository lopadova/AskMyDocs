<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Services\Kb\Pii\ReembedProjectService;
use App\Support\TenantContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

/**
 * v8.23 (Ciclo 4, PR5) — MCP surface (R44) to re-embed a project under the
 * current PII ingestion policy, over the SAME {@see ReembedProjectService} core
 * as the HTTP endpoint and the `kb:reembed-project` CLI.
 *
 * A write (it queues jobs that mutate the vector store), so NOT annotated
 * `#[IsReadOnly]` → the host `McpToolAuthorizerAdapter` requires super-admin.
 * Tenant-scoped (R30); returns the number of documents queued.
 */
#[Description('Re-embed a project\'s documents under the current PII ingestion policy (e.g. after switching mask⇄tokenise), so chunks + embeddings are re-derived from disk. Queues one job per live document and returns the count. Tenant-scoped; super-admin only.')]
class KbReembedProjectTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_key' => $schema->string()
                ->description('The project_key whose documents to re-embed (tenant-scoped).')
                ->required(),
        ];
    }

    public function handle(Request $request, ReembedProjectService $service, TenantContext $tenants): Response
    {
        $projectKey = (string) $request->get('project_key');
        if (trim($projectKey) === '') {
            return Response::error('project_key is required.');
        }

        $tenantId = $tenants->current();
        $queued = $service->reembedProject($tenantId, $projectKey);

        return Response::json([
            'tenant_id' => $tenantId,
            'project_key' => $projectKey,
            'queued' => $queued,
        ]);
    }
}
