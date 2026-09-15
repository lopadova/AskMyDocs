<?php

declare(strict_types=1);

namespace App\Services\Kb\Versioning;

use App\Models\KbCanonicalAudit;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Scopes\AccessScopeScope;
use App\Services\Kb\Canonical\CanonicalParser;
use App\Support\Kb\StorageNamespace;
use App\Support\MarkdownDiff;
use App\Support\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Yaml\Yaml;

/**
 * v8.7/W5 — Cloud Time Machine: browse + restore document versions.
 *
 * Every re-ingest archives the prior `knowledge_documents` row (status
 * `archived`) but RETAINS it + its chunks (see
 * `DocumentIngestor::archivePreviousVersions`). This service reads that
 * retained history: it lists the version timeline for a doc's
 * `(tenant, project_key, source_path)` family, reconstructs a version's
 * content from its chunks, diffs two versions, and restores an archived
 * version (status flip + the version's own canonical identity, recomputed
 * from its retained frontmatter; transactional + audited). Reuses retained
 * chunks/embeddings — no re-embedding.
 */
final class DocumentVersionService
{
    /** The timeline-limit misconfiguration has been reported by this process (test seam: resetWarnings()). */
    private static bool $warnedTimelineLimit = false;

    private static bool $warnedArtifactStateCache = false;

    public static function resetWarnings(): void
    {
        self::$warnedArtifactStateCache = false;
        self::$warnedTimelineLimit = false;
    }

    public const SOURCE_ARTIFACT = 'artifact';

    public const INTEGRITY_VERIFIED = 'verified';

    public const INTEGRITY_MISMATCH = 'mismatch';

    public const SOURCE_RECONSTRUCTION = 'reconstruction';

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ConversionArtifactStore $artifacts,
        private readonly CanonicalParser $parser,
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
     *
     * The check READS the bytes, so a timeline page of `timeline_limit`
     * versions would fetch that many objects from a bucket on every listing
     * and on every "load older" page. `verified` — and ONLY `verified` — is
     * therefore memoized for `kb.versioning.artifact_state_cache_seconds`
     * (0 disables it, R43) under a key carrying the bytes' identity: disk,
     * path and `content_hash`, all three immutable for a published artifact.
     *
     * The asymmetry is the point. A state that can be repaired — `missing`,
     * `mismatch`, `unverified`, `none` — is never memoized, so the identical
     * re-ingest and `kb:artifacts-backfill` that republish the bytes show as
     * repaired on the very next listing; only the state that cannot improve
     * on its own is cached. What the window can then hide is the one
     * transition left: a verified file deleted or tampered WITHIN it, whose
     * badge may lag by up to the TTL — while the content and diff endpoints
     * re-read every time and report `missing` / `mismatch` faithfully. The
     * badge is a diagnostic; the served bytes are the contract.
     */
    public function artifactStateFor(KnowledgeDocument $version): string
    {
        $seconds = self::artifactStateCacheSeconds();
        $hash = $version->content_hash;
        if ($seconds < 1 || ! is_string($hash) || $hash === '') {
            return $this->readArtifact($version)['state'];
        }
        $metadata = is_array($version->metadata) ? $version->metadata : [];
        // The VALUE is the verified hash, so the key needs only (disk, path):
        // one key per artifact, which the removal gate can forget without
        // knowing which rows pointed at it.
        $key = self::artifactStateCacheKey(StorageNamespace::diskOf($metadata), (string) $version->markdown_path);
        try {
            if (Cache::get($key) === $hash) {
                return self::ARTIFACT_VERIFIED;
            }
        } catch (\Throwable $e) {
            // A cache that refuses is never an outage on a read path (R14):
            // the state is computed, just not memoized.
            Log::warning('DocumentVersionService: artifact state memo not read; verifying', ['document_id' => (int) $version->id, 'error' => $e->getMessage()]);

            return $this->readArtifact($version)['state'];
        }
        $state = $this->readArtifact($version)['state'];
        if ($state !== self::ARTIFACT_VERIFIED) {
            return $state; // repairable: never memoized, so a repair shows on the next listing
        }
        try {
            Cache::put($key, $hash, $seconds);
        } catch (\Throwable $e) {
            Log::warning('DocumentVersionService: artifact state not memoized; verifying on every read', ['document_id' => (int) $version->id, 'error' => $e->getMessage()]);
        }

        return $state;
    }

    /** One key per artifact `(disk, path)`; the value is the `content_hash` proved verified. */
    public static function artifactStateCacheKey(string $disk, string $path): string
    {
        return 'kb:artifact-state:'.$disk.':'.sha1($path);
    }

    /**
     * Forget an artifact's memoized verification. Called by the ONE gate
     * every removal goes through (`DocumentDeleter::removeArtifactIfUnreferenced()`),
     * so a file WE delete never leaves a "stored" badge standing for the rest
     * of the window: what the window can still hide is an external deletion
     * or tampering, which the content and diff endpoints report faithfully
     * anyway. A cache that refuses is logged, never a removal turned into a
     * failure (R14).
     */
    public static function forgetArtifactStateMemo(string $disk, string $path): void
    {
        try {
            Cache::forget(self::artifactStateCacheKey($disk, $path));
        } catch (\Throwable $e) {
            Log::warning('DocumentVersionService: artifact state memo not forgotten after a removal; the badge may lag until it expires', ['disk' => $disk, 'markdown_path' => $path, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Seconds an artifact state stays memoized. `0` disables the memo
     * deliberately and silently (every read verifies); a negative or
     * non-numeric value disables it too — the safe direction — and is
     * reported once per process, never a stale badge from a
     * misconfiguration.
     */
    public static function artifactStateCacheSeconds(): int
    {
        $configured = config('kb.versioning.artifact_state_cache_seconds', 300);
        if (is_numeric($configured) && (int) $configured >= 0) {
            return (int) $configured;
        }
        if (! self::$warnedArtifactStateCache) {
            self::$warnedArtifactStateCache = true;
            Log::warning('DocumentVersionService: kb.versioning.artifact_state_cache_seconds is not a number of seconds; verifying every read', [
                'configured' => is_scalar($configured) ? $configured : gettype($configured),
            ]);
        }

        return 0;
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

    /**
     * Restore an archived version to live. Archives the current live version
     * of the same family, settles the canonical identity, activates the
     * target, and writes a `kb_canonical_audit` row for canonical restores.
     * Transactional so a partial flip can never leave two live versions or a
     * vacated identity.
     *
     * v8.36 — the identity is the restored version's OWN, recomputed from the
     * frontmatter the archive retained through the same parser + validator the
     * ingest path runs: a restore is the re-ingest of older bytes, so the
     * canonical identity follows the CONTENT. A version that never declared a
     * slug takes none (the family's slug is left unheld, exactly as after
     * ingesting non-canonical bytes); a LEGACY version archived before the
     * frontmatter was persisted has none of its own, so it still carries the
     * outgoing live/swept row's. A reclaimed slug or doc_id already held by
     * another row degrades the restore to non-canonical with a logged warning
     * rather than raising on the composite unique.
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

            // v8.36 — the restored version's canonical identity is ITS OWN,
            // reconstructed from the frontmatter the archive retained. A
            // restore is the re-ingest of an older version's bytes, and under
            // ingest the canonical identity follows the CONTENT: taking
            // whatever the live row happened to hold would mark content
            // canonical that never declared it (and would silently demote a
            // canonical version restored over a non-canonical one).
            $ownIdentity = $this->canonicalIdentityFromFrontmatter($locked);
            $targetWasCanonical = $ownIdentity !== null || $locked->canonical_type !== null;
            $identity = $ownIdentity ?? [];
            // Legacy rows (archived before the frontmatter was persisted) keep
            // no identity of their own: `canonical_type` is all that survives,
            // so for THOSE the family's identity is carried from the outgoing
            // live version — the only place the slug still exists.
            $carryFromLive = $targetWasCanonical && $ownIdentity === null;
            $restoreCanonical = $ownIdentity !== null;
            if ($carryFromLive && $live !== null && (bool) $live->is_canonical) {
                $identity = [
                    'is_canonical' => true,
                    'doc_id' => $live->doc_id,
                    'slug' => $live->slug,
                    'canonical_status' => $live->canonical_status,
                    'retrieval_priority' => $live->retrieval_priority,
                ];
                $restoreCanonical = true;
            }

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
            // activated. The row is always vacated and archived, upholding the
            // one-active-per-family invariant unconditionally; its canonical
            // identity is carried onto the target ONLY when the target is a
            // legacy version with none of its own (the identity rule above) —
            // otherwise the family's slug is deliberately left unheld, exactly
            // as it would be after ingesting non-canonical bytes. A carried
            // transfer is audited like the ordinary one.
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
                // Same rule as above: only a legacy row with no identity of its
                // own borrows the swept version's.
                if ($carryFromLive && $identity === [] && (bool) $other->is_canonical) {
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
            // The family's ACTIVE rows have been vacated above, but the
            // reclaimed slug/doc_id can still be held by an archived sibling
            // (a re-ingest that dropped the frontmatter vacates nothing) or by
            // a live row of ANOTHER source path in the same project. Writing
            // it anyway raises a QueryException on `uq_kb_doc_slug` /
            // `uq_kb_doc_doc_id` — a 500 with a raw SQL message. The restore's
            // job is to bring the CONTENT back, so a taken slot degrades the
            // row to non-canonical, loudly, rather than failing the restore or
            // stealing the slot from its current holder.
            if ($identity !== []) {
                $holderId = $this->conflictingCanonicalHolderId($locked, $identity, $tenantId);
                if ($holderId !== null) {
                    Log::warning('DocumentVersionService: restoring without the canonical identity — its slug or doc_id is held by another document', [
                        'knowledge_document_id' => (int) $locked->id,
                        'project_key' => (string) $locked->project_key,
                        'slug' => $identity['slug'] ?? null,
                        'doc_id' => $identity['doc_id'] ?? null,
                        'held_by_document_id' => $holderId,
                    ]);
                    $identity = [];
                    $restoreCanonical = false;
                }
            }

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

    /**
     * The canonical identity a version declares in ITS OWN retained
     * frontmatter, or null when it declares none.
     *
     * `vacateCanonicalIdentifiersOnPreviousVersions()` clears `doc_id`,
     * `slug`, `canonical_status` and `is_canonical` when a version is
     * archived but PRESERVES `frontmatter_json` (and `canonical_type`)
     * precisely so the identity can be reconstructed: the markdown is the
     * source of truth and the columns are its projection (CLAUDE.md §6).
     * `retrieval_priority` survives the archive on the row itself, so it is
     * read back from the column with the frontmatter as a fallback.
     *
     * @return array<string, mixed>|null
     */
    private function canonicalIdentityFromFrontmatter(KnowledgeDocument $version): ?array
    {
        $frontmatter = $version->frontmatter_json;
        if (! is_array($frontmatter) || $frontmatter === []) {
            return null;
        }
        // `_derived` is the ingestor's own sub-map, not authored frontmatter.
        unset($frontmatter['_derived']);
        if ($frontmatter === []) {
            return null;
        }

        // The identity is recomputed through the SAME parser + validator the
        // ingest path runs, so a restore can never resurrect an identity that
        // ingestion would have refused (an invalid status, a slug that does
        // not match the pattern, a missing type). Anything the parser turns
        // down degrades to a non-canonical restore, exactly as re-ingesting
        // those bytes would.
        try {
            $document = $this->parser->parse("---\n".Yaml::dump($frontmatter, 4, 2)."---\n\n");
        } catch (\Throwable $e) {
            Log::warning('DocumentVersionService: a version\'s retained frontmatter could not be re-read; restoring it without a canonical identity', [
                'knowledge_document_id' => (int) $version->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
        if (! $this->parser->validate($document)->valid || $document->slug === null || $document->slug === '') {
            return null;
        }

        return [
            'is_canonical' => true,
            'doc_id' => $document->docId,
            'slug' => $document->slug,
            'canonical_type' => $document->type?->value,
            'canonical_status' => $document->status?->value,
            'retrieval_priority' => $document->retrievalPriority,
        ];
    }

    /**
     * The id of another row in this tenant + project that already holds the
     * slug or doc_id the restore is about to reclaim, or null when the slots
     * are free.
     *
     * The composite uniques are `(tenant_id, project_key, slug)` and
     * `(tenant_id, project_key, doc_id)` since 2026_10_02_000011, and only
     * the family's ACTIVE rows are vacated before the assignment — an
     * archived sibling of this family (a re-ingest that dropped the
     * frontmatter never vacates) or a live row of ANOTHER source path can
     * still hold the value. Writing it anyway raises a `QueryException` the
     * restore has no business turning into a 500.
     *
     * The probe therefore has to see EXACTLY what the index sees. A holder
     * the reader is not allowed to read still occupies the slot, so the two
     * global scopes are lifted here: `withTrashed()` because a soft-deleted
     * row keeps its slug, and `AccessScopeScope` because an ACL-hidden
     * holder is invisible to this admin yet not to the database. Dropping
     * either one turns a degraded restore back into the 500 this probe
     * exists to prevent. The tenant filter STAYS — it is the first column of
     * the index.
     *
     * @param  array<string, mixed>  $identity
     */
    private function conflictingCanonicalHolderId(KnowledgeDocument $locked, array $identity, string $tenantId): ?int
    {
        $slug = $identity['slug'] ?? null;
        $docId = $identity['doc_id'] ?? null;
        if (! is_string($slug) && ! is_string($docId)) {
            return null;
        }

        $holder = KnowledgeDocument::withTrashed()
            ->withoutGlobalScope(AccessScopeScope::class)
            ->forTenant($tenantId)
            ->where('project_key', $locked->project_key)
            ->where('id', '!=', $locked->id)
            ->where(static function ($query) use ($slug, $docId): void {
                if (is_string($slug)) {
                    $query->orWhere('slug', $slug);
                }
                if (is_string($docId)) {
                    $query->orWhere('doc_id', $docId);
                }
            })
            ->lockForUpdate()
            ->first();

        return $holder !== null ? (int) $holder->id : null;
    }
}
