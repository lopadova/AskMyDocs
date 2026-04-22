<?php

namespace App\Services\Kb;

use App\Models\KbCanonicalAudit;
use App\Models\KbNode;
use App\Models\KnowledgeDocument;
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
     * @param  array<int,string>  $existingRelativePaths
     * @return array<int,array{mode: string, document_id: int, project_key: string, source_path: string, file_deleted: bool}>
     */
    public function deleteOrphans(
        string $projectKey,
        string $basePath,
        array $existingRelativePaths,
        ?bool $force = null,
    ): array {
        $base = trim($basePath, '/');
        $existing = array_values(array_unique(array_map(
            static fn ($p) => ltrim((string) $p, '/'),
            $existingRelativePaths,
        )));

        $query = KnowledgeDocument::query()
            ->where('project_key', $projectKey);

        if ($base !== '') {
            $query->where(function ($q) use ($base) {
                $q->where('source_path', 'like', $base.'/%')
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
        $fullPath = ltrim(trim($prefix, '/').'/'.ltrim($sourcePath, '/'), '/');

        $canonicalSnapshot = $this->canonicalSnapshot($document);

        DB::transaction(function () use ($document) {
            // Explicit chunk delete keeps the intent clear even though the FK
            // cascade would do the same thing.
            $document->chunks()->delete();
            $this->cascadeGraphFor($document);
            $document->forceDelete();
            $this->writeDeprecationAudit($document);
        });

        $fileDeleted = $this->removeFile($disk, $fullPath, $documentId, $sourcePath);

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
     * No-op on non-canonical documents (doc_id is null).
     */
    private function cascadeGraphFor(KnowledgeDocument $document): void
    {
        if ($document->doc_id === null) {
            return;
        }
        KbNode::where('project_key', $document->project_key)
            ->where('source_doc_id', $document->doc_id)
            ->delete();
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
