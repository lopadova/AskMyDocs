<?php

declare(strict_types=1);

namespace App\Services\Kb\AutoWiki;

use App\Ai\AiManager;
use App\Ai\AiProviderInterface;
use App\Models\KbCanonicalAudit;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Services\Kb\KbSearchService;
use App\Support\Canonical\GenerationSource;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;

/**
 * v8.11/P7 — cross-model review / novelty gate (AutoSci cross-model review +
 * novelty; folds in the contradiction detection deferred from P5).
 *
 * An INDEPENDENT review-LLM validates an auto-tier page before it's trusted:
 *   - grounded      : is the content supported by the doc itself (not invented)?
 *   - cross_refs_valid: do its cross-references point at real, on-topic pages?
 *   - novelty       : novel | overlap | duplicate vs the nearest existing pages;
 *   - contradictions: pairs of pages whose claims conflict (the P5 deferral).
 * → a verdict `approved | flagged`, persisted to
 *   `frontmatter_json._autowiki.review` + audited (actor `system:autowiki-review`).
 *
 * Cross-model by design: it uses the dedicated review model override
 * (`kb.autowiki.review_ai_provider`/`_model`) — point it at a DIFFERENT model
 * than the compiler for true cross-model diversity (empty => default chat).
 *
 * Firewall: only reviews AUTO-tier docs — a human-curated page is never
 * machine-graded. Explicit trigger (command/API/MCP, and the P9 scheduler),
 * never per-ingest, so review LLM cost is opt-in. Tenant-scoped (R30).
 *
 * NOT `final` — mockable in the tri-surface tests.
 */
class AutoWikiReviewer
{
    private const MAX_DOC_CHARS = 4000;
    private const MAX_NEIGHBOR_SNIPPET = 400;
    private const MAX_LIST = 10;
    private const MAX_STRING = 400;
    private const NOVELTY = ['novel', 'overlap', 'duplicate'];

    public function __construct(
        private readonly AiManager $ai,
        private readonly KbSearchService $search,
    ) {}

    /**
     * Review an auto-tier document. Returns the verdict, or reviewed=false with a
     * reason when skipped (disabled / not-auto / empty).
     *
     * @return array{reviewed: bool, reason?: string, verdict?: string, grounded?: bool, cross_refs_valid?: bool, novelty?: string, contradictions?: list<array{slug: string, why: string}>, issues?: list<string>, provider?: string, model?: string}
     */
    public function review(KnowledgeDocument $document): array
    {
        if (! (bool) config('kb.autowiki.review_enabled', true)) {
            return ['reviewed' => false, 'reason' => 'disabled'];
        }

        // Firewall: only the auto tier is machine-reviewed.
        if ((string) ($document->generation_source ?? GenerationSource::Human->value) !== GenerationSource::Auto->value) {
            return ['reviewed' => false, 'reason' => 'not_auto'];
        }

        $docText = $this->documentText($document);
        if ($docText === '') {
            return ['reviewed' => false, 'reason' => 'empty_document'];
        }

        $neighbours = $this->findNeighbours((string) $document->project_key, (int) $document->id, (string) ($document->title ?? ''), $docText);

        $system = View::make('prompts.kb_autowiki_review', [
            'document' => $document,
            'docText' => $docText,
            'neighbours' => $neighbours,
            'crossReferences' => $this->existingCrossReferences($document),
        ])->render();

        $response = $this->resolveProvider()->chat($system, 'Produce the JSON verdict now.', $this->chatOptions());
        $verdict = $this->validate($this->decodeJson($response->content), $neighbours);

        $this->apply($document, $verdict, $response->provider, $response->model);

        return array_merge(['reviewed' => true, 'provider' => $response->provider, 'model' => $response->model], $verdict);
    }

    /**
     * @param  array{verdict: string, grounded: bool, cross_refs_valid: bool, novelty: string, contradictions: list<array{slug: string, why: string}>, issues: list<string>}  $verdict
     */
    private function apply(KnowledgeDocument $document, array $verdict, string $provider, string $model): void
    {
        $frontmatter = is_array($document->frontmatter_json) ? $document->frontmatter_json : [];
        $autowiki = is_array($frontmatter['_autowiki'] ?? null) ? $frontmatter['_autowiki'] : [];
        $autowiki['review'] = array_merge($verdict, [
            'provider' => $provider,
            'model' => $model,
            'reviewed_at' => now()->toIso8601String(),
            'source_version_hash' => $document->version_hash,
        ]);
        $frontmatter['_autowiki'] = $autowiki;

        $document->forceFill(['frontmatter_json' => $frontmatter])->save();

        if ((bool) config('kb.canonical.audit_enabled', true)) {
            KbCanonicalAudit::create([
                'tenant_id' => (string) $document->tenant_id,
                'project_key' => (string) $document->project_key,
                'doc_id' => $document->doc_id,
                'slug' => $document->slug,
                'event_type' => 'updated',
                'actor' => 'system:autowiki-review',
                'after_json' => ['review' => $autowiki['review']],
                'metadata_json' => [
                    'verdict' => $verdict['verdict'],
                    'novelty' => $verdict['novelty'],
                    'contradiction_count' => count($verdict['contradictions']),
                ],
            ]);
        }
    }

    /**
     * @param  array<string,mixed>  $decoded
     * @param  list<array{slug: ?string, title: ?string, snippet: string}>  $neighbours
     * @return array{verdict: string, grounded: bool, cross_refs_valid: bool, novelty: string, contradictions: list<array{slug: string, why: string}>, issues: list<string>}
     */
    private function validate(array $decoded, array $neighbours): array
    {
        $novelty = is_string($decoded['novelty'] ?? null) ? strtolower(trim($decoded['novelty'])) : 'novel';
        if (! in_array($novelty, self::NOVELTY, true)) {
            $novelty = 'novel';
        }

        $neighbourSlugs = [];
        foreach ($neighbours as $n) {
            if (is_string($n['slug'] ?? null) && $n['slug'] !== '') {
                $neighbourSlugs[strtolower($n['slug'])] = true;
            }
        }

        $verdict = is_string($decoded['verdict'] ?? null) ? strtolower(trim($decoded['verdict'])) : '';
        $verdict = $verdict === 'approved' ? 'approved' : 'flagged';

        return [
            'verdict' => $verdict,
            'grounded' => (bool) ($decoded['grounded'] ?? false),
            'cross_refs_valid' => (bool) ($decoded['cross_refs_valid'] ?? false),
            'novelty' => $novelty,
            // Anti-hallucination: only keep contradictions that name a real neighbour slug.
            'contradictions' => $this->contradictions($decoded['contradictions'] ?? [], $neighbourSlugs),
            'issues' => $this->stringList($decoded['issues'] ?? []),
        ];
    }

    /**
     * @param  array<string,bool>  $neighbourSlugs
     * @return list<array{slug: string, why: string}>
     */
    private function contradictions(mixed $raw, array $neighbourSlugs): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $slug = is_string($entry['slug'] ?? null) ? trim($entry['slug']) : '';
            if ($slug === '' || ! isset($neighbourSlugs[strtolower($slug)])) {
                continue; // drop contradictions that don't name a real neighbour
            }
            $out[] = ['slug' => $slug, 'why' => mb_substr(is_string($entry['why'] ?? null) ? trim($entry['why']) : '', 0, self::MAX_STRING)];
            if (count($out) >= self::MAX_LIST) {
                break;
            }
        }

        return $out;
    }

    /** @return list<string> */
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

    /** @return list<string> the doc's existing _autowiki cross-reference slugs */
    private function existingCrossReferences(KnowledgeDocument $document): array
    {
        $fm = is_array($document->frontmatter_json) ? $document->frontmatter_json : [];
        $refs = $fm['_autowiki']['cross_references'] ?? [];
        if (! is_array($refs)) {
            return [];
        }
        $out = [];
        foreach ($refs as $ref) {
            $slug = is_array($ref) ? ($ref['slug'] ?? null) : null;
            if (is_string($slug) && $slug !== '') {
                $out[] = $slug;
            }
        }

        return $out;
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
     * @return list<array{slug: ?string, title: ?string, snippet: string}>
     */
    private function findNeighbours(string $projectKey, int $excludeDocId, string $title, string $docText): array
    {
        $query = trim($title.' '.mb_substr($docText, 0, 500));
        if ($query === '') {
            return [];
        }
        $limit = (int) config('kb.autowiki.neighbor_limit', 5);
        $results = $this->search->search(query: $query, projectKey: $projectKey, limit: $limit + 3);

        $byDoc = [];
        foreach ($results as $chunk) {
            $docId = data_get($chunk, 'document.id');
            if ($docId === null || (int) $docId === $excludeDocId || isset($byDoc[$docId])) {
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

    private function resolveProvider(): AiProviderInterface
    {
        $provider = config('kb.autowiki.review_ai_provider');

        return $this->ai->provider(is_string($provider) && $provider !== '' ? $provider : null);
    }

    /** @return array<string,mixed> */
    private function chatOptions(): array
    {
        $opts = ['temperature' => 0.1];
        $model = config('kb.autowiki.review_ai_model');
        if (is_string($model) && $model !== '') {
            $opts['model'] = $model;
        }

        return $opts;
    }

    /** @return array<string,mixed> */
    private function decodeJson(string $content): array
    {
        $stripped = trim($content);
        if (preg_match('/\A```(?:json)?\s*(.*?)\s*```\z/s', $stripped, $m) === 1) {
            $stripped = trim($m[1]);
        }
        $decoded = json_decode($stripped, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        Log::warning('AutoWikiReviewer: LLM returned non-JSON output', ['preview' => mb_substr($content, 0, 200)]);

        return [];
    }
}
