<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * Return the full source text of a CITED document so the chat UI can open it
 * in a modal ("the documents used to ground the answer"), for ANY authenticated
 * reader — not only the admins who can reach the KB admin surface.
 *
 * Contract: GET /api/kb/documents/{document}/preview
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
 */
final class KbDocumentPreviewController extends Controller
{
    public function __invoke(int $document): JsonResponse
    {
        $tenant = app(TenantContext::class)->current();

        $doc = KnowledgeDocument::query()
            ->forTenant($tenant)
            ->find($document);

        if ($doc === null) {
            return response()->json(['message' => 'Document not found.'], 404);
        }

        $content = KnowledgeChunk::query()
            ->forTenant($tenant)
            ->where('knowledge_document_id', $doc->id)
            ->orderBy('chunk_order')
            ->pluck('chunk_text')
            ->implode("\n\n");

        return response()->json([
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
