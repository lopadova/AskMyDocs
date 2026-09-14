<?php

declare(strict_types=1);

namespace App\Services\Kb\Versioning;

use App\Models\KbCanonicalAudit;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Support\Kb\StorageNamespace;
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
    /** The timeline-limit misconfiguration has been reported by this process (test seam: resetWarnings()). */
    private static bool $warnedTimelineLimit = false;

    public static function resetWarnings(): void
    {
        self::$warnedTimelineLimit = false;
    }

    public const SOURCE_ARTIFACT = 'artifact';

    public const INTEGRITY_VERIFIED = 'verified';

    public const INTEGRITY_MISMATCH = 'mismatch';

    public const SOURCE_RECONSTRUCTION = 'reconstruction';

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ConversionArtifactStore $artifacts,
    ) {}

    /**
     * The versions (active + archived) of the doc's family, newest first —
     * at most `$limit` of them (`kb.versioning.timeline_limit` when null),
     * skipping the newest `$offset` (the page cursor: every surface exposes
     * it, so a family larger than the bound is still reachable page by
     * page). The timeline is BOUNDED (R3): every caller hydrates each row
     * and reads + hashes its artifact, and a family grows without bound
     * while the prune is delayed or `keep_archived` is high; the surfaces
     * say when the family is larger than what they show (`truncated`).
     * Rows without `indexed_at` sort LAST on every driver (PostgreSQL would
     * put NULLs first under DESC and fill the window with them).
     *
     * @return Collection<int, KnowledgeDocument>
     */
    public function versionsFor(KnowledgeDocument $document, ?int $limit = null, int $offset = 0): Collection
    {
        return $this->familyQuery($document)
            ->orderByRaw('CASE WHEN indexed_at IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('indexed_at')
            ->orderByDesc('id')
            ->offset(max(0, $offset))
            ->limit(self::timelineLimit($limit))
            ->get(['id', 'title', 'version_hash', 'status', 'is_canonical', 'canonical_type', 'indexed_at', 'created_at',
                // v8.36 / ADR 0030 §4 — version provenance + the artifact pointer
                'markdown_path', 'version_actor', 'version_reason', 'content_hash', 'metadata']);
    }

    /** How many versions the doc's family holds in total (the timeline shows at most `timelineLimit()` of them). */
    public function familySizeFor(KnowledgeDocument $document): int
    {
        return $this->familyQuery($document)->count();
    }

    /**
     * One version of the doc's family by id — resolved against the WHOLE
     * family (tenant-scoped), never against a listed page: a diff or a
     * restore may name a version the bounded listing did not show.
     */
    public function versionInFamily(KnowledgeDocument $document, int $id): ?KnowledgeDocument
    {
        return $this->familyQuery($document)->whereKey($id)->first();
    }

    /**
     * The bound on a timeline listing: the caller's positive limit, capped by
     * `kb.versioning.timeline_limit` (a non-positive configured value is the
     * default of 100, never "unbounded").
     */
    public static function timelineLimit(?int $requested = null): int
    {
        $configured = config('kb.versioning.timeline_limit', 100);
        $max = is_numeric($configured) && (int) $configured >= 1 ? (int) $configured : 100;
        if (($max !== (int) $configured || ! is_numeric($configured)) && ! self::$warnedTimelineLimit) {
            // Once per process, not once per call: the limit is read by every
            // surface (HTTP / MCP / CLI) on every page, and a misconfiguration
            // must stay visible without sustained noise.
            self::$warnedTimelineLimit = true;
            Log::warning('DocumentVersionService: kb.versioning.timeline_limit is not a positive number of versions; using the default', [
                'configured' => is_scalar($configured) ? $configured : gettype($configured),
                'default' => 100,
            ]);
        }
        if ($requested === null || $requested < 1) {
            return $max;
        }

        return min($requested, $max);
    }

    /** @return \Illuminate\Database\Eloquent\Builder<KnowledgeDocument> */
    private function familyQuery(KnowledgeDocument $document): \Illuminate\Database\Eloquent\Builder
    {
        return KnowledgeDocument::query()
            ->forTenant($this->tenant->current())
            ->where('project_key', $document->project_key)
            ->where('source_path', $document->source_path);
    }

    public const ARTIFACT_NONE = 'none';

    public const ARTIFACT_VERIFIED = 'verified';

    public const ARTIFACT_UNVERIFIED = 'unverified';

    public const ARTIFACT_MISSING = 'missing';

    public const ARTIFACT_MISMATCH = 'mismatch';

    /**
     * v8.36 / ADR 0030 §5 — the verified state of a version's artifact, the
     * same read + hash check {@see contentFor()} serves content with (never
     * the pointer alone: the ingestor deliberately keeps the pointer when a
     * post-commit publish fails, and a file can be truncated or replaced
     * later): `none` (no pointer), `verified` (readable, hashes to
     * `content_hash`), `unverified` (readable, no `content_hash` to check
     * against), `missing` (pointer set, nothing readable there), `mismatch`
     * (readable, does not hash to `content_hash`). Every surface that claims
     * "this version has a stored artifact" derives it from here.
     */
    public function artifactStateFor(KnowledgeDocument $version): string
    {
        return $this->readArtifact($version)['state'];
    }

    /**
     * Whether an artifact state backs the `has_artifact` claim: only
     * `verified` — readable AND hashing to `content_hash`. `unverified` (a
     * readable file with no hash to check against) serves content, but it
     * is a diagnostic state, not a claim of integrity; the identical
     * re-ingest and the backfill record the hash and make it `verified`.
     */
    public static function isVerifiedArtifactState(string $state): bool
    {
        return $state === self::ARTIFACT_VERIFIED;
    }

    /**
     * @return array{state: string, content: string|null, disk: string|null, path: string|null}
     */
    private function readArtifact(KnowledgeDocument $version): array
    {
        $path = $version->markdown_path;
        if (! is_string($path) || $path === '') {
            return ['state' => self::ARTIFACT_NONE, 'content' => null, 'disk' => null, 'path' => null];
        }
        $metadata = is_array($version->metadata) ? $version->metadata : [];
        // One reading for every consumer (StorageNamespace): a row whose
        // `metadata.disk` is not a non-empty string reads from the configured
        // disk, never from '' or 'Array'.
        $disk = StorageNamespace::diskOf($metadata);
        $content = $this->artifacts->read($disk, $path);
        if ($content === null) {
            return ['state' => self::ARTIFACT_MISSING, 'content' => null, 'disk' => $disk, 'path' => $path];
        }
        if (! is_string($version->content_hash) || $version->content_hash === '') {
            return ['state' => self::ARTIFACT_UNVERIFIED, 'content' => $content, 'disk' => $disk, 'path' => $path];
        }
        if (hash('sha256', $content) !== $version->content_hash) {
            return ['state' => self::ARTIFACT_MISMATCH, 'content' => null, 'disk' => $disk, 'path' => $path];
        }

        return ['state' => self::ARTIFACT_VERIFIED, 'content' => $content, 'disk' => $disk, 'path' => $path];
    }

    /**
     * v8.36 / ADR 0030 §5 — the version's content and where it came from:
     * the stored artifact when `markdown_path` is set, the file is there and
     * its bytes hash to `content_hash` (or there is no hash to check),
     * otherwise the chunk reconstruction. A missing file behind a non-null
     * path is logged and degrades — it is not a 500 (R14 is about silent
     * success; this says which source it used). A truncated or replaced file
     * is never reported as a faithful artifact: it degrades to the
     * reconstruction and says so (`integrity: mismatch`).
     *
     * @return array{content: string, source: string, integrity: string|null}
     */
    public function contentFor(KnowledgeDocument $version): array
    {
        $artifact = $this->readArtifact($version);
        switch ($artifact['state']) {
            case self::ARTIFACT_VERIFIED:
                return ['content' => (string) $artifact['content'], 'source' => self::SOURCE_ARTIFACT, 'integrity' => self::INTEGRITY_VERIFIED];
            case self::ARTIFACT_UNVERIFIED:
                return ['content' => (string) $artifact['content'], 'source' => self::SOURCE_ARTIFACT, 'integrity' => null];
            case self::ARTIFACT_MISMATCH:
                Log::warning('DocumentVersionService: artifact bytes do not match content_hash, falling back to reconstruction', [
                    'document_id' => (int) $version->id,
                    'disk' => $artifact['disk'],
                    'markdown_path' => $artifact['path'],
                ]);

                return ['content' => $this->reconstructContent($version), 'source' => self::SOURCE_RECONSTRUCTION, 'integrity' => self::INTEGRITY_MISMATCH];
            case self::ARTIFACT_MISSING:
                Log::warning('DocumentVersionService: artifact missing behind markdown_path, falling back to reconstruction', [
                    'document_id' => (int) $version->id,
                    'disk' => $artifact['disk'],
                    'markdown_path' => $artifact['path'],
                ]);
                break;
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

            // R21 — Sweep-archive any other active versions that may have been
            // activated by a concurrent restore transaction. When two threads
            // restore different archived versions concurrently, PostgreSQL
            // EvalPlanQual re-evaluates WHERE status='active' after a blocked
            // lock is released; the formerly-active row is now archived so
            // $live above returns null, causing this thread to miss the version
            // that the concurrent transaction just activated. This fresh
            // SELECT runs after our own UPDATE — READ COMMITTED gives each
            // statement a new snapshot — so it sees the row that transaction
            // activated. Its canonical identity is CARRIED onto the target
            // before it is vacated (never left on no row: the family would lose
            // its slug/doc_id), the transfer is audited like the ordinary one,
            // and the row is archived, upholding the one-active-per-family
            // invariant unconditionally.
            $displacedIds = $live !== null ? [(int) $live->id] : [];
            $concurrentlyActive = KnowledgeDocument::query()
                ->forTenant($tenantId)
                ->where('project_key', $locked->project_key)
                ->where('source_path', $locked->source_path)
                ->where('status', 'active')
                ->where('id', '!=', $locked->id)
                ->lockForUpdate()
                ->get();
            foreach ($concurrentlyActive as $other) {
                $displacedIds[] = (int) $other->id;
                if ($identity === [] && (bool) $other->is_canonical) {
                    $identity = [
                        'is_canonical' => true,
                        'doc_id' => $other->doc_id,
                        'slug' => $other->slug,
                        'canonical_status' => $other->canonical_status,
                        'retrieval_priority' => $other->retrieval_priority,
                    ];
                    $restoreCanonical = true;
                }
                // Vacate BEFORE the target takes the identity (composite uniques).
                $other->update([
                    'status' => 'archived',
                    'is_canonical' => false,
                    'doc_id' => null,
                    'slug' => null,
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
                'previous_live_id' => $displacedIds[0] ?? null,
            ];
            $locked->update(array_merge([
                'status' => 'active',
                'indexed_at' => now(),
                'metadata' => array_merge($lockedMetadata, ['restores' => $restores]),
            ], $identity));

            if ($restoreCanonical && (bool) config('kb.canonical.audit_enabled', true)) {
                KbCanonicalAudit::create([
                    'project_key' => (string) $locked->project_key,
                    'doc_id' => $identity['doc_id'] ?? null,
                    'slug' => $identity['slug'] ?? null,
                    'event_type' => 'updated',
                    'actor' => $actor ?? 'time-machine:restore',
                    'before_json' => ['restored_from_status' => 'archived', 'previous_live_id' => $displacedIds[0] ?? null, 'displaced_ids' => $displacedIds],
                    'after_json' => ['restored_version_id' => (int) $locked->id, 'version_hash' => $locked->version_hash],
                    'metadata_json' => ['action' => 'version_restore'],
                ]);
            }
        });

        return $target->fresh() ?? throw new \RuntimeException('Restored version has been deleted.');
    }
}
