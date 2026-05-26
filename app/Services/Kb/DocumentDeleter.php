<?php

declare(strict_types=1);

namespace App\Services\Kb;

use App\Models\KbCanonicalAudit;
use App\Models\KbNode;
use App\Models\KnowledgeDocument;
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
    /**
     * Delete a single document. When $force is null the behaviour is driven
     * by the `kb.deletion.soft_delete` config flag (default: soft).
     *
     * @return array{mode: string, document_id: int, project_key: string, source_path: string, file_deleted: bool}
     */
    public function delete(KnowledgeDocument $document, ?bool $force = null): array
    {
        $shouldForce = $force ?? ! (bool) config('kb.deletion.soft_delete', true);

        return $shouldForce
            ? $this->forceDelete($document)
            : $this->softDelete($document);
    }

    /**
     * Locate a document by project+source_path and delete it. Returns null
     * when no row exists at all. Already-soft-deleted rows are still
     * reachable so `force=true` can promote a soft delete to a hard delete,
     * and repeated soft-deletes are idempotent (no-op returning a soft
     * result).
     *
     * @return array{mode: string, document_id: int, project_key: string, source_path: string, file_deleted: bool}|null
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
     * @return array<int,array{mode: string, document_id: int, project_key: string, source_path: string, file_deleted: bool}>
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
     * @return array{mode: string, document_id: int, project_key: string, source_path: string, file_deleted: bool, canonical: array<string, mixed>|null}
     */
    public function deleteDbOnly(KnowledgeDocument $document): array
    {
        $documentId = (int) $document->id;
        $projectKey = (string) $document->project_key;
        $sourcePath = (string) $document->source_path;

        $canonicalSnapshot = $this->canonicalSnapshot($document);

        DB::transaction(function () use ($document) {
            // Explicit chunk delete keeps the intent clear even though the FK
            // cascade would do the same thing.
            $document->chunks()->delete();
            $this->cascadeGraphFor($document);
            $document->forceDelete();
            $this->writeDeprecationAudit($document);
        });

        return [
            'mode' => 'hard_db_only',
            'document_id' => $documentId,
            'project_key' => $projectKey,
            'source_path' => $sourcePath,
            'file_deleted' => false,
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
     * @return array{mode: string, document_id: int, project_key: string, source_path: string, file_deleted: bool, canonical: array<string, mixed>|null, disk: string, full_path: string}
     */
    public function deleteRowsOnly(KnowledgeDocument $document): array
    {
        $documentId = (int) $document->id;
        $projectKey = (string) $document->project_key;
        $sourcePath = (string) $document->source_path;

        $metadata = is_array($document->metadata) ? $document->metadata : [];
        $disk = (string) ($metadata['disk'] ?? config('kb.sources.disk', 'kb'));
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

        return [
            'mode' => 'hard_rows_only',
            'document_id' => $documentId,
            'project_key' => $projectKey,
            'source_path' => $sourcePath,
            'file_deleted' => false,
            'canonical' => $canonicalSnapshot,
            'disk' => $disk,
            'full_path' => (string) ($fullPath ?? ''),
        ];
    }

    /**
     * Remove a previously-recorded file from a disk. Public wrapper around
     * the private {@see removeFile()} helper used by the legacy
     * `forceDelete()` path so {@see \App\Flow\Definitions\DeleteDocumentFlow}
     * can express the file removal as its own Flow step.
     */
    public function removeFileFor(string $disk, string $fullPath, int $documentId, string $sourcePath): bool
    {
        return $this->removeFile($disk, $fullPath, $documentId, $sourcePath);
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
     * @return array{mode: string, document_id: int, project_key: string, source_path: string, file_deleted: bool}
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
     * @return array{mode: string, document_id: int, project_key: string, source_path: string, file_deleted: bool}
     */
    private function forceDelete(KnowledgeDocument $document): array
    {
        $documentId = (int) $document->id;
        $projectKey = (string) $document->project_key;
        $sourcePath = (string) $document->source_path;

        $metadata = is_array($document->metadata) ? $document->metadata : [];
        $disk = (string) ($metadata['disk'] ?? config('kb.sources.disk', 'kb'));
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

        $fileDeleted = $fullPath === null
            ? false
            : $this->removeFile($disk, $fullPath, $documentId, $sourcePath);

        return [
            'mode' => 'hard',
            'document_id' => $documentId,
            'project_key' => $projectKey,
            'source_path' => $sourcePath,
            'file_deleted' => $fileDeleted,
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

    private function removeFile(string $disk, string $fullPath, int $documentId, string $sourcePath): bool
    {
        try {
            $storage = Storage::disk($disk);
            if (! $storage->exists($fullPath)) {
                return false;
            }

            return (bool) $storage->delete($fullPath);
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

            return false;
        }
    }
}
