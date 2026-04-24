<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Resources\Admin\Kb\KbAuditResource;
use App\Http\Resources\Admin\Kb\KbDocumentResource;
use App\Models\KbCanonicalAudit;
use App\Models\KnowledgeDocument;
use App\Services\Kb\DocumentDeleter;
use App\Support\KbDiskResolver;
use App\Support\KbPath;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * PR9 / Phase G2 — admin KB document detail endpoints (READ-ONLY).
 *
 * Scope boundary: this controller exposes the surfaces needed to
 * browse, preview, download, print, restore and delete a document.
 * It does NOT provide editing (G3 — updateRaw) or graph rendering /
 * PDF export (G4). The graph and PDF endpoints are explicitly
 * excluded; route bindings inside the admin group still go through
 * `withTrashed()` so every subsequent microphase can keep the same
 * binding contract.
 *
 * Canonical awareness (R10): `show` uses the implicit default-scoped
 * binding for live docs and a `withTrashed()` binding shim (registered
 * at the route layer) for trashed ones. The audit history filter
 * scopes by `(project_key, doc_id, slug)` exactly as
 * `kb_canonical_audit` stores it — audits survive hard deletes, so
 * the history endpoint must tolerate a null `doc_id` + `slug` (raw
 * non-canonical rows simply return an empty page).
 *
 * Disk reads (raw / download / print) use `KbDiskResolver::forProject()`
 * (R8) so per-project disks resolved in PR1 are honoured. `source_path`
 * is always normalised via {@see KbPath::normalize()} (R1).
 */
class KbDocumentController extends Controller
{
    public function __construct(private readonly DocumentDeleter $deleter) {}

    /**
     * GET /api/admin/kb/documents/{document}
     *
     * Route binding already resolved the row. When `?with_trashed=1`
     * is set the admin-side binding shim uses `withTrashed()` so
     * soft-deleted rows are reachable.
     */
    public function show(KnowledgeDocument $document): KbDocumentResource
    {
        $document->loadMissing('tags');

        $chunksCount = (int) $document->chunks()->count();

        [$auditQuery, $auditsCount, $recent] = $this->auditComponentsFor($document, limit: 20);
        unset($auditQuery); // only used for counts + top slice here

        return (new KbDocumentResource($document))->additional([
            'chunks_count' => $chunksCount,
            'audits_count' => $auditsCount,
            'recent_audits' => KbAuditResource::collection($recent)->resolve(),
        ]);
    }

    /**
     * GET /api/admin/kb/documents/{document}/raw
     *
     * Returns the raw markdown payload + hash + mime so the SPA can
     * render the Preview tab without a second lookup. Storage lookup
     * goes through `KbDiskResolver` + `KbPath::normalize()` — the
     * ingest side stores identical normalised paths, so the two
     * flows line up (R1).
     */
    public function raw(KnowledgeDocument $document): JsonResponse
    {
        $sourcePath = KbPath::normalize((string) $document->source_path);
        $disk = $this->resolveDiskFor($document);
        $fullPath = $this->fullPathFor($document, $sourcePath);

        $storage = Storage::disk($disk);

        if (! $storage->exists($fullPath)) {
            return response()->json(
                ['message' => 'Markdown file not found on disk.', 'path' => $sourcePath, 'disk' => $disk],
                Response::HTTP_NOT_FOUND,
            );
        }

        $content = $storage->get($fullPath);
        if ($content === null) {
            // Storage::get returns null on failure — surface as 500 (R4).
            return response()->json(
                ['message' => 'Could not read markdown file from disk.'],
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        return response()->json([
            'path' => $sourcePath,
            'disk' => $disk,
            'mime' => $document->mime_type ?? 'text/markdown',
            'content' => $content,
            'content_hash' => hash('sha256', $content),
        ]);
    }

    /**
     * GET /api/admin/kb/documents/{document}/download
     */
    public function download(KnowledgeDocument $document): StreamedResponse|JsonResponse
    {
        $sourcePath = KbPath::normalize((string) $document->source_path);
        $disk = $this->resolveDiskFor($document);
        $fullPath = $this->fullPathFor($document, $sourcePath);

        $storage = Storage::disk($disk);
        if (! $storage->exists($fullPath)) {
            return response()->json(
                ['message' => 'Markdown file not found on disk.'],
                Response::HTTP_NOT_FOUND,
            );
        }

        $filename = basename($sourcePath);

        return $storage->download($fullPath, $filename);
    }

    /**
     * GET /api/admin/kb/documents/{document}/print
     *
     * Renders a print-optimised HTML page. The SPA opens it in a new
     * tab and calls `window.print()` client-side — no server-side PDF
     * rendering (that's G4).
     */
    public function printable(KnowledgeDocument $document): HttpResponse|JsonResponse
    {
        $sourcePath = KbPath::normalize((string) $document->source_path);
        $disk = $this->resolveDiskFor($document);
        $fullPath = $this->fullPathFor($document, $sourcePath);

        $storage = Storage::disk($disk);
        $body = $storage->exists($fullPath) ? $storage->get($fullPath) : null;

        $html = view('print.kb-doc', [
            'document' => $document,
            'body' => is_string($body) ? $body : '',
        ])->render();

        return response($html, Response::HTTP_OK, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * POST /api/admin/kb/documents/{document}/restore
     *
     * Admin-only un-delete for soft-deleted docs. 409 when the row
     * is not trashed (R2 — "restore a live doc" is a client bug,
     * not an idempotent no-op).
     */
    public function restore(KnowledgeDocument $document): KbDocumentResource|JsonResponse
    {
        if (! $document->trashed()) {
            return response()->json(
                ['message' => 'Document is not trashed.'],
                Response::HTTP_CONFLICT,
            );
        }

        $document->restore();
        $document->refresh();
        $document->loadMissing('tags');

        return new KbDocumentResource($document);
    }

    /**
     * DELETE /api/admin/kb/documents/{document}?force=1
     *
     * Defaults to soft-delete. `force=1` routes through
     * {@see DocumentDeleter::forceDelete()} so chunks + graph nodes
     * + physical file all cascade, and a `kb_canonical_audit` row
     * with `event_type='deprecated'` is recorded (R10).
     */
    public function destroy(Request $request, KnowledgeDocument $document): JsonResponse
    {
        $force = $request->boolean('force');

        $result = $this->deleter->delete($document, $force);

        return response()->json([
            'ok' => true,
            'mode' => $result['mode'],
            'document_id' => $result['document_id'],
            'file_deleted' => (bool) ($result['file_deleted'] ?? false),
        ], Response::HTTP_OK);
    }

    /**
     * GET /api/admin/kb/documents/{document}/history
     *
     * Paginated audit rows for a document (20/page, R3). Filters on
     * `(project_key, doc_id, slug)` so the trail survives hard deletes
     * — `kb_canonical_audit` rows are immutable (CLAUDE.md §4).
     */
    public function history(Request $request, KnowledgeDocument $document): AnonymousResourceCollection
    {
        $perPage = max(1, min(50, (int) $request->query('per_page', 20)));
        [$auditQuery] = $this->auditComponentsFor($document, limit: null);

        $page = $auditQuery
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($perPage);

        return KbAuditResource::collection($page);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Build the base audit query + optional count + recent slice for
     * a document. The filter lines up with how `DocumentDeleter` +
     * `CanonicalWriter` stamp audit rows — `(project_key, doc_id)`
     * when canonical, `(project_key, slug)` as a fallback. Raw docs
     * without either return zero.
     *
     * @return array{0: \Illuminate\Database\Eloquent\Builder, 1: int, 2: \Illuminate\Support\Collection<int, KbCanonicalAudit>}
     */
    private function auditComponentsFor(KnowledgeDocument $document, ?int $limit): array
    {
        $query = KbCanonicalAudit::query()
            ->where('project_key', $document->project_key)
            ->where(function ($q) use ($document) {
                if ($document->doc_id !== null) {
                    $q->orWhere('doc_id', $document->doc_id);
                }
                if ($document->slug !== null) {
                    $q->orWhere('slug', $document->slug);
                }
                // Raw docs with neither identifier — close the where()
                // with an impossible clause so the page is empty.
                if ($document->doc_id === null && $document->slug === null) {
                    $q->whereRaw('1 = 0');
                }
            });

        $count = (int) $query->clone()->count();

        $recent = collect();
        if ($limit !== null && $count > 0) {
            $recent = $query->clone()
                ->orderBy('created_at', 'desc')
                ->orderBy('id', 'desc')
                ->limit($limit)
                ->get();
        }

        return [$query, $count, $recent];
    }

    private function resolveDiskFor(KnowledgeDocument $document): string
    {
        $metadata = is_array($document->metadata) ? $document->metadata : [];
        $stamped = $metadata['disk'] ?? null;
        if (is_string($stamped) && $stamped !== '') {
            return $stamped;
        }

        return KbDiskResolver::forProject($document->project_key);
    }

    /**
     * Join the KB path prefix (KB_PATH_PREFIX, R8) with the document's
     * normalised source_path. Matches the prefix resolution done by
     * {@see DocumentDeleter::forceDelete()} so the same file that was
     * written during ingest is the one we read now.
     */
    private function fullPathFor(KnowledgeDocument $document, string $normalizedPath): string
    {
        $metadata = is_array($document->metadata) ? $document->metadata : [];
        $prefix = array_key_exists('prefix', $metadata)
            ? (string) $metadata['prefix']
            : (string) config('kb.sources.path_prefix', '');
        $prefix = trim($prefix, '/');

        return $prefix === '' ? $normalizedPath : $prefix.'/'.$normalizedPath;
    }
}
