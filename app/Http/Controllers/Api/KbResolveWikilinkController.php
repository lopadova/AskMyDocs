<?php

namespace App\Http\Controllers\Api;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Resolve a `[[slug]]` wikilink to a lightweight preview payload used
 * by the chat UI's hover card.
 *
 * Contract: GET /api/kb/resolve-wikilink?project=<key>&slug=<slug>
 *   - 200: { document_id, title, source_path, canonical_type, canonical_status, preview }
 *   - 404: slug not found for this project (either no such doc or RBAC
 *          filtered it out — the two are indistinguishable, by design)
 *
 * The controller uses Eloquent with global scopes, so soft-deleted rows
 * and rows outside the caller's AccessScopeScope are invisible (R2).
 * Never add `withTrashed()` / `withoutGlobalScopes()` here — consumers
 * include authenticated chat users who must not see restricted docs.
 */
class KbResolveWikilinkController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'max:240'],
        ]);

        $doc = KnowledgeDocument::query()
            ->bySlug($validated['project'], $validated['slug'])
            ->first();

        if ($doc === null) {
            return response()->json(['message' => 'Wikilink target not found.'], 404);
        }

        return response()->json([
            'document_id' => $doc->id,
            'title' => $doc->title ?? $validated['slug'],
            'source_path' => $doc->source_path,
            'canonical_type' => $doc->canonical_type,
            'canonical_status' => $doc->canonical_status,
            'is_canonical' => (bool) $doc->is_canonical,
            'preview' => $this->previewFor($doc),
        ]);
    }

    private function previewFor(KnowledgeDocument $doc): string
    {
        $chunk = KnowledgeChunk::query()
            ->where('knowledge_document_id', $doc->id)
            ->orderBy('chunk_order')
            ->value('chunk_text');

        if ($chunk === null || $chunk === '') {
            return '';
        }

        $plain = trim(preg_replace('/\s+/', ' ', $chunk) ?? '');
        if (mb_strlen($plain) <= 200) {
            return $plain;
        }

        return rtrim(mb_substr($plain, 0, 200)).'…';
    }
}
