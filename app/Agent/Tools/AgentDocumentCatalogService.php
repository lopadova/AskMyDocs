<?php

declare(strict_types=1);

namespace App\Agent\Tools;

use App\Agent\AgentExecutionContext;
use App\Models\KnowledgeDocument;
use App\Support\LikeEscaper;

/**
 * Backs the `list_knowledge_documents` agent tool — a document CATALOG
 * lookup (by title, deliberately not by content), distinct from
 * `search_knowledge_base`'s semantic content search.
 *
 * Exists because there was no way for the chat agent to answer a genuine
 * catalog/overview question ("what manuals do we have", "elenca i moduli",
 * "riassunto dei manuali") — `search_knowledge_base` only ever returns
 * chunks similar to ONE query embedding, so there is no query string that
 * means "everything". At a high investigation depth the planner would
 * instead guess plausible manual names and search_knowledge_base each one
 * blindly, burning the whole budget without ever assembling a real list.
 *
 * Covers BOTH canonical and non-canonical/auto-tier documents — unlike
 * `App\Mcp\Tools\KbDocumentsByTypeTool` (the external MCP server's
 * equivalent, which is canonical-only and requires a `type`), a "manual"
 * a user is asking about may well be a raw or auto-compiled document that
 * was never promoted through the canonical pipeline.
 */
final class AgentDocumentCatalogService
{
    private const DEFAULT_LIMIT = 30;

    private const MAX_LIMIT = 100;

    /**
     * @return array{count: int, documents: list<array<string, mixed>>}
     */
    public function list(AgentExecutionContext $context, ?string $titleQuery, ?int $limit): array
    {
        $limit = max(1, min($limit ?? self::DEFAULT_LIMIT, self::MAX_LIMIT));

        $query = KnowledgeDocument::query()
            ->forTenant($context->tenantId)
            ->where('status', '!=', 'archived');

        if ($context->projectKey !== null && $context->projectKey !== '') {
            $query->where('project_key', $context->projectKey);
        }

        $needle = trim((string) $titleQuery);
        if ($needle !== '') {
            // R19 — LikeEscaper (escape char `~`, not backslash — see that
            // class for why backslash breaks under PDO/Postgres). Case-fold
            // both sides: SQLite's LIKE is case-insensitive by default,
            // Postgres's is case-sensitive.
            $pattern = LikeEscaper::contains(mb_strtolower($needle));
            $query->whereRaw('LOWER(title) LIKE ? '.LikeEscaper::ESCAPE_SQL, [$pattern]);
        }

        $documents = $query
            ->orderByDesc('is_canonical')
            ->orderByDesc('retrieval_priority')
            ->orderByDesc('indexed_at')
            ->orderBy('title')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        return [
            'count' => $documents->count(),
            'documents' => $documents->map(static fn (KnowledgeDocument $doc): array => [
                'id' => $doc->id,
                'title' => $doc->title,
                'source_path' => $doc->source_path,
                'source_type' => $doc->source_type,
                'is_canonical' => (bool) $doc->is_canonical,
                'canonical_type' => $doc->canonical_type,
                'generation_source' => $doc->generation_source ?? 'human',
                'summary' => data_get($doc->frontmatter_json, '_derived.summary')
                    ?? data_get($doc->frontmatter_json, 'summary'),
            ])->values()->all(),
        ];
    }
}
