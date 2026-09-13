<?php

declare(strict_types=1);

namespace App\Services\Kb\Versioning;

use App\Models\KbCanonicalAudit;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Support\MarkdownDiff;
use App\Support\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * v8.7/W5 — Cloud Time Machine: browse + restore document versions.
 *
 * Every re-ingest archives the prior `knowledge_documents` row (status
 * `archived`) but RETAINS it + its chunks (see
 * `DocumentIngestor::archivePreviousVersions`). This service reads that
 * retained history: it lists the version timeline for a doc's
 * `(tenant, project_key, source_path)` family, reconstructs a version's
 * content from its chunks, diffs two versions, and restores an archived
 * version (status flip + canonical-identity transfer, transactional +
 * audited). Reuses retained chunks/embeddings — no re-embedding.
 */
final class DocumentVersionService
{
    public const SOURCE_ARTIFACT = 'artifact';

    public const INTEGRITY_VERIFIED = 'verified';

    public const INTEGRITY_MISMATCH = 'mismatch';

    public const SOURCE_RECONSTRUCTION = 'reconstruction';

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ConversionArtifactStore $artifacts,
    ) {}

    /**
     * All versions (active + archived) for the doc's family, newest first.
     *
     * @return Collection<int, KnowledgeDocument>
     */
    public function versionsFor(KnowledgeDocument $document): Collection
    {
        return KnowledgeDocument::query()
            ->forTenant($this->tenant->current())
            ->where('project_key', $document->project_key)
            ->where('source_path', $document->source_path)
            ->orderByDesc('indexed_at')
            ->orderByDesc('id')
            ->get(['id', 'title', 'version_hash', 'status', 'is_canonical', 'canonical_type', 'indexed_at', 'created_at',
                // v8.36 / ADR 0030 §4 — version provenance + the artifact pointer
                'markdown_path', 'version_actor', 'version_reason', 'content_hash', 'metadata']);
    }

    /**
     * v8.36 / ADR 0030 §5 — the version's content and where it came from:
     * the stored artifact when `markdown_path` is set and the file is there,
     * otherwise the chunk reconstruction. A missing file behind a non-null
     * path is logged and degrades — it is not a 500 (R14 is about silent
     * success; this says which source it used).
     *
     * @return array{content: string, source: string, integrity: string|null}
     */
    public function contentFor(KnowledgeDocument $version): array
    {
        $path = $version->markdown_path;
        if (is_string($path) && $path !== '') {
            $metadata = is_array($version->metadata) ? $version->metadata : [];
            $disk = (string) ($metadata['disk'] ?? config('kb.sources.disk', 'kb'));
            $content = $this->artifacts->read($disk, $path);
            // ADR 0030 §5 — `content_hash` is the integrity check of the
            // stored bytes: a truncated or replaced file is never reported
            // as a faithful artifact; it degrades to the reconstruction and
            // says so (`integrity: mismatch`), it is not a 500.
            if ($content !== null && is_string($version->content_hash) && $version->content_hash !== '' && hash('sha256', $content) !== $version->content_hash) {
                Log::warning('DocumentVersionService: artifact bytes do not match content_hash, falling back to reconstruction', [
                    'document_id' => (int) $version->id,
                    'disk' => $disk,
                    'markdown_path' => $path,
                ]);

                return ['content' => $this->reconstructContent($version), 'source' => self::SOURCE_RECONSTRUCTION, 'integrity' => self::INTEGRITY_MISMATCH];
            }
            if ($content !== null) {
                return ['content' => $content, 'source' => self::SOURCE_ARTIFACT, 'integrity' => is_string($version->content_hash) && $version->content_hash !== '' ? self::INTEGRITY_VERIFIED : null];
            }
            Log::warning('DocumentVersionService: artifact missing behind markdown_path, falling back to reconstruction', [
                'document_id' => (int) $version->id,
                'disk' => $disk,
                'markdown_path' => $path,
            ]);
        }

        return ['content' => $this->reconstructContent($version), 'source' => self::SOURCE_RECONSTRUCTION, 'integrity' => null];
    }

    /**
     * Reconstruct a version's body content from its retained chunks
     * (the archived row keeps its chunks). Frontmatter is not stored in
     * chunks, so this is the indexed body — sufficient for the diff view.
     */
    public function reconstructContent(KnowledgeDocument $version): string
    {
        return (string) KnowledgeChunk::query()
            ->forTenant($this->tenant->current())
            ->where('knowledge_document_id', $version->id)
            ->orderBy('chunk_order')
            ->pluck('chunk_text')
            ->implode("\n\n");
    }

    /**
     * Artifact-aware since v8.36 (ADR 0030 §5): each side is its stored
     * artifact when there is one, its chunk reconstruction otherwise, and
     * `from_source` / `to_source` say which — additive keys (R27), so the
     * v8.7 shape is unchanged for every existing reader.
     *
     * @return array{from: int, to: int, added: int, removed: int, rows: list<array{type: string, text: string}>, from_source: string, to_source: string, from_integrity: string|null, to_integrity: string|null}
     */
    public function diff(KnowledgeDocument $from, KnowledgeDocument $to): array
    {
        $fromContent = $this->contentFor($from);
        $toContent = $this->contentFor($to);
        $diff = MarkdownDiff::compute($fromContent['content'], $toContent['content']);

        return [
            'from' => (int) $from->id,
            'to' => (int) $to->id,
            'added' => $diff['added'],
            'removed' => $diff['removed'],
            'rows' => $diff['rows'],
            'from_source' => $fromContent['source'],
            'to_source' => $toContent['source'],
            'from_integrity' => $fromContent['integrity'] ?? null,
            'to_integrity' => $toContent['integrity'] ?? null,
        ];
    }

    /**
     * Restore an archived version to live. Archives the current live
     * version of the same family, transfers its canonical identity (when
     * canonical) to the target, activates the target, and writes a
     * `kb_canonical_audit` row for canonical restores. Transactional so a
     * partial flip can never leave two live versions or a vacated identity.
     *
     * R21 — The target is re-fetched with lockForUpdate() as the FIRST
     * statement inside the transaction so concurrent restore calls for the
     * same version are serialised. The "already live" guard is also inside
     * the lock boundary so a TOCTOU race between the controller check and
     * the transaction commit cannot yield a double-restore.
     *
     * A final sweep UPDATE after activation enforces the one-active-per-
     * family invariant even when two threads concurrently restore different
     * archived versions: PostgreSQL EvalPlanQual can cause the $live SELECT
     * FOR UPDATE to return null (the previously-active row was archived by
     * the competing transaction), leaving this thread unaware of the
     * newly-activated version. The sweep runs at UPDATE-lock time and
     * captures any concurrent activations that the SELECT missed.
     */
    /**
     * The most recent restore recorded on a version (ADR 0030 §6), or null
     * when it was never restored. Additive read model for the index surfaces.
     *
     * @return array{actor: string, at: string, previous_live_id: int|null}|null
     */
    public static function lastRestoreOf(KnowledgeDocument $version): ?array
    {
        $metadata = is_array($version->metadata) ? $version->metadata : [];
        $restores = is_array($metadata['restores'] ?? null) ? $metadata['restores'] : [];
        $last = $restores === [] ? null : end($restores);
        if (! is_array($last) || ! is_string($last['actor'] ?? null) || ! is_string($last['at'] ?? null)) {
            return null;
        }

        return ['actor' => $last['actor'], 'at' => $last['at'], 'previous_live_id' => isset($last['previous_live_id']) ? (int) $last['previous_live_id'] : null];
    }

    public function restore(KnowledgeDocument $target, ?string $actor = null): KnowledgeDocument
    {
        $tenantId = $this->tenant->current();

        DB::transaction(function () use ($target, $tenantId, $actor): void {
            // R21 — Re-read and lock the target first; the stale $target loaded
            // by the controller cannot be trusted once we cross the lock boundary.
            $locked = KnowledgeDocument::query()
                ->forTenant($tenantId)
                ->where('id', $target->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                throw new UnprocessableEntityHttpException('Document not found.');
            }

            if ($locked->status === 'active') {
                throw new UnprocessableEntityHttpException('This version is already live.');
            }

            /** @var KnowledgeDocument|null $live */
            $live = KnowledgeDocument::query()
                ->forTenant($tenantId)
                ->where('project_key', $locked->project_key)
                ->where('source_path', $locked->source_path)
                ->where('status', 'active')
                ->where('id', '!=', $locked->id)
                ->lockForUpdate()
                ->first();

            $restoreCanonical = $live !== null && (bool) $live->is_canonical;
            $identity = $restoreCanonical
                ? [
                    'is_canonical' => true,
                    'doc_id' => $live->doc_id,
                    'slug' => $live->slug,
                    'canonical_status' => $live->canonical_status,
                    'retrieval_priority' => $live->retrieval_priority,
                ]
                : [];

            if ($live !== null) {
                // Vacate the outgoing live version's canonical identity FIRST
                // so the composite uniques (project, slug)/(project, doc_id)
                // are free before we assign them to the target.
                $live->update([
                    'status' => 'archived',
                    'doc_id' => null,
                    'slug' => null,
                    'is_canonical' => false,
                    'canonical_status' => null,
                ]);
            }

            // v8.36 / ADR 0030 §6 — `version_actor` / `version_reason` are the
            // CREATION provenance of the version and stay immutable; a restore
            // is appended to `metadata.restores` (actor, when, which live row it
            // displaced), so the timeline keeps both who created a version and
            // who brought it back. `markdown_path` is left untouched (the
            // artifact was never deleted with the archive, only with the prune).
            $lockedMetadata = is_array($locked->metadata) ? $locked->metadata : [];
            $restores = is_array($lockedMetadata['restores'] ?? null) ? $lockedMetadata['restores'] : [];
            $restores[] = [
                'actor' => $actor ?? 'system:restore',
                'at' => now()->toIso8601String(),
                'previous_live_id' => $live?->id,
            ];
            $locked->update(array_merge([
                'status' => 'active',
                'indexed_at' => now(),
                'metadata' => array_merge($lockedMetadata, ['restores' => $restores]),
            ], $identity));

            // R21 — Sweep-archive any other active versions that may have been
            // activated by a concurrent restore transaction. When two threads
            // restore different archived versions concurrently, PostgreSQL
            // EvalPlanQual re-evaluates WHERE status='active' after a blocked
            // lock is released; the formerly-active row is now archived so
            // $live above returns null, causing this thread to miss the version
            // that the concurrent transaction just activated. This UPDATE runs
            // at UPDATE-lock-acquisition time (not at SELECT scan time) so it
            // captures any such version and upholds the one-active-per-family
            // invariant unconditionally.
            KnowledgeDocument::query()
                ->forTenant($tenantId)
                ->where('project_key', $locked->project_key)
                ->where('source_path', $locked->source_path)
                ->where('status', 'active')
                ->where('id', '!=', $locked->id)
                ->update([
                    'status' => 'archived',
                    'is_canonical' => false,
                    'doc_id' => null,
                    'slug' => null,
                    'canonical_status' => null,
                ]);

            if ($restoreCanonical && (bool) config('kb.canonical.audit_enabled', true)) {
                KbCanonicalAudit::create([
                    'project_key' => (string) $locked->project_key,
                    'doc_id' => $identity['doc_id'] ?? null,
                    'slug' => $identity['slug'] ?? null,
                    'event_type' => 'updated',
                    'actor' => $actor ?? 'time-machine:restore',
                    'before_json' => ['restored_from_status' => 'archived', 'previous_live_id' => $live?->id],
                    'after_json' => ['restored_version_id' => (int) $locked->id, 'version_hash' => $locked->version_hash],
                    'metadata_json' => ['action' => 'version_restore'],
                ]);
            }
        });

        return $target->fresh() ?? throw new \RuntimeException('Restored version has been deleted.');
    }
}
