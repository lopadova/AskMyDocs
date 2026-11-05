<?php

declare(strict_types=1);

namespace App\Services\Kb\Embedding;

use App\Jobs\ReembedDocumentJob;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Scopes\AccessScopeScope;
use App\Services\Kb\EmbeddingCacheService;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * v8.42/W2 (ADR 0035 §D2) — finds and re-embeds chunks whose vector was
 * produced under a DIFFERENT recipe than the one active now.
 *
 * "Stale" = `knowledge_chunks.embedding_fingerprint` is NULL (written before
 * v8.42, recipe unknown) or differs from the active fingerprint (provider /
 * model / input template / chunking parameters changed since). The active
 * fingerprint is computed WITHOUT a provider call
 * ({@see EmbeddingCacheService::activeTarget()}), so reporting costs nothing.
 *
 * The ONE core behind the CLI (`kb:reembed-stale`) and the additive
 * `stale_embeddings` key on the ingestion-status surfaces (CLI + HTTP + MCP,
 * R44). Re-embedding reuses the existing {@see ReembedDocumentJob}: it
 * re-derives the document from the stored source under the current policy,
 * re-chunks and re-embeds (cache hits are free; with the contextual template
 * on, the changed inputs are provider calls — hence `--dry-run`).
 *
 * Tenant-scoped (R30) via the explicit `$tenantId`; streams documents with
 * `chunkById(100)` (R3); never loads chunk text into PHP (aggregates in SQL).
 */
final class ReembedStaleService
{
    public function __construct(
        private readonly EmbeddingCacheService $embeddings,
        private readonly EmbeddingInputTemplate $template,
    ) {}

    /** The fingerprint a chunk embedded RIGHT NOW would carry. */
    public function activeFingerprint(): string
    {
        $target = $this->embeddings->activeTarget();

        return $this->template->fingerprint($target['provider'], $target['model']);
    }

    /**
     * Read-only summary. Safe to call from a status endpoint: two aggregate
     * queries, no provider call, no chunk text.
     *
     * @return array{
     *   active_fingerprint: string,
     *   template: string,
     *   total_chunks: int,
     *   stale_chunks: int,
     *   stale_documents: int,
     *   estimated_tokens: int,
     *   estimated_cost: float|null
     * }
     */
    public function summary(string $tenantId, ?string $projectKey = null): array
    {
        $active = $this->activeFingerprint();

        $stale = $this->staleChunks($tenantId, $projectKey, $active);

        $row = (clone $stale)
            ->selectRaw('COUNT(*) as chunks, COUNT(DISTINCT knowledge_document_id) as docs')
            ->first();

        // Re-embedding re-derives the WHOLE document, so the cost basis is
        // every chunk of every stale document, not only the stale chunks.
        $tokens = $this->estimatedTokens($tenantId, $projectKey, $active);

        $price = config('kb.embedding_input.price_per_million_tokens');

        return [
            'active_fingerprint' => $active,
            'template' => $this->template->templateId(),
            'total_chunks' => $this->chunksOf($tenantId, $projectKey)->count(),
            'stale_chunks' => (int) ($row->chunks ?? 0),
            'stale_documents' => (int) ($row->docs ?? 0),
            'estimated_tokens' => $tokens,
            'estimated_cost' => is_numeric($price) ? round($tokens / 1_000_000 * (float) $price, 4) : null,
        ];
    }

    /**
     * Queue a re-embed for every document that owns at least one stale chunk.
     * `$dryRun` reports the same numbers and queues nothing.
     *
     * @return array{queued: int, dry_run: bool, summary: array<string,mixed>}
     */
    public function run(string $tenantId, ?string $projectKey = null, int $batch = 100, bool $dryRun = false): array
    {
        $summary = $this->summary($tenantId, $projectKey);
        if ($dryRun) {
            return ['queued' => 0, 'dry_run' => true, 'summary' => $summary];
        }

        $active = $summary['active_fingerprint'];
        $queued = 0;
        $size = max(1, min(500, $batch));

        $this->staleDocuments($tenantId, $projectKey, $active)
            ->select('knowledge_documents.id')
            ->chunkById($size, function ($documents) use (&$queued, $tenantId): void {
                foreach ($documents as $document) {
                    ReembedDocumentJob::dispatch((int) $document->id, $tenantId);
                    $queued++;
                }
            }, 'knowledge_documents.id', 'id');

        return ['queued' => $queued, 'dry_run' => false, 'summary' => $summary];
    }

    /** @return Builder<KnowledgeChunk> */
    private function chunksOf(string $tenantId, ?string $projectKey): Builder
    {
        return KnowledgeChunk::query()
            ->forTenant($tenantId)
            ->when($projectKey !== null && $projectKey !== '', fn (Builder $q) => $q->where('knowledge_chunks.project_key', $projectKey));
    }

    /** @return Builder<KnowledgeChunk> */
    private function staleChunks(string $tenantId, ?string $projectKey, string $active): Builder
    {
        return $this->chunksOf($tenantId, $projectKey)
            ->whereHas('document', fn (Builder $d) => $d->withoutGlobalScope(AccessScopeScope::class)->forTenant($tenantId)->where('status', 'active'))
            ->where(fn (Builder $q) => $q->whereNull('knowledge_chunks.embedding_fingerprint')
                ->orWhere('knowledge_chunks.embedding_fingerprint', '!=', $active));
    }

    /** @return Builder<KnowledgeDocument> */
    private function staleDocuments(string $tenantId, ?string $projectKey, string $active): Builder
    {
        return KnowledgeDocument::query()
            ->withoutGlobalScope(AccessScopeScope::class)
            ->forTenant($tenantId)
            ->where('status', 'active')
            ->when($projectKey !== null && $projectKey !== '', fn (Builder $q) => $q->where('knowledge_documents.project_key', $projectKey))
            ->whereExists(function ($sub) use ($tenantId, $active): void {
                $sub->selectRaw('1')
                    ->from('knowledge_chunks')
                    ->whereColumn('knowledge_chunks.knowledge_document_id', 'knowledge_documents.id')
                    ->where('knowledge_chunks.tenant_id', $tenantId)
                    ->where(fn ($q) => $q->whereNull('knowledge_chunks.embedding_fingerprint')
                        ->orWhere('knowledge_chunks.embedding_fingerprint', '!=', $active));
            });
    }

    private function estimatedTokens(string $tenantId, ?string $projectKey, string $active): int
    {
        $chars = (int) KnowledgeChunk::query()
            ->forTenant($tenantId)
            ->when($projectKey !== null && $projectKey !== '', fn (Builder $q) => $q->where('knowledge_chunks.project_key', $projectKey))
            ->whereIn('knowledge_chunks.knowledge_document_id', $this->staleDocuments($tenantId, $projectKey, $active)->select('knowledge_documents.id'))
            ->sum(\Illuminate\Support\Facades\DB::raw('LENGTH(chunk_text)'));

        // ~4 characters per token — the same coarse estimate the chunker uses.
        return intdiv($chars, 4);
    }
}
