<?php

declare(strict_types=1);

namespace App\Services\Kb;

use App\Jobs\AnalyzeDocumentDeletionJob;
use App\Models\KbCanonicalAudit;
use App\Models\KbNode;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Services\Kb\Ocr\OcrFigureStore;
use App\Services\Kb\Versioning\ConversionArtifactStore;
use App\Services\Kb\Analysis\ChangeAnalysisGate;
use App\Support\Kb\HeldLock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use App\Support\Kb\SourceKeyLock;
use App\Support\Kb\StorageNamespace;
use App\Support\KbPath;
use App\Support\LikeEscaper;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Deletion pipeline for knowledge documents.
 *
 * Supports soft (deleted_at only) and hard (document + chunks + physical
 * file) deletion, as well as orphan cleanup during a folder resync and a
 * scheduled purge of soft-deleted documents older than the configured
 * retention window.
 */
class DocumentDeleter
{
    /** Max chars of reconstructed document text kept in the deletion snapshot. */
    private const SNAPSHOT_TEXT_CHARS = 4000;

    /**
     * Delete a single document. When $force is null the behaviour is driven
     * by the `kb.deletion.soft_delete` config flag (default: soft).
     *
     * @return array{mode: string, document_id: int, project_key: string, source_path: string, file_deleted: bool, ocr_assets_deleted?: bool, artifact_deleted?: bool}
     */
    public function delete(KnowledgeDocument $document, ?bool $force = null, bool $analyzeImpact = false): array
    {
        $shouldForce = $force ?? ! (bool) config('kb.deletion.soft_delete', true);

        // v8.8/W2 — capture the obsolescence-impact snapshot BEFORE the
        // deletion mutates anything: a hard delete cascades the chunks, a
        // soft delete hides them. Only the user-initiated single delete opts
        // in ($analyzeImpact); bulk orphan/prune sweeps never trigger the LLM.
        $snapshot = ($analyzeImpact && $this->deletionAnalysisEnabled($document))
            ? $this->deletionSnapshot($document)
            : null;

        $result = $shouldForce
            ? $this->forceDelete($document)
            : $this->softDelete($document);

        // Dispatched only AFTER the delete has persisted (softDelete's
        // delete() and forceDelete's own DB::transaction have both committed
        // by here), so a failed/throwing delete never fans out an analysis
        // for a still-live document. Direct dispatch mirrors how
        // IngestDocumentJob dispatches AnalyzeDocumentChangeJob.
        if ($snapshot !== null) {
            AnalyzeDocumentDeletionJob::dispatch($snapshot);
        }

        return $result;
    }

    /**
     * Skip the (one-query) snapshot build entirely when deletion analysis is
     * disabled for this doc's (tenant, project) — resolved through the same
     * {@see ChangeAnalysisGate} the job re-checks authoritatively. Resolved
     * via the container because this service is also instantiated with `new`
     * in tests (no constructor injection).
     */
    private function deletionAnalysisEnabled(KnowledgeDocument $document): bool
    {
        return app(ChangeAnalysisGate::class)->allows(
            (string) $document->tenant_id,
            (string) $document->project_key,
            (bool) $document->is_canonical,
            isDelete: true,
        );
    }

    /**
     * Pre-delete snapshot for {@see AnalyzeDocumentDeletionJob}. Reconstructs
     * the document text from its chunks (tenant-scoped, R30) while they still
     * exist, plus the identity fields the analyzer + notifier need.
     *
     * @return array{tenant_id: string, project_key: string, knowledge_document_id: int, doc_slug: ?string, title: string, source_path: string, is_canonical: bool, doc_text: string}
     */
    private function deletionSnapshot(KnowledgeDocument $document): array
    {
        return [
            'tenant_id' => (string) $document->tenant_id,
            'project_key' => (string) $document->project_key,
            'knowledge_document_id' => (int) $document->id,
            'doc_slug' => $document->slug,
            'title' => (string) ($document->title ?? ''),
            'source_path' => (string) $document->source_path,
            'is_canonical' => (bool) $document->is_canonical,
            'doc_text' => $this->snapshotText($document),
        ];
    }

    /**
     * Reconstruct up to {@see self::SNAPSHOT_TEXT_CHARS} chars of the document
     * text from its chunks (tenant-scoped, R30). Streams with `cursor()` and
     * stops as soon as enough text is accumulated, so a huge document doesn't
     * pull every chunk into memory only to truncate it (R3 — Copilot review).
     */
    private function snapshotText(KnowledgeDocument $document): string
    {
        $chunks = KnowledgeChunk::query()
            ->forTenant((string) $document->tenant_id)
            ->where('knowledge_document_id', $document->id)
            ->orderBy('chunk_order')
            ->select('chunk_text')
            ->cursor();

        $text = '';
        foreach ($chunks as $chunk) {
            $text .= ($text === '' ? '' : "\n\n").(string) $chunk->chunk_text;
            if (mb_strlen($text) >= self::SNAPSHOT_TEXT_CHARS) {
                break;
            }
        }

        return mb_substr(trim($text), 0, self::SNAPSHOT_TEXT_CHARS);
    }

    /**
     * Locate a document by project+source_path and delete it. Returns null
     * when no row exists at all. Already-soft-deleted rows are still
     * reachable so `force=true` can promote a soft delete to a hard delete,
     * and repeated soft-deletes are idempotent (no-op returning a soft
     * result).
     *
     * @return array{mode: string, document_id: int, project_key: string, source_path: string, file_deleted: bool, ocr_assets_deleted?: bool, artifact_deleted?: bool}|null
     */
    public function deleteByPath(string $projectKey, string $sourcePath, ?bool $force = null): ?array
    {
        $document = KnowledgeDocument::withTrashed()
            ->where('project_key', $projectKey)
            ->where('source_path', $sourcePath)
            ->first();

        if ($document === null) {
            return null;
        }

        $shouldForce = $force ?? ! (bool) config('kb.deletion.soft_delete', true);

        if ($document->trashed() && ! $shouldForce) {
            // Idempotent: the row is already soft-deleted, surface it as
            // such without touching anything.
            return [
                'mode' => 'soft',
                'document_id' => (int) $document->id,
                'project_key' => (string) $document->project_key,
                'source_path' => (string) $document->source_path,
                'file_deleted' => false,
            ];
        }

        return $this->delete($document, $force);
    }

    /**
     * Delete every active document under $basePath (recursively) whose
     * source_path is not in $existingRelativePaths. Used by
     * kb:ingest-folder --prune-orphans and by the GitHub Action when it
     * detects a file has been removed from the repository.
     *
     * R30 — `$tenantId` MUST be passed when more than one tenant uses
     * the same `project_key`. Two different tenants may legitimately
     * share `project_key='demo'`; without an explicit tenant filter the
     * orphan sweep would soft/hard-delete documents owned by OTHER
     * tenants whose source files happen to live outside the caller's
     * `$existingRelativePaths` set. New callers (PruneOrphansStep,
     * KbIngestFolderCommand, action.yml ingest job) ALWAYS pass a
     * concrete tenant_id; the `null` default is preserved only for
     * backward compatibility with legacy callers and is logged at
     * WARNING level so ops can spot unscoped runs in production.
     *
     * @param  array<int,string>  $existingRelativePaths
     * @return array<int,array{mode: string, document_id: int, project_key: string, source_path: string, file_deleted: bool, ocr_assets_deleted?: bool, artifact_deleted?: bool}>
     */
    public function deleteOrphans(
        string $projectKey,
        string $basePath,
        array $existingRelativePaths,
        ?bool $force = null,
        ?string $tenantId = null,
    ): array {
        $base = trim($basePath, '/');
        $existing = array_values(array_unique(array_map(
            static fn ($p) => ltrim((string) $p, '/'),
            $existingRelativePaths,
        )));

        $query = KnowledgeDocument::query()
            ->where('project_key', $projectKey);

        if ($tenantId !== null && $tenantId !== '') {
            // R30 — restrict the sweep to the caller's tenant so we
            // never cascade-delete another tenant's documents that
            // share the same project_key.
            $query->forTenant($tenantId);
        } else {
            // Legacy back-compat path. Surface the unscoped sweep so
            // operators can spot it during incident review — a single
            // unscoped invocation in a multi-tenant deployment is
            // enough to delete cross-tenant rows by accident.
            Log::warning('DocumentDeleter::deleteOrphans called without tenant_id — cross-tenant orphan delete possible', [
                'project_key' => $projectKey,
                'base_path' => $base,
                'existing_count' => count($existing),
            ]);
        }

        if ($base !== '') {
            // R19 — $base is a folder prefix that can contain LIKE
            // meta-chars (% / _). Escape them and pair with an explicit
            // ESCAPE clause so a stray meta-char can't widen the match and
            // cascade-delete unintended documents.
            $escapedBase = LikeEscaper::escape($base);
            $query->where(function ($q) use ($base, $escapedBase) {
                $q->whereRaw('source_path LIKE ? '.LikeEscaper::ESCAPE_SQL, [$escapedBase.'/%'])
                    ->orWhere('source_path', $base);
            });
        }

        // Push the "not in existing" filter into SQL so we don't load
        // every document for the project into memory. Chunk the array to
        // keep individual IN lists bounded when a folder tracks tens of
        // thousands of files.
        if ($existing !== []) {
            foreach (array_chunk($existing, 1000) as $chunk) {
                $query->whereNotIn('source_path', $chunk);
            }
        }

        $results = [];
        $query->orderBy('id')->chunkById(100, function ($orphans) use (&$results, $force) {
            foreach ($orphans as $orphan) {
                // v8.36 / ADR 0030 §3 — `markdown_only` retention drops the
                // original binary on purpose after the artifact commit and
                // stamps the row: a missing file that was dropped by design
                // is not an orphan, it is the retention policy working.
                $metadata = is_array($orphan->metadata) ? $orphan->metadata : [];
                if (($metadata['source_dropped'] ?? false) === true) {
                    continue;
                }
                $results[] = $this->delete($orphan, $force);
            }
        });

        return $results;
    }

    /**
     * DB-only hard delete: removes the document row + chunks (FK cascade)
     * + canonical graph nodes/edges (cascadeGraphFor) + writes the
     * deprecation audit row, but PRESERVES the physical file on disk.
     *
     * Use this from saga compensators where the source file was NOT
     * created by the failing flow — destroying the source-of-truth on
     * disk because of a transient downstream failure (e.g. canonical
     * indexer dispatch failure) is data loss.
     *
     * Per Copilot PR #115 review iteration 1 (R4 + R14 — never silently
     * destroy operator-supplied data on a recoverable failure).
     *
     * v8.36 / ADR 0030 §8 — the row's version artifact is NOT the source: it
     * was written by the failing flow for this very row, so it goes with the
     * row here too (otherwise the compensated row would leave a raw artifact
     * behind until the orphan sweep) — THROUGH the reference gate: the path
     * is the content hash, so a newer identical version that took the same
     * path meanwhile keeps its artifact. The `.ocr/` tree stays with the
     * preserved source (`ocr_assets_deleted` is false by construction).
     *
     * @return array{mode: string, document_id: int, project_key: string, source_path: string, file_deleted: bool, ocr_assets_deleted: bool, artifact_deleted: bool, canonical: array<string, mixed>|null}
     */
    public function deleteDbOnly(KnowledgeDocument $document): array
    {
        $documentId = (int) $document->id;
        $projectKey = (string) $document->project_key;
        $sourcePath = (string) $document->source_path;

        $metadata = is_array($document->metadata) ? $document->metadata : [];
        $disk = StorageNamespace::diskOf($metadata);

        $canonicalSnapshot = $this->canonicalSnapshot($document);

        DB::transaction(function () use ($document) {
            // Explicit chunk delete keeps the intent clear even though the FK
            // cascade would do the same thing.
            $document->chunks()->delete();
            $this->cascadeGraphFor($document);
            $document->forceDelete();
            $this->writeDeprecationAudit($document);
        });

        $artifactDeleted = $this->removeArtifact($disk, $document->markdown_path);

        return [
            'mode' => 'hard_db_only',
            'document_id' => $documentId,
            'project_key' => $projectKey,
            'source_path' => $sourcePath,
            'file_deleted' => false,
            'ocr_assets_deleted' => false,
            'artifact_deleted' => $artifactDeleted,
            'canonical' => $canonicalSnapshot,
        ];
    }

    /**
     * DB+graph hard delete: chunks cascade + canonical graph cascade +
     * deprecation audit, but PRESERVES the physical file on disk.
     *
     * Used by {@see \App\Flow\Definitions\DeleteDocumentFlow}'s
     * `hard-delete-rows` step so the file removal step can run as a
     * separate Flow step (with its own observability + dry-run handling)
     * AFTER the DB rows are gone.
     *
     * `artifact_deleted` reports what THIS call did to the row's version
     * artifact: true when none remains FOR THIS ROW after it — deleted, never
     * written, already gone, or now owned by a newer identical version that
     * points at the same path (the reference gate keeps it) — false when one
     * may — either a delete error, or a caller that opted out
     * (`$removeArtifact = false`) and handles the artifact itself. Never a
     * claim about work not done here.
     *
     * @return array{mode: string, document_id: int, project_key: string, source_path: string, file_deleted: bool, artifact_deleted: bool, canonical: array{is_canonical: bool, doc_id: ?string, slug: ?string, canonical_type: ?string, canonical_status: ?string}, disk: string, full_path: string}
     */
    public function deleteRowsOnly(KnowledgeDocument $document, bool $removeArtifact = true): array
    {
        $documentId = (int) $document->id;
        $projectKey = (string) $document->project_key;
        $sourcePath = (string) $document->source_path;

        $metadata = is_array($document->metadata) ? $document->metadata : [];
        $disk = StorageNamespace::diskOf($metadata);
        $prefix = array_key_exists('prefix', $metadata)
            ? (string) $metadata['prefix']
            : (string) config('kb.sources.path_prefix', '');
        // R1 — every KB source path goes through KbPath::normalize() so
        // the resulting key is byte-identical to what the ingest pipeline
        // wrote (collapses `//`, normalizes `\\`, rejects `..` traversal).
        // Mirrors CanonicalWriter::applyPathPrefix(). Iteration 4 (PR #116)
        // — resolveFullPath now returns null on un-normalisable input;
        // expose the bad path on the response so DeleteDocumentFlow's
        // file-removal step can report file_deleted=false uniformly
        // without ever attempting a disk write on a tainted key.
        $fullPath = $this->resolveFullPath($prefix, $sourcePath);

        $canonicalSnapshot = $this->canonicalSnapshot($document);

        DB::transaction(function () use ($document) {
            $document->chunks()->delete();
            $this->cascadeGraphFor($document);
            $document->forceDelete();
            $this->writeDeprecationAudit($document);
        });

        // v8.36 / ADR 0030 §8 — each row owns its own version artifact: it
        // goes with the row on EVERY hard-delete path (the Flow saga reaches
        // here, then removes the shared source file in its own step — the
        // artifact is not the source and `keep_file` does not cover it). A
        // caller that reports the removal itself (the prune) opts out — and
        // then this call claims nothing about the artifact (false).
        $artifactDeleted = $removeArtifact
            && $this->removeArtifact($disk, $document->markdown_path);

        return [
            'mode' => 'hard_rows_only',
            'document_id' => $documentId,
            'project_key' => $projectKey,
            'source_path' => $sourcePath,
            'file_deleted' => false,
            // additive (R27): whether the row's version artifact is gone
            'artifact_deleted' => $artifactDeleted,
            'canonical' => $canonicalSnapshot,
            'disk' => $disk,
            'full_path' => (string) ($fullPath ?? ''),
        ];
    }

    /**
     * Remove a previously-recorded file from a disk — and the `.ocr/` tree
     * beside it. Public wrapper around the private helper used by the legacy
     * `forceDelete()` path so {@see \App\Flow\Definitions\DeleteDocumentFlow}
     * can express the file removal as its own Flow step; both outcomes are
     * returned so a hard delete never reports a clean disk while OCR assets
     * were kept (in-flight grace) or failed to go.
     *
     * @return array{file_deleted: bool, ocr_assets_deleted: bool}
     */
    public function removeFileFor(string $disk, string $fullPath, int $documentId, string $sourcePath): array
    {
        return $this->removeFileAndOcrAssets($disk, $fullPath, $documentId, $sourcePath);
    }

    /**
     * Hard-delete every document whose deleted_at is older than $before.
     * Returns the number of documents purged.
     */
    public function pruneSoftDeleted(DateTimeInterface $before): int
    {
        $count = 0;

        // chunkById uses `id > ?` cursoring, so it remains correct even
        // though forceDelete() removes each row as we iterate.
        KnowledgeDocument::onlyTrashed()
            ->where('deleted_at', '<', $before)
            ->orderBy('id')
            ->chunkById(100, function ($rows) use (&$count) {
                foreach ($rows as $row) {
                    $this->forceDelete($row);
                    $count++;
                }
            });

        return $count;
    }

    /**
     * @return array{mode: string, document_id: int, project_key: string, source_path: string, file_deleted: bool, ocr_assets_deleted?: bool, artifact_deleted?: bool}
     */
    private function softDelete(KnowledgeDocument $document): array
    {
        $payload = [
            'mode' => 'soft',
            'document_id' => (int) $document->id,
            'project_key' => (string) $document->project_key,
            'source_path' => (string) $document->source_path,
            'file_deleted' => false,
        ];

        if ($document->trashed()) {
            return $payload;
        }

        $document->delete();

        return $payload;
    }

    /**
     * @return array{mode: string, document_id: int, project_key: string, source_path: string, file_deleted: bool, ocr_assets_deleted?: bool, artifact_deleted?: bool}
     */
    private function forceDelete(KnowledgeDocument $document): array
    {
        $documentId = (int) $document->id;
        $projectKey = (string) $document->project_key;
        $sourcePath = (string) $document->source_path;

        $metadata = is_array($document->metadata) ? $document->metadata : [];
        $disk = StorageNamespace::diskOf($metadata);
        $prefix = array_key_exists('prefix', $metadata)
            ? (string) $metadata['prefix']
            : (string) config('kb.sources.path_prefix', '');
        // R1 — same KbPath::normalize() guard as deleteRowsOnly().
        // Iteration 4 (PR #116) — resolveFullPath returns null when the
        // path is un-normalisable (traversal, empty); skip the disk
        // delete entirely in that case rather than handing
        // Storage::delete() an attacker-controlled key.
        $fullPath = $this->resolveFullPath($prefix, $sourcePath);

        $canonicalSnapshot = $this->canonicalSnapshot($document);

        DB::transaction(function () use ($document) {
            // Explicit chunk delete keeps the intent clear even though the FK
            // cascade would do the same thing.
            $document->chunks()->delete();
            $this->cascadeGraphFor($document);
            $document->forceDelete();
            $this->writeDeprecationAudit($document);
        });

        $removal = $fullPath === null
            ? ['file_deleted' => false, 'ocr_assets_deleted' => false]
            : $this->removeFileAndOcrAssets($disk, $fullPath, $documentId, $sourcePath);

        // v8.36 / ADR 0030 §8 — the row's version artifact goes with the row
        // THROUGH the reference gate: the path is the content hash, so a
        // newer identical version that took the same path meanwhile keeps
        // its artifact (nothing of this row remains there).
        $artifactDeleted = $this->removeArtifact($disk, $document->markdown_path);

        return [
            'mode' => 'hard',
            'document_id' => $documentId,
            'project_key' => $projectKey,
            'source_path' => $sourcePath,
            'file_deleted' => $removal['file_deleted'],
            // v8.36 / ADR 0029 §4 — additive (R27): whether the `.ocr/` tree
            // is gone too. A hard delete that reports the source removed but
            // leaves generated OCR data behind says so here, never silently.
            'ocr_assets_deleted' => $removal['ocr_assets_deleted'],
            // v8.36 / ADR 0030 §8 — additive (R27): the row's version artifact.
            'artifact_deleted' => $artifactDeleted,
            'canonical' => $canonicalSnapshot,
        ];
    }

    /**
     * Remove the kb_node(s) this document owns. The composite FK on
     * kb_edges cascades both outgoing AND incoming edges automatically.
     *
     * Preferred match is by `source_doc_id` (the stable business id), which
     * is what {@see \App\Jobs\CanonicalIndexerJob} stamps on the node it
     * creates. When the canonical doc has a slug but no `id` (the
     * CanonicalParser validator does not require `id`), we fall back to
     * matching by `(project_key, node_uid = slug)` so cascade still happens.
     *
     * No-op only when BOTH `doc_id` and `slug` are null (truly non-canonical).
     */
    private function cascadeGraphFor(KnowledgeDocument $document): void
    {
        // R30/R31 — slug + doc_id are tenant-scoped per CLAUDE.md R10. Two
        // tenants may legitimately share the same `(project_key, doc_id)` /
        // `(project_key, slug)` combination; deleting tenant A's document
        // must NOT cascade-delete tenant B's graph nodes. The composite FK
        // on `kb_edges.(project_key, from/to_node_uid)` cascades the edges
        // for whichever node is removed — bounding the node delete by
        // tenant is sufficient.
        $tenantId = (string) $document->tenant_id;

        if ($document->doc_id !== null) {
            KbNode::where('tenant_id', $tenantId)
                ->where('project_key', $document->project_key)
                ->where('source_doc_id', $document->doc_id)
                ->delete();
            return;
        }
        if ($document->slug !== null) {
            KbNode::where('tenant_id', $tenantId)
                ->where('project_key', $document->project_key)
                ->where('node_uid', $document->slug)
                ->delete();
            return;
        }
        // Truly non-canonical document — nothing in the graph to remove.
    }

    /**
     * @return array{is_canonical: bool, doc_id: ?string, slug: ?string, canonical_type: ?string, canonical_status: ?string}
     */
    private function canonicalSnapshot(KnowledgeDocument $document): array
    {
        return [
            'is_canonical' => (bool) $document->is_canonical,
            'doc_id' => $document->doc_id,
            'slug' => $document->slug,
            'canonical_type' => $document->canonical_type,
            'canonical_status' => $document->canonical_status,
        ];
    }

    private function writeDeprecationAudit(KnowledgeDocument $document): void
    {
        if (! (bool) config('kb.canonical.audit_enabled', true)) {
            return;
        }
        if ($document->doc_id === null && $document->slug === null) {
            return;
        }
        KbCanonicalAudit::create([
            'project_key' => $document->project_key,
            'doc_id' => $document->doc_id,
            'slug' => $document->slug,
            'event_type' => 'deprecated',
            'actor' => 'document-deleter',
            'before_json' => [
                'canonical_type' => $document->canonical_type,
                'canonical_status' => $document->canonical_status,
            ],
            'after_json' => null,
            'metadata_json' => ['source_path' => $document->source_path],
        ]);
    }

    /**
     * R1 — apply the canonical KB path-normalisation rules so the disk
     * key we hand to {@see Storage::delete()} is byte-identical to what
     * the ingest path wrote (no double slashes, no `.`/`..` traversal,
     * `\\` → `/`). Mirrors {@see \App\Services\Kb\Canonical\CanonicalWriter::applyPathPrefix()}.
     *
     * Iteration 4 (PR #116) — R1 + R4 + R14. Returns `null` when the input
     * cannot be normalised (empty path, `.`, `..` traversal segment).
     * Callers MUST treat null as "do not touch the disk" — the previous
     * fallback to a hand-built `ltrim()` chain DEFEATED KbPath's
     * traversal guard by handing the un-normalised path back to
     * {@see Storage::delete()}, blowing the radius open to attacker-
     * controlled writes. Better silent no-op than blast-radius write.
     */
    private function resolveFullPath(string $prefix, string $sourcePath): ?string
    {
        try {
            if ($prefix === '') {
                return KbPath::normalize($sourcePath);
            }
            return KbPath::normalize($prefix.'/'.$sourcePath);
        } catch (\InvalidArgumentException $e) {
            // Caught traversal/empty path; refuse to delete with the
            // un-normalized path. R4: surface the refusal in the log so
            // operators can investigate the bad metadata.
            Log::warning('DocumentDeleter: refusing file delete on un-normalizable path', [
                'source_path' => $sourcePath,
                'prefix' => $prefix,
                'reason' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * ADR 0030 §8 — the ONE gate every artifact removal goes through (the
     * hard delete, the prune of a version, the orphan sweep): under the
     * artifact path's lock — the lock a publish holds around its move — the
     * references are re-checked and the file is removed only when NO row of
     * any tenant (live, archived, trashed) still points at it on this disk.
     * The path is the content hash, so an identical ingest that ran between
     * a caller's decision and this call recreated the very same path for a
     * new row: without the re-check the caller would delete a live row's
     * artifact. A row without a usable recorded disk (absent, null or empty —
     * StorageNamespace) references the path wherever the check looks (fail
     * closed). Returns the store's outcome,
     * or `KEPT` when the re-check found a referencing row.
     */
    public function removeArtifactIfUnreferenced(string $disk, string $path): string
    {
        $store = app(ConversionArtifactStore::class);
        try {
            return $store->underPathLock($disk, $path, function (HeldLock $held) use ($store, $disk, $path): string {
                if ($this->artifactReferenced($disk, $path)) {
                    return ConversionArtifactStore::KEPT;
                }
                // The re-check may have outlived the lock's TTL: the delete is
                // refused on a lock this holder no longer owns (→ `failed`).
                $held->assertHeld('artifact removal');

                return $store->remove($disk, $path);
            });
        } catch (\Throwable $e) {
            // The gate keeps the store's contract — never an exception: a
            // lock that could not be taken (contention past the wait, a lock
            // store outage) or a reference check the database refused is a
            // removal that did NOT happen, reported as `failed` so the caller
            // counts it and exits non-zero (R14), never a stack trace that
            // loses the rest of a prune mid-corpus.
            Log::warning('DocumentDeleter: artifact removal not attempted — the reference gate could not decide', ['disk' => $disk, 'markdown_path' => $path, 'exception' => $e::class, 'error' => $e->getMessage()]);

            return ConversionArtifactStore::FAILED;
        }
    }

    /**
     * Whether any row — live, archived or trashed, any tenant — points at
     * this artifact path on this disk. A row without a usable recorded disk
     * (absent, null, empty or malformed — StorageNamespace, judged in PHP so
     * every shape counts) is a reference (fail closed): the prune's snapshot
     * (`documentRecordsStorageNamespace()`) and this gate answer alike, and a
     * path such a row points at is kept, never deleted under it.
     */
    public function artifactReferenced(string $disk, string $path): bool
    {
        return $this->artifactsReferenced($disk, [$path]) !== [];
    }

    /**
     * The reference predicate, batched: {@see artifactReferenced()} is its
     * single-path wrapper (inside the locked gate), the dry-run preview
     * calls it directly — one query per chunk of 500 (R3). Returns the
     * referenced paths as keys.
     *
     * @param  list<string>  $paths
     * @return array<string, true>
     */
    public function artifactsReferenced(string $disk, array $paths): array
    {
        if ($paths === []) {
            return [];
        }
        $referenced = [];
        foreach (array_chunk($paths, 500) as $chunk) {
            // The rows are narrowed by path in SQL (the `markdown_path`
            // index); the namespace is judged in PHP with the ONE reading
            // every consumer shares (StorageNamespace): a recorded disk
            // that matches, or no usable recorded disk at all (absent,
            // null, empty OR malformed — a shape SQL cannot express
            // portably), counts as a reference. Fail closed.
            $rows = KnowledgeDocument::withoutGlobalScopes()
                ->whereIn('markdown_path', $chunk)
                ->select(['id', 'markdown_path', 'metadata'])
                ->cursor();
            $wanted = count(array_unique($chunk));
            $found = 0; // per chunk: `$referenced` accumulates across chunks and must not satisfy this chunk's target
            foreach ($rows as $row) {
                $recorded = StorageNamespace::recordedDisk($row->metadata);
                if ($recorded !== null && $recorded !== $disk) {
                    continue;
                }
                $path = (string) $row->markdown_path;
                if (isset($referenced[$path])) {
                    continue;
                }
                $referenced[$path] = true;
                $found++;
                if ($found >= $wanted) {
                    break; // every path of this chunk is referenced: nothing left to learn from the remaining rows
                }
            }
        }

        return $referenced;
    }

    /**
     * Remove an orphan SOURCE file (the orphan-file sweep's deletion) after
     * re-checking that no row of any tenant references the key any more
     * (ADR 0030 §3): a row that took the key between the sweep's snapshot
     * and this call keeps its file (KEPT) — the re-check narrows the window
     * for every source. While conversion artifacts are on, the re-check and
     * the delete also run under the storage key's lock — the lock the
     * `markdown_only` drop and the row commits of non-Markdown sources hold
     * — so the sweep cannot slip between their two halves (a Markdown
     * source's commit takes no lock: for it the re-check alone stands); a key
     * a writer holds right now is in flight and kept (KEPT, the next sweep
     * decides), and the delete runs only while the lock is still owned (a
     * lapsed TTL is a refusal). With artifacts off nothing else takes that
     * lock, so none is taken here either (R43: the sweep never depends on a
     * lock store it was not configured for). A re-check the database refused
     * or a disk that refused is FAILED — reported, never a stack trace
     * mid-sweep and never a delete under a live row.
     *
     * @return string one of ConversionArtifactStore::REMOVED | ABSENT | KEPT | FAILED
     */
    public function removeSourceFileIfUnreferenced(string $disk, string $fullPath, string $sourcePath): string
    {
        $lock = null;
        $held = null;
        if (app(ConversionArtifactStore::class)->enabled()) {
            try {
                $lock = SourceKeyLock::make($disk, $fullPath);
                $lock->block(SourceKeyLock::waitSeconds());
                $held = new HeldLock($lock, 'storage key');
            } catch (LockTimeoutException) {
                // Another holder (a row commit, a drop) is on this key right
                // now: the file is in flight, not an orphan to decide today.
                Log::info('DocumentDeleter: orphan source kept — the storage key is held by a concurrent writer; the next sweep decides', ['disk' => $disk, 'path' => $fullPath]);

                return ConversionArtifactStore::KEPT;
            } catch (\Throwable $e) {
                Log::warning('DocumentDeleter: orphan source not removed — the storage key lock could not be taken', ['disk' => $disk, 'path' => $fullPath, 'exception' => $e::class, 'error' => $e->getMessage()]);

                return ConversionArtifactStore::FAILED;
            }
        }
        try {
            if ($this->firstDocumentReferencingStorageKey($disk, $fullPath, $sourcePath) !== null) {
                return ConversionArtifactStore::KEPT;
            }
            $storage = Storage::disk($disk);
            if (! $storage->exists($fullPath)) {
                return ConversionArtifactStore::ABSENT;
            }
            // Right before the irreversible step, after the existence probe
            // (a network round-trip on a bucket disk): a lapsed TTL refuses.
            $held?->assertHeld('orphan source removal');

            return $storage->delete($fullPath) ? ConversionArtifactStore::REMOVED : ConversionArtifactStore::FAILED;
        } catch (\Throwable $e) {
            Log::warning('DocumentDeleter: orphan source not removed — the reference gate could not decide', ['disk' => $disk, 'path' => $fullPath, 'exception' => $e::class, 'error' => $e->getMessage()]);

            return ConversionArtifactStore::FAILED;
        } finally {
            HeldLock::releaseQuietly($lock);
        }
    }

    /**
     * Remove the row's own version artifact (ADR 0030 §8). Returns true when
     * NO artifact remains FOR THE ROW afterwards (deleted, never written,
     * already gone, or now owned by a newer identical version that points
     * at the same path); false when the removal was refused or failed (the disk
     * refused, or the pointer is not a contained artifact path) — this call
     * then cannot assert that nothing remains, and the caller reports it
     * instead of a warning nobody reads (R14).
     */
    private function removeArtifact(string $disk, mixed $markdownPath): bool
    {
        if (! is_string($markdownPath) || $markdownPath === '') {
            return true;
        }
        // The gate says what happened — removed, absent (already gone),
        // kept (a newer identical version points at the same path now:
        // nothing of THIS row remains there), failed (the disk refused, the
        // path is not contained, or the gate could not decide — logged
        // there, never an exception here) — and a refusal is reported as
        // such, never masked by a raw probe that bypasses the store's
        // containment check.
        return $this->removeArtifactIfUnreferenced($disk, $markdownPath) !== ConversionArtifactStore::FAILED;
    }

    /**
     * Remove the `{fullPath}.ocr/` tree. Returns true when NO OCR tree
     * remains for the source afterwards (removed, or never there); false
     * when one is still on the disk — a purge error, or a run kept inside
     * the in-flight grace (ADR 0029 §6) that the orphan sweep removes once
     * aged. The result is reported, never swallowed (R14): a hard delete
     * that leaves generated OCR data behind must say so.
     */
    private function removeOcrAssets(string $disk, string $fullPath, int $documentId): bool
    {
        try {
            app(OcrFigureStore::class)->purgeBeside($disk, $fullPath);

            return ! Storage::disk($disk)->directoryExists($fullPath.OcrFigureStore::DIR_SUFFIX);
        } catch (\Throwable $e) {
            Log::warning('DocumentDeleter: failed to remove OCR assets', [
                'document_id' => $documentId,
                'disk' => $disk,
                'full_path' => $fullPath,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function removeFile(string $disk, string $fullPath, int $documentId, string $sourcePath): bool
    {
        return $this->removeFileAndOcrAssets($disk, $fullPath, $documentId, $sourcePath)['file_deleted'];
    }

    /**
     * @return array{file_deleted: bool, ocr_assets_deleted: bool}  `ocr_assets_deleted`
     *         is true when no `.ocr/` tree remains for the source — including the
     *         paths that never touch the disk (a shared key, a failed reference
     *         check) only when none was there to begin with.
     */
    private function removeFileAndOcrAssets(string $disk, string $fullPath, int $documentId, string $sourcePath): array
    {
        $result = $this->removeSourceObject($disk, $fullPath, $documentId, $sourcePath);
        if ($result['ocr_assets_deleted'] !== null) {
            return ['file_deleted' => $result['file_deleted'], 'ocr_assets_deleted' => $result['ocr_assets_deleted']];
        }
        // The purge did not run (kept/refused before it): honest answer is
        // whether a tree is there, never an assumed "clean".
        try {
            $remains = Storage::disk($disk)->directoryExists($fullPath.OcrFigureStore::DIR_SUFFIX);
        } catch (\Throwable) {
            $remains = true;
        }

        return ['file_deleted' => $result['file_deleted'], 'ocr_assets_deleted' => ! $remains];
    }

    /**
     * @return array{file_deleted: bool, ocr_assets_deleted: ?bool}  null = the purge was not attempted
     */
    private function removeSourceObject(string $disk, string $fullPath, int $documentId, string $sourcePath): array
    {
        try {
            $referencingDocumentId = $this->firstDocumentReferencingStorageKey(
                $disk,
                $fullPath,
                $sourcePath,
            );
        } catch (\InvalidArgumentException $e) {
            Log::warning('DocumentDeleter: refusing file delete while checking shared references', [
                'document_id' => $documentId,
                'source_path' => $sourcePath,
                'disk' => $disk,
                'full_path' => $fullPath,
                'reason' => $e->getMessage(),
            ]);

            return ['file_deleted' => false, 'ocr_assets_deleted' => null];
        } catch (\Throwable $e) {
            // Reference discovery is a safety gate. If it cannot complete,
            // keep the shared object rather than risk deleting bytes still
            // owned by another version; the database deletion has already
            // committed and must remain best-effort with respect to storage.
            Log::warning('DocumentDeleter: failed to check shared file references', [
                'document_id' => $documentId,
                'source_path' => $sourcePath,
                'disk' => $disk,
                'full_path' => $fullPath,
                'error' => $e->getMessage(),
            ]);

            return ['file_deleted' => false, 'ocr_assets_deleted' => null];
        }

        if ($referencingDocumentId !== null) {
            // Different document versions share one physical source object:
            // `(disk, prefix/source_path)` identifies the file, while every
            // re-ingest gets its own knowledge_documents row. Deleting an old
            // or soft-deleted version must therefore never remove the object
            // while a live/archived/trashed sibling still references it.
            Log::info('DocumentDeleter: preserving physical file still referenced by another document', [
                'deleted_document_id' => $documentId,
                'referencing_document_id' => $referencingDocumentId,
                'source_path' => $sourcePath,
                'disk' => $disk,
                'full_path' => $fullPath,
            ]);

            return ['file_deleted' => false, 'ocr_assets_deleted' => null];
        }

        // v8.36 / ADR 0029 — the OCR assets (`{fullPath}.ocr/`) belong to the
        // same storage key and pass the same reference gate above: they go
        // when the last row referencing the source goes, never before.
        $ocrAssetsDeleted = $this->removeOcrAssets($disk, $fullPath, $documentId);

        try {
            $storage = Storage::disk($disk);
            if (! $storage->exists($fullPath)) {
                return ['file_deleted' => false, 'ocr_assets_deleted' => $ocrAssetsDeleted];
            }

            return ['file_deleted' => (bool) $storage->delete($fullPath), 'ocr_assets_deleted' => $ocrAssetsDeleted];
        } catch (\Throwable $e) {
            // A stale/missing file on the disk must never stop a DB deletion
            // from completing — log and move on.
            Log::warning('DocumentDeleter: failed to remove physical file', [
                'document_id' => $documentId,
                'source_path' => $sourcePath,
                'disk' => $disk,
                'full_path' => $fullPath,
                'error' => $e->getMessage(),
            ]);

            return ['file_deleted' => false, 'ocr_assets_deleted' => $ocrAssetsDeleted];
        }
    }

    /**
     * Public reference gate for callers that hold a resolved storage key and
     * must not delete a file a knowledge_documents row (any tenant, trashed
     * included) still points at — the connector bridge's refused-image path.
     * Returns the referencing document id, or null when nothing references it.
     */
    public function documentReferencingStorageKey(string $disk, string $fullPath, string $sourcePath): ?int
    {
        return $this->firstDocumentReferencingStorageKey($disk, $fullPath, $sourcePath);
    }

    /**
     * Public reference gate for ONE recorded OCR run (ADR 0030 §8): the id of
     * a remaining row — any tenant, live, archived or soft-deleted, the same
     * documented R30 exception as the storage-key gate, because the run
     * directory sits beside a source object that is not tenant namespaced —
     * whose `metadata.converter.ocr.run` names `$run` AND whose recorded
     * storage namespace resolves `$sourcePath` to the very object the run
     * directory sits beside (`$prefix/$sourcePath` on `$disk`), or null when
     * nothing references it. A run directory is physically scoped by disk,
     * prefix, source path and run key: a same-named row under another disk
     * or prefix references ANOTHER run directory and neither keeps this one
     * alive nor is ignored — the same identity {@see documentReferencesStorageKey()}
     * applies to the source object, legacy rows without a recorded disk
     * counting as references (fail closed). The prune and the orphan sweep
     * ask here before they purge a run, so "referenced" means the same thing
     * for the hard delete and for the retention paths.
     */
    public function documentReferencingOcrRun(string $disk, string $prefix, string $sourcePath, string $run): ?int
    {
        $normalizedSourcePath = KbPath::normalize($sourcePath);
        $fullPath = $this->resolveFullPath($prefix, $normalizedSourcePath);
        if ($fullPath === null) {
            return null;
        }
        // R3 — the run key is compared in SQL (JSON path, portable across
        // pgsql and SQLite), never by decoding every version's metadata; the
        // (few) rows naming the run are then judged on their namespace.
        $rows = KnowledgeDocument::query()
            ->withoutGlobalScopes()
            ->where('source_path', $normalizedSourcePath)
            ->where('metadata->converter->ocr->run', $run)
            ->select(['id', 'source_path', 'metadata'])
            ->orderBy('id')
            ->cursor();
        foreach ($rows as $row) {
            if ($this->documentReferencesStorageKey($row, $disk, $fullPath)) {
                return (int) $row->id;
            }
        }

        return null;
    }

    /**
     * The batched form of {@see documentReferencingOcrRun()} for the orphan
     * sweep (R3): every candidate `(source path, run)` of a namespace is
     * judged with ONE bounded query per 500 candidates instead of one query
     * per run — a shared disk accumulates runs, and the nightly sweep must not
     * grow a query per run. Returns, for every candidate that IS referenced,
     * the id of the first row referencing it, keyed
     * `"{normalized source path}|{run}"` (`KbPath::normalize()` of the
     * candidate's path — two spellings of one path are one candidate); an
     * absent key means no row of any tenant (trashed included) names the run
     * under this namespace. A candidate whose path cannot be normalized is
     * not judged (absent, like the single gate's null).
     *
     * @param  list<array{0: string, 1: string}>  $candidates  [source path (disk-relative, prefix stripped), run]
     * @return array<string, int>
     */
    public function documentsReferencingOcrRuns(string $disk, string $prefix, array $candidates): array
    {
        $referenced = [];
        foreach (array_chunk($candidates, 500) as $chunk) {
            $wanted = [];
            $paths = [];
            $runs = [];
            foreach ($chunk as [$sourcePath, $run]) {
                try {
                    $normalizedSourcePath = KbPath::normalize($sourcePath);
                } catch (\InvalidArgumentException) {
                    continue;
                }
                $fullPath = $this->resolveFullPath($prefix, $normalizedSourcePath);
                if ($fullPath === null) {
                    continue;
                }
                $wanted[$normalizedSourcePath.'|'.$run] = $fullPath;
                $paths[$normalizedSourcePath] = true;
                $runs[$run] = true;
            }
            if ($wanted === []) {
                continue;
            }
            // Narrowed in SQL on both halves (paths × runs, each list ≤ 500),
            // then each row is matched to its exact candidate and judged on
            // its recorded namespace — the same predicate as the single gate.
            // Bindings stay strings: an all-digit path would become an int
            // array key and be bound as one.
            $rows = KnowledgeDocument::query()
                ->withoutGlobalScopes()
                ->whereIn('source_path', array_map('strval', array_keys($paths)))
                ->whereIn('metadata->converter->ocr->run', array_map('strval', array_keys($runs)))
                ->select(['id', 'source_path', 'metadata'])
                ->orderBy('id')
                ->cursor();
            foreach ($rows as $row) {
                $metadata = is_array($row->metadata) ? $row->metadata : [];
                $run = $metadata['converter']['ocr']['run'] ?? null;
                if (! is_string($run)) {
                    continue;
                }
                // The row matched an already-normalized path in SQL: its key is that path as stored.
                $key = (string) $row->source_path.'|'.$run;
                if (! isset($wanted[$key]) || isset($referenced[$key])) {
                    continue;
                }
                if ($this->documentReferencesStorageKey($row, $disk, $wanted[$key])) {
                    $referenced[$key] = (int) $row->id;
                }
            }
        }

        return $referenced;
    }

    /**
     * Return the first remaining row that resolves to the same physical
     * storage object. The lookup deliberately crosses tenant/access/soft-delete
     * scopes: a shared bucket key is global infrastructure state, so deleting
     * tenant A's row must not remove bytes still referenced by tenant B.
     *
     * Rows are narrowed by the logical source path in SQL, then streamed so a
     * long version history stays memory-safe. Each row is judged by
     * {@see documentReferencesStorageKey()}: the recorded namespace resolved
     * with deletion's fallbacks, or — for a row without a usable recorded
     * disk ({@see \App\Support\Kb\StorageNamespace}) — a reference by path,
     * fail closed.
     */
    private function firstDocumentReferencingStorageKey(
        string $disk,
        string $fullPath,
        string $sourcePath,
    ): ?int {
        $normalizedSourcePath = KbPath::normalize($sourcePath);
        $normalizedFullPath = KbPath::normalize($fullPath);

        $documents = KnowledgeDocument::query()
            ->withoutGlobalScopes()
            ->where('source_path', $normalizedSourcePath)
            ->select(['id', 'source_path', 'metadata'])
            ->cursor();

        foreach ($documents as $document) {
            if ($this->documentReferencesStorageKey($document, $disk, $normalizedFullPath)) {
                return (int) $document->id;
            }
        }

        return null;
    }

    /**
     * THE one test of "this row references that physical object", shared by
     * every deleting consumer (this gate — the dangling-tree sweep, the
     * connector bridge, the deleter's own hard delete — and the orphan-file
     * sweep): a row that recorded its storage namespace references the
     * object only when that namespace resolves to `$fullPath` on `$disk`;
     * a row without a usable recorded disk (absent, null or empty — ingested
     * before the namespace was persisted, or stamped with an unusable value;
     * {@see \App\Support\Kb\StorageNamespace}) references the object on every
     * disk its logical path matches — deletion fails closed, it never guesses
     * a disk.
     */
    public function documentReferencesStorageKey(KnowledgeDocument $document, string $disk, string $fullPath): bool
    {
        if (! $this->documentRecordsStorageNamespace($document)) {
            return true;
        }

        return $this->documentResolvesToStorageKey($document, $disk, $fullPath);
    }

    /**
     * Whether the row persisted the storage namespace its file lives in.
     * The disk is the decisive half (`metadata.disk`; the ingest job records
     * it together with `metadata.prefix`): a row without a non-empty string
     * there is a legacy (or ambiguous) row, whatever else its metadata
     * carries, and is never resolved to a guessed disk by a deleting
     * consumer.
     */
    public function documentRecordsStorageNamespace(KnowledgeDocument $document): bool
    {
        // One reading for every consumer (StorageNamespace): a null, empty
        // or malformed `metadata.disk` is NOT a recorded namespace but an
        // ambiguous legacy row — treated as a reference wherever a deleting
        // consumer looks, never resolved to a guessed disk.
        return StorageNamespace::recordedDisk($document->metadata) !== null;
    }

    /**
     * Whether this row's RECORDED storage namespace (`metadata.disk` /
     * `metadata.prefix`, deletion's configured defaults when absent) resolves its
     * `source_path` to exactly `$fullPath` on `$disk`. Only ever reached for
     * a row that recorded its disk ({@see documentReferencesStorageKey()});
     * a row without one is never resolved to a guessed disk.
     */
    private function documentResolvesToStorageKey(KnowledgeDocument $document, string $disk, string $fullPath): bool
    {
        $metadata = is_array($document->metadata) ? $document->metadata : [];
        // The same fallbacks deletion applies (`kb.sources.disk` /
        // `kb.sources.path_prefix`). A row without a usable recorded disk
        // (absent, null or empty — StorageNamespace) never reaches a
        // resolution in the deleting consumers — they treat it as a
        // reference by path ({@see documentRecordsStorageNamespace()}).
        $candidateDisk = StorageNamespace::diskOf($metadata);
        $candidatePrefix = array_key_exists('prefix', $metadata)
            ? (string) $metadata['prefix']
            : (string) config('kb.sources.path_prefix', '');
        $candidateFullPath = $this->resolveFullPath($candidatePrefix, (string) $document->source_path);

        try {
            $normalizedFullPath = KbPath::normalize($fullPath);
        } catch (\InvalidArgumentException) {
            return false;
        }

        return $candidateDisk === $disk && $candidateFullPath === $normalizedFullPath;
    }
}
