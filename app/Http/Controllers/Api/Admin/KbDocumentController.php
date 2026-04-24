<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\PdfEngineDisabledException;
use App\Http\Requests\Admin\Kb\UpdateRawRequest;
use App\Http\Resources\Admin\Kb\KbAuditResource;
use App\Http\Resources\Admin\Kb\KbDocumentResource;
use App\Jobs\IngestDocumentJob;
use App\Models\KbCanonicalAudit;
use App\Models\KbEdge;
use App\Models\KbNode;
use App\Models\KnowledgeDocument;
use App\Services\Admin\Pdf\PdfRenderer;
use App\Services\Kb\Canonical\CanonicalParser;
use App\Services\Kb\DocumentDeleter;
use App\Support\KbDiskResolver;
use App\Support\KbPath;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

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
     * PATCH /api/admin/kb/documents/{document}/raw
     *
     * Writes the SPA-edited markdown to the KB disk, records an
     * `updated` audit row, then queues an `IngestDocumentJob` so the
     * chunks + graph are rebuilt through the single canonical
     * ingestion path (CLAUDE.md §6).
     *
     * Ordering is load-bearing: disk write FIRST, audit SECOND, job
     * LAST. If the disk write fails we return 500 with the same shape
     * as {@see self::raw()} and leave no audit row / no queued job —
     * a failed write must not produce a lying audit trail (R4).
     *
     * Frontmatter, when present, is validated synchronously via
     * {@see CanonicalParser::validate()}. Invalid frontmatter short-circuits
     * to 422 with per-key errors under `errors.frontmatter.<key>` so
     * the editor can surface them field-by-field (R11). Non-canonical
     * markdown (no `---` fence) is accepted as-is — matching the
     * degrade-gracefully contract in DocumentIngestor (R10).
     */
    public function updateRaw(
        UpdateRawRequest $request,
        KnowledgeDocument $document,
        CanonicalParser $parser,
    ): JsonResponse {
        /** @var string $content */
        $content = $request->validated()['content'];

        $sourcePath = KbPath::normalize((string) $document->source_path);

        // Parsed frontmatter survives past the validation block so the
        // audit row can stamp identifier *edits* (e.g. `id:` change in
        // the YAML) with the NEW doc_id / slug — otherwise the audit
        // trail would key on the pre-edit identifiers and the row
        // would vanish from History once the re-ingest lands with the
        // new doc_id (history prefers doc_id, Copilot #5).
        $parsed = null;

        if (str_starts_with($content, '---')) {
            $parsed = $parser->parse($content);
            // Copilot #4 fix: when the content opens with `---` but
            // `parse()` returns null, the frontmatter fence is broken
            // (e.g. missing closing `---`, malformed YAML scalar).
            // Previously we silently skipped validation and wrote the
            // malformed block to disk — the next ingest then crashed
            // on CanonicalParser. Treat "opens with --- but can't
            // parse" as a 422 so the editor can surface the failure
            // to the author before bytes hit the disk.
            if ($parsed === null) {
                return response()->json([
                    'message' => 'Invalid canonical frontmatter.',
                    'errors' => ['frontmatter' => [
                        'Frontmatter block must be a complete --- ... --- section with valid YAML.',
                    ]],
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $validation = $parser->validate($parsed);
            if (! $validation->valid) {
                return response()->json([
                    'message' => 'Invalid canonical frontmatter.',
                    'errors' => ['frontmatter' => $validation->errors],
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        $disk = $this->resolveDiskFor($document);
        $fullPath = $this->fullPathFor($document, $sourcePath);

        // R4: disk write FIRST — anything downstream (audit, job) must
        // only happen when the bytes actually landed on disk.
        $ok = Storage::disk($disk)->put($fullPath, $content);
        if ($ok === false) {
            return response()->json([
                'message' => 'failed to write markdown to disk',
                'path' => $fullPath,
                'disk' => $disk,
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // R10: stamp the audit row with the identifiers that survive a
        // hard delete (project_key, doc_id, slug).
        //
        // Copilot #5 fix: when the author edits `id:` / `slug:` in the
        // YAML, the next ingest will materialise a KnowledgeDocument
        // with the NEW identifiers. Keying the audit on the pre-edit
        // identifiers would leave this `updated` row invisible from
        // the new doc's History tab (history prefers `doc_id` and
        // falls back to `slug`). Prefer the parsed frontmatter's
        // identifiers when present; capture the prior ones inside
        // `before_json` so the trail stays reversible. Raw
        // (non-canonical) edits keep `$document->...` as-is.
        $newVersionHash = hash('sha256', $content);
        $actor = (string) (auth()->user()?->email ?? 'system');

        $nextDocId = $parsed?->docId ?? $document->doc_id;
        $nextSlug = $parsed?->slug ?? $document->slug;

        $audit = KbCanonicalAudit::create([
            'project_key' => $document->project_key,
            'doc_id' => $nextDocId,
            'slug' => $nextSlug,
            'event_type' => 'updated',
            'actor' => $actor,
            'before_json' => [
                'version_hash' => $document->version_hash,
                'metadata' => $document->metadata,
                'doc_id' => $document->doc_id,
                'slug' => $document->slug,
            ],
            'after_json' => [
                'version_hash' => $newVersionHash,
                'size_bytes' => strlen($content),
                'doc_id' => $nextDocId,
                'slug' => $nextSlug,
            ],
            'metadata_json' => [
                'route' => 'api.admin.kb.documents.update_raw',
                'identifier_changed' => ($nextDocId !== $document->doc_id)
                    || ($nextSlug !== $document->slug),
            ],
        ]);

        // Single ingestion execution path (CLAUDE.md §6). The queued
        // job re-reads from the same disk+prefix combination, chunks,
        // embeds and refreshes graph edges.
        IngestDocumentJob::dispatch(
            $document->project_key,
            $sourcePath,
            $disk,
            $document->title,
            is_array($document->metadata) ? $document->metadata : [],
        );

        return response()->json([
            'version_hash' => $newVersionHash,
            'audit_id' => $audit->id,
            'queued' => true,
        ], Response::HTTP_ACCEPTED);
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

        // Copilot #1 fix: mirror raw() / download() error handling —
        // surface a real 404 when the file is missing and a 500 when
        // the read fails, so the SPA can tell "empty document" apart
        // from "broken disk". Previously we silently coerced to an
        // empty body and returned 200, which hid data-loss bugs.
        if (! $storage->exists($fullPath)) {
            return response()->json(
                [
                    'message' => 'Markdown file not found on disk.',
                    'path' => $fullPath,
                    'disk' => $disk,
                ],
                Response::HTTP_NOT_FOUND,
            );
        }

        try {
            $body = $storage->get($fullPath);
        } catch (\Throwable) {
            return response()->json(
                [
                    'message' => 'Failed to read markdown file from disk.',
                    'path' => $fullPath,
                    'disk' => $disk,
                ],
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        if (! is_string($body)) {
            return response()->json(
                [
                    'message' => 'Failed to read markdown file from disk.',
                    'path' => $fullPath,
                    'disk' => $disk,
                ],
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        $html = view('print.kb-doc', [
            'document' => $document,
            'body' => $body,
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

    /**
     * GET /api/admin/kb/documents/{document}/graph
     *
     * PR11 / Phase G4 — tenant-scoped subgraph rooted at this document.
     *
     * Seed node resolution (R10):
     *   1. Canonical doc → `kb_nodes` row with `source_doc_id = $doc->doc_id`.
     *   2. Audit-compatible fallback → `node_uid = $doc->slug` when step 1 misses.
     *   3. Raw doc (no canonical identifiers, or seed node not yet indexed) →
     *      return an empty subgraph with HTTP 200 (not 404). The SPA renders
     *      an "empty" state so the operator knows the doc exists but has no
     *      graph presence yet.
     *
     * Expansion: 1 hop in BOTH directions (from_node_uid ∈ {seed} OR
     * to_node_uid ∈ {seed}), then load every node touched by those edges
     * in a single `whereIn()` lookup. Cap at 50 nodes + 100 edges to
     * protect large clusters; this is generous enough for the current
     * consumers (HR + Engineering) and can be lifted later.
     *
     * Tenant isolation (R10): every query starts with
     * `where('project_key', $doc->project_key)`. The composite FKs on
     * `kb_edges` already guarantee (project_key, node_uid) pairs never
     * cross tenants, so this filter plus the composite FK make cross-
     * tenant leakage structurally impossible.
     */
    public function graph(KnowledgeDocument $document): JsonResponse
    {
        $project = (string) $document->project_key;
        $meta = [
            'project_key' => $project,
            'center_node_uid' => null,
            'generated_at' => now()->toIso8601String(),
        ];

        // Seed resolution — prefer source_doc_id (the stable canonical
        // identifier), fall back to node_uid=slug for audit-compatible
        // lookups (older canonical rows may have been indexed before
        // source_doc_id was populated). When neither is available we
        // return an empty graph — the SPA renders an empty-state card.
        $seed = null;
        if ($document->doc_id !== null) {
            $seed = KbNode::query()
                ->where('project_key', $project)
                ->where('source_doc_id', $document->doc_id)
                ->first();
        }
        if ($seed === null && $document->slug !== null) {
            $seed = KbNode::query()
                ->where('project_key', $project)
                ->where('node_uid', $document->slug)
                ->first();
        }

        if ($seed === null) {
            return response()->json([
                'nodes' => [],
                'edges' => [],
                'meta' => $meta,
            ]);
        }

        $meta['center_node_uid'] = $seed->node_uid;

        $edgeCap = 100;
        $nodeCap = 50;

        // One hop in both directions. We ORDER BY weight desc then id so
        // the cap takes the heaviest edges first — matching the same
        // ordering used by GraphExpander in the retrieval pipeline
        // (CLAUDE.md §3, Retrieval/GraphExpander).
        $edges = KbEdge::query()
            ->where('project_key', $project)
            ->where(function ($q) use ($seed) {
                $q->where('from_node_uid', $seed->node_uid)
                    ->orWhere('to_node_uid', $seed->node_uid);
            })
            ->orderBy('weight', 'desc')
            ->orderBy('id')
            ->limit($edgeCap)
            ->get();

        // Collect every uid touched by the returned edges PLUS the seed
        // itself. A single whereIn() keeps the lookup memory-safe
        // regardless of fan-out (R3); the uniqueness of node_uid per
        // project guarantees one row per uid here.
        $uids = [$seed->node_uid];
        foreach ($edges as $edge) {
            $uids[] = $edge->from_node_uid;
            $uids[] = $edge->to_node_uid;
        }
        $uids = array_values(array_unique($uids));

        $nodes = KbNode::query()
            ->where('project_key', $project)
            ->whereIn('node_uid', $uids)
            ->limit($nodeCap)
            ->get();

        $nodePayload = $nodes->map(function (KbNode $node) use ($seed) {
            $payload = [
                'uid' => $node->node_uid,
                'type' => $node->node_type,
                'label' => $node->label,
                'source_doc_id' => $node->source_doc_id,
                'role' => $node->node_uid === $seed->node_uid ? 'center' : 'neighbor',
            ];
            // `dangling: true` is how CanonicalIndexerJob stamps wikilink
            // targets that point at a slug no canonical doc provides yet.
            // Surfacing it lets the SPA render a dimmed "pending"
            // node so operators see what needs canonicalizing next.
            if (is_array($node->payload_json) && ($node->payload_json['dangling'] ?? false) === true) {
                $payload['dangling'] = true;
            }
            return $payload;
        })->values();

        // Guard against the (extremely unlikely) case where `whereIn` hit
        // the cap and dropped one of an edge's endpoints. Drop any edge
        // whose endpoint isn't in the returned node set so the SPA never
        // tries to draw a line to a ghost.
        $nodeUidSet = array_flip($nodePayload->pluck('uid')->all());
        $edgePayload = $edges->filter(
            fn (KbEdge $edge) => isset($nodeUidSet[$edge->from_node_uid], $nodeUidSet[$edge->to_node_uid]),
        )->map(fn (KbEdge $edge) => [
            'uid' => $edge->edge_uid,
            'from' => $edge->from_node_uid,
            'to' => $edge->to_node_uid,
            'type' => $edge->edge_type,
            'weight' => (float) $edge->weight,
            'provenance' => $edge->provenance,
        ])->values();

        return response()->json([
            'nodes' => $nodePayload,
            'edges' => $edgePayload,
            'meta' => $meta,
        ]);
    }

    /**
     * POST /api/admin/kb/documents/{document}/export-pdf
     *
     * PR11 / Phase G4 — export the current markdown as PDF bytes.
     *
     * Pipeline:
     *   1. Normalise `source_path` via {@see KbPath::normalize()} (R1) so
     *      the disk read targets the exact same key the ingest flow wrote.
     *   2. Read the markdown from the resolved KB disk; missing → 404,
     *      read failure → 500 (R4 — do not silently coerce to empty).
     *   3. Hand the doc + body to the injected {@see PdfRenderer}. The
     *      concrete implementation is resolved by
     *      {@see \App\Services\Admin\Pdf\PdfRendererFactory} from
     *      `config('admin.pdf_engine')` (default: 'disabled').
     *   4. `PdfEngineDisabledException` → 501 JSON so the SPA can surface
     *      the actionable "enable ADMIN_PDF_ENGINE" message.
     *   5. Any other error from the engine → 500 (log for ops triage).
     */
    public function exportPdf(
        Request $request,
        PdfRenderer $renderer,
        KnowledgeDocument $document,
    ): Response|JsonResponse {
        $sourcePath = KbPath::normalize((string) $document->source_path);
        $disk = $this->resolveDiskFor($document);
        $fullPath = $this->fullPathFor($document, $sourcePath);

        $storage = Storage::disk($disk);

        if (! $storage->exists($fullPath)) {
            return response()->json([
                'message' => 'Markdown file not found on disk.',
                'path' => $fullPath,
                'disk' => $disk,
            ], Response::HTTP_NOT_FOUND);
        }

        $body = null;
        try {
            $body = $storage->get($fullPath);
        } catch (Throwable) {
            // Fall through — handled by the is_string() check below.
        }

        if (! is_string($body)) {
            // R4: Storage::get returned null (or threw) → surface as 500
            // with the same shape as raw() / printable() so the SPA error
            // UX is uniform.
            return response()->json([
                'message' => 'Failed to read markdown file from disk.',
                'path' => $fullPath,
                'disk' => $disk,
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        try {
            $pdfBytes = $renderer->render($document, $body);
        } catch (PdfEngineDisabledException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'engine' => (string) config('admin.pdf_engine', 'disabled'),
            ], Response::HTTP_NOT_IMPLEMENTED);
        } catch (Throwable $e) {
            Log::error('PDF export failed', [
                'document_id' => $document->id,
                'engine' => (string) config('admin.pdf_engine', 'disabled'),
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'message' => 'PDF rendering failed.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $filename = basename($sourcePath, '.md').'.pdf';

        return response($pdfBytes, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Content-Length' => (string) strlen($pdfBytes),
        ]);
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
        // Copilot #2 fix: prefer `doc_id` when present; fall back to
        // `slug` only when doc_id is null. The previous `(doc_id OR slug)`
        // union could merge audit rows from unrelated documents when a
        // slug got recycled after a hard delete or a historical audit
        // still carried the previous slug for the same doc_id. `doc_id`
        // is the stable identifier — audits emitted by DocumentDeleter
        // / CanonicalIndexerJob stamp both, so the stricter filter loses
        // nothing real and gains precision.
        $query = KbCanonicalAudit::query()
            ->where('project_key', $document->project_key);

        if ($document->doc_id !== null) {
            $query->where('doc_id', $document->doc_id);
        } elseif ($document->slug !== null) {
            $query->where('slug', $document->slug);
        } else {
            // Raw doc with neither identifier — return an empty page.
            $query->whereRaw('1 = 0');
        }

        // Copilot #5 fix: the COUNT query was always executed, even
        // when the caller only wanted the paginated slice. `history()`
        // now passes `limit = null` and then lets `paginate()` issue
        // its own COUNT, so we skip the redundant clone+count here
        // when no recent slice is requested.
        $count = 0;
        $recent = collect();

        if ($limit !== null) {
            $count = (int) $query->clone()->count();

            if ($count > 0) {
                $recent = $query->clone()
                    ->orderBy('created_at', 'desc')
                    ->orderBy('id', 'desc')
                    ->limit($limit)
                    ->get();
            }
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
