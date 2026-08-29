<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\UnmappedSourcePrincipal;
use App\Services\Kb\Access\SourceAclTriageService;
use App\Support\TenantContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * v8.33 / ADR 0028 phase 2 — MCP read surface (R44) for the source-ACL
 * triage queue.
 *
 * Read-only by design, and not merely by omission. Resolving a triage entry
 * grants a person access to documents, which is exactly the kind of decision
 * ADR 0023/0024 keeps outside the agent trust boundary: an agent may report
 * that a question is outstanding, never answer it.
 *
 * Degrades cleanly when nothing has ever been mirrored (R43): zero counts and
 * an empty queue are a valid answer, not an error.
 */
#[Description('Report principals that a connected source named on a document but which could not be matched to an internal user or role, so an operator can decide about them. Also reports how many documents currently have their readers dictated by their source. Read-only: it cannot grant access.')]
#[IsReadOnly]
#[IsIdempotent]
class KbSourceAclTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_key' => $schema->string()
                ->description('Limit the queue to one project. Omit for the whole tenant.'),
            'status' => $schema->string()
                ->description('Which entries to list: pending (default) or ignored.')
                ->default(UnmappedSourcePrincipal::STATUS_PENDING),
            'limit' => $schema->integer()
                ->description('Max entries to list (1-200).')
                ->default(50),
        ];
    }

    public function handle(
        Request $request,
        SourceAclTriageService $triage,
        TenantContext $tenants,
    ): Response {
        $projectKey = $request->get('project_key');
        $projectKey = is_string($projectKey) && $projectKey !== '' ? $projectKey : null;

        $status = $request->get('status');
        $status = in_array($status, UnmappedSourcePrincipal::STATUSES, true)
            ? $status
            : UnmappedSourcePrincipal::STATUS_PENDING;

        $limit = (int) ($request->get('limit') ?? 50);

        $queue = $triage->queue($projectKey, $status, $limit)
            ->map(static fn (UnmappedSourcePrincipal $row): array => [
                'id' => $row->id,
                'document_id' => $row->knowledge_document_id,
                'document_title' => $row->document?->title,
                'project_key' => $row->project_key,
                // As the SOURCE described it, not as an internal subject type
                // — the point of the entry is that no such mapping was found.
                'principal_type' => $row->principal_type,
                'principal' => $row->principal_external_id,
                'effect' => $row->effect,
                'status' => $row->status,
                'first_seen_at' => $row->first_seen_at?->toIso8601String(),
                'last_seen_at' => $row->last_seen_at?->toIso8601String(),
            ])
            ->all();

        return Response::json([
            'tenant_id' => $tenants->current(),
            'summary' => $triage->summary(),
            'status' => $status,
            'project_key' => $projectKey,
            'queue' => $queue,
        ]);
    }
}
