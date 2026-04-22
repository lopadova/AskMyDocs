<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\KnowledgeDocument;
use App\Support\Canonical\CanonicalType;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('List canonical documents of a given type within a project, sorted by retrieval_priority + recency.')]
#[IsReadOnly]
#[IsIdempotent]
class KbDocumentsByTypeTool extends Tool
{
    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT = 200;

    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()
                ->description('One of the 9 canonical types: decision, module-kb, runbook, standard, incident, integration, domain-concept, rejected-approach, project-index.')
                ->required(),
            'project_key' => $schema->string()
                ->description('Project scope — REQUIRED.')
                ->required(),
            'status_filter' => $schema->string()
                ->description('Canonical status filter: accepted (default), review, draft, superseded, deprecated, archived, or "all" for any status.')
                ->default('accepted'),
            'limit' => $schema->integer()
                ->description('Max documents to return (default 50, max 200).')
                ->default(self::DEFAULT_LIMIT),
        ];
    }

    public function handle(Request $request): Response
    {
        $type = (string) $request->get('type');
        $projectKey = (string) $request->get('project_key');
        if ($type === '' || $projectKey === '') {
            return Response::json(['error' => 'type and project_key are required', 'documents' => []]);
        }
        if (CanonicalType::tryFrom($type) === null) {
            return Response::json(['error' => 'invalid_type', 'allowed_types' => array_map(fn ($c) => $c->value, CanonicalType::cases())]);
        }

        $statusFilter = (string) ($request->get('status_filter') ?? 'accepted');
        $limit = min((int) ($request->get('limit') ?? self::DEFAULT_LIMIT), self::MAX_LIMIT);

        $query = KnowledgeDocument::query()
            ->where('project_key', $projectKey)
            ->where('is_canonical', true)
            ->where('canonical_type', $type)
            ->where('status', '!=', 'archived');

        if ($statusFilter !== 'all') {
            $query->where('canonical_status', $statusFilter);
        }

        $docs = $query
            ->orderByDesc('retrieval_priority')
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        return Response::json([
            'type' => $type,
            'project_key' => $projectKey,
            'status_filter' => $statusFilter,
            'count' => $docs->count(),
            'documents' => $docs->map(fn (KnowledgeDocument $doc) => [
                'id' => $doc->id,
                'doc_id' => $doc->doc_id,
                'slug' => $doc->slug,
                'title' => $doc->title,
                'source_path' => $doc->source_path,
                'canonical_status' => $doc->canonical_status,
                'retrieval_priority' => $doc->retrieval_priority,
                'summary' => $doc->frontmatter_json['_derived']['summary'] ?? ($doc->frontmatter_json['summary'] ?? null),
                'tags' => $doc->frontmatter_json['_derived']['tags'] ?? ($doc->frontmatter_json['tags'] ?? []),
                'indexed_at' => $doc->indexed_at?->toIso8601String(),
            ])->values()->all(),
        ]);
    }
}
