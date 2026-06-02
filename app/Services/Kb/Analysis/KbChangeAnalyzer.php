<?php

declare(strict_types=1);

namespace App\Services\Kb\Analysis;

use App\Ai\AiManager;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Services\Kb\KbSearchService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;

/**
 * v8.7/W3–W4 — AI deep-analysis of a document change.
 *
 * On ingest/modify, asks the configured LLM to look at the changed
 * document AND its closest existing neighbours and return, as strict JSON:
 *   - `enhancement_suggestions`: how to strengthen THIS doc
 *   - `cross_references`: existing docs this one connects to
 *   - `impacted_docs`: existing docs this change makes obsolete / in need
 *     of revision, each with a suggested action
 *
 * Suggest-only: the analyzer NEVER writes canonical markdown or mutates
 * any doc — it produces advice that lands in `kb_doc_analyses` and is
 * surfaced to reviewers. Mirrors `PromotionSuggestService`'s graceful
 * JSON-decode + shape-validation (drop bad entries, never throw on a
 * malformed LLM reply).
 */
/**
 * NOT `final` — `AnalyzeDocumentChangeJob` tests mock this service to pin
 * the job's gating/debounce/persistence contract without the LLM +
 * embedding plumbing (same Mockery-friendliness rationale as `AiManager`).
 */
class KbChangeAnalyzer
{
    private const MAX_DOC_CHARS = 4000;
    private const MAX_NEIGHBOR_SNIPPET = 400;
    private const MAX_LIST = 8;
    private const MAX_STRING = 600;

    public function __construct(
        private readonly AiManager $ai,
        private readonly KbSearchService $search,
    ) {}

    /**
     * @return array{analysis: array{enhancement_suggestions: list<string>, cross_references: list<array{slug: string, title: string, why: string}>, impacted_docs: list<array{slug: string, title: string, impact: string, suggested_action: string}>}, provider: string, model: string}
     */
    public function analyze(KnowledgeDocument $document, string $trigger): array
    {
        $docText = $this->documentText($document);
        $neighbours = $this->findNeighbours($document, $docText);

        $systemPrompt = View::make('prompts.kb_change_analysis', [
            'document' => $document,
            'docText' => $docText,
            'neighbours' => $neighbours,
            'trigger' => $trigger,
        ])->render();

        $response = $this->ai->chat($systemPrompt, 'Produce the JSON now.');

        return [
            'analysis' => $this->validate($this->decodeLlmJson($response->content)),
            'provider' => $response->provider,
            'model' => $response->model,
        ];
    }

    private function documentText(KnowledgeDocument $document): string
    {
        $text = KnowledgeChunk::query()
            ->forTenant((string) $document->tenant_id)
            ->where('knowledge_document_id', $document->id)
            ->orderBy('chunk_order')
            ->pluck('chunk_text')
            ->implode("\n\n");

        return mb_substr(trim($text), 0, self::MAX_DOC_CHARS);
    }

    /**
     * Closest existing documents (semantic neighbours), excluding the
     * document itself. Reuses the production retrieval path so neighbour
     * quality matches what chat would surface.
     *
     * @return list<array{slug: ?string, title: ?string, snippet: string}>
     */
    private function findNeighbours(KnowledgeDocument $document, string $docText): array
    {
        $query = trim(((string) $document->title).' '.mb_substr($docText, 0, 500));
        if ($query === '') {
            return [];
        }

        $limit = (int) config('kb.change_analysis.neighbor_limit', 5);

        $results = $this->search->search(
            query: $query,
            projectKey: (string) $document->project_key,
            limit: $limit + 3, // over-fetch so excluding self still leaves enough
        );

        $byDoc = [];
        foreach ($results as $chunk) {
            $docId = data_get($chunk, 'document.id');
            if ($docId === null || (int) $docId === (int) $document->id || isset($byDoc[$docId])) {
                continue;
            }
            $byDoc[$docId] = [
                'slug' => data_get($chunk, 'document.slug'),
                'title' => data_get($chunk, 'document.title'),
                'snippet' => mb_substr((string) data_get($chunk, 'chunk_text', ''), 0, self::MAX_NEIGHBOR_SNIPPET),
            ];
            if (count($byDoc) >= $limit) {
                break;
            }
        }

        return array_values($byDoc);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeLlmJson(string $content): array
    {
        $stripped = trim($content);
        if (preg_match('/\A```(?:json)?\s*(.*?)\s*```\z/s', $stripped, $m) === 1) {
            $stripped = trim($m[1]);
        }

        $decoded = json_decode($stripped, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        Log::warning('KbChangeAnalyzer: LLM returned non-JSON output', [
            'content_preview' => mb_substr($content, 0, 300),
            'json_error' => json_last_error_msg(),
        ]);

        return [];
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return array{enhancement_suggestions: list<string>, cross_references: list<array{slug: string, title: string, why: string}>, impacted_docs: list<array{slug: string, title: string, impact: string, suggested_action: string}>}
     */
    private function validate(array $decoded): array
    {
        return [
            'enhancement_suggestions' => $this->stringList($decoded['enhancement_suggestions'] ?? []),
            'cross_references' => $this->objectList($decoded['cross_references'] ?? [], ['slug', 'title', 'why']),
            'impacted_docs' => $this->objectList($decoded['impacted_docs'] ?? [], ['slug', 'title', 'impact', 'suggested_action']),
        ];
    }

    /**
     * @param  mixed  $raw
     * @return list<string>
     */
    private function stringList(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $entry) {
            if (is_string($entry) && trim($entry) !== '') {
                $out[] = mb_substr(trim($entry), 0, self::MAX_STRING);
            }
            if (count($out) >= self::MAX_LIST) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param  mixed  $raw
     * @param  list<string>  $keys
     * @return list<array<string, string>>
     */
    private function objectList(mixed $raw, array $keys): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $clean = [];
            foreach ($keys as $key) {
                $value = $entry[$key] ?? '';
                $clean[$key] = is_scalar($value) ? mb_substr(trim((string) $value), 0, self::MAX_STRING) : '';
            }
            // Drop an entry with no identifying slug AND no title.
            if ($clean['slug'] === '' && ($clean['title'] ?? '') === '') {
                continue;
            }
            $out[] = $clean;
            if (count($out) >= self::MAX_LIST) {
                break;
            }
        }

        return $out;
    }
}
