<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Services\Kb\Agent\DocumentLineReader;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Return the full source text of a CITED document so the chat UI can open it
 * in a modal ("the documents used to ground the answer"), for ANY authenticated
 * reader — not only the admins who can reach the KB admin surface.
 *
 * Contract: GET /api/kb/documents/{documentId}/preview
 *   - 200: { document_id, title, source_path, slug, project_key, source_type,
 *            canonical_type, canonical_status, is_canonical, content }
 *   - 404: document not found OR outside the caller's tenant / AccessScope —
 *          the two are indistinguishable, by design (no existence oracle).
 *
 * Isolation (R30): the document is resolved with `forTenant(current())` AND the
 * model's global AccessScopeScope (project_isolation) + SoftDeletes, exactly
 * like {@see KbResolveWikilinkController}. A reader can only open a document
 * they are allowed to read — a citation from company A can never surface
 * company B's bytes. Never add `withTrashed()` / `withoutGlobalScopes()` here.
 *
 * The `content` is reconstructed from the document's chunks in order: those
 * chunks ARE the text retrieval grounded on, so this is the most faithful view
 * of "what the answer used", and it needs no KB disk access (works for any
 * deployment, local or S3). A document with no chunks yields an empty string
 * (the FE renders an explicit empty state) — a 200, not a fake 404, because the
 * document legitimately exists.
 *
 * R44 — DELIBERATE single-surface exception: this is a chat-UI read affordance
 * over the EXISTING document-read capability (already exposed on the admin
 * `KbDocumentController` HTTP surface and the MCP retrieval tools), not a new
 * product capability. It therefore ships HTTP-only — no Artisan command, no MCP
 * tool — on purpose.
 */
final class KbDocumentPreviewController extends Controller
{
    public function __invoke(Request $request, int $documentId): JsonResponse
    {
        $tenant = app(TenantContext::class)->current();

        $doc = KnowledgeDocument::query()
            ->forTenant($tenant)
            ->find($documentId);

        if ($doc === null) {
            return response()->json(['message' => 'Document not found.'], 404);
        }

        // v8.44/W4 (ADR 0035 §D6.2) — opt-in line-range read: `from_line` /
        // `max_lines` slice `content` and add the `lines` block. Without them
        // the payload is exactly what it has always been (R27). A ranged read
        // goes through the reader alone: the full-content query below runs
        // only when no range is asked, so the text is never loaded twice.
        $range = null;
        if ($request->query('from_line') !== null || $request->query('max_lines') !== null) {
            $validated = $request->validate([
                'from_line' => ['nullable', 'integer', 'min:1'],
                'max_lines' => ['nullable', 'integer', 'min:1'],
            ]);
            $range = app(DocumentLineReader::class)->read(
                $doc,
                (int) ($validated['from_line'] ?? 1),
                isset($validated['max_lines']) ? (int) $validated['max_lines'] : null,
            );
        }

        $content = $range !== null ? $range['content'] : KnowledgeChunk::query()
            ->forTenant($tenant)
            ->where('knowledge_document_id', $doc->id)
            ->orderBy('chunk_order')
            ->pluck('chunk_text')
            ->implode("\n\n");

        return response()->json(array_filter([
            'lines' => $range === null ? null : array_diff_key($range, ['content' => true]),
        ], static fn ($v) => $v !== null) + [
            'document_id' => $doc->id,
            'title' => $doc->title,
            'source_path' => $doc->source_path,
            'slug' => $doc->slug,
            'project_key' => $doc->project_key,
            'source_type' => $doc->source_type,
            'canonical_type' => $doc->canonical_type,
            'canonical_status' => $doc->canonical_status,
            'is_canonical' => (bool) $doc->is_canonical,
            'content' => $content,
        ]);
    }
}
