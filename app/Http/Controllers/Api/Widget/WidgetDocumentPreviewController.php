<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Widget;

use App\Http\Middleware\ResolveWidgetKey;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\WidgetKey;
use App\Models\WidgetSession;
use App\Models\WidgetSessionStep;
use App\Services\Widget\WidgetSessionResolver;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;

/**
 * Return a cited document reconstructed from the same indexed chunks used by
 * retrieval. Every denied ownership/citation check deliberately returns the
 * same 404 response so this public surface cannot become an existence oracle.
 */
final class WidgetDocumentPreviewController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly WidgetSessionResolver $sessions,
    ) {}

    public function __invoke(Request $request, string $session, int $documentId): JsonResponse
    {
        $widgetSession = $this->sessions->find($request, $session);
        if ($widgetSession === null || ! $this->wasCited($widgetSession, $documentId)) {
            return $this->notFound();
        }

        $key = $request->attributes->get(ResolveWidgetKey::ATTR_KEY);
        if (! $key instanceof WidgetKey) {
            return $this->notFound();
        }

        $tenant = $this->tenants->current();
        $document = KnowledgeDocument::query()
            ->forTenant($tenant)
            ->where('project_key', $key->project_key)
            ->whereKey($documentId)
            ->first();

        if ($document === null) {
            return $this->notFound();
        }

        $chunks = KnowledgeChunk::query()
            ->forTenant($tenant)
            ->where('project_key', $key->project_key)
            ->where('knowledge_document_id', $document->id)
            ->orderBy('chunk_order')
            ->orderBy('id')
            ->get(['heading_path', 'chunk_text']);

        return response()->json([
            'document_id' => $document->id,
            'title' => $document->title,
            'source_path' => $document->source_path,
            'source_type' => $document->source_type,
            'language' => $document->language,
            'source_updated_at' => $document->source_updated_at?->toIso8601String(),
            'sections' => $this->sections($chunks),
        ])->header('Cache-Control', 'no-store');
    }

    private function wasCited(WidgetSession $session, int $documentId): bool
    {
        $steps = $session->steps()
            ->where('kind', WidgetSessionStep::KIND_BOT_MESSAGE)
            ->select(['args_json'])
            ->get();

        foreach ($steps as $step) {
            foreach ((array) data_get($step->args_json, 'citations', []) as $citation) {
                if (is_array($citation)
                    && filter_var($citation['document_id'] ?? null, FILTER_VALIDATE_INT) === $documentId) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Group adjacent chunks with the same heading while preserving the exact
     * document order. Repeated headings separated by another section remain
     * separate, avoiding any reordering of the source text.
     *
     * @param  Collection<int, KnowledgeChunk>  $chunks
     * @return list<array{heading_path: ?string, content: string}>
     */
    private function sections(Collection $chunks): array
    {
        $sections = [];

        foreach ($chunks as $chunk) {
            $heading = is_string($chunk->heading_path) && $chunk->heading_path !== ''
                ? $chunk->heading_path
                : null;
            $last = count($sections) - 1;

            if ($last >= 0 && $sections[$last]['heading_path'] === $heading) {
                $sections[$last]['content'] .= "\n\n".$chunk->chunk_text;
                continue;
            }

            $sections[] = [
                'heading_path' => $heading,
                'content' => $chunk->chunk_text,
            ];
        }

        return $sections;
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['message' => 'Document not found.'], 404)
            ->header('Cache-Control', 'no-store');
    }
}
