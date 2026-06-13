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
 * v8.11/P1 — Auto-Wiki frontmatter enrichment (Karpathy LLM-Wiki "ingest").
 *
 * After a document is ingested, asks the configured LLM to enrich it for the
 * wiki: derive topical `tags`, a tight `summary`, optional `aliases`, and
 * `cross_references` to its closest existing neighbours. The result is merged
 * into `frontmatter_json` under an `_autowiki` sub-key and the document is
 * marked `generation_source='auto'` (the second-class auto tier — see ADR 0014).
 *
 * Firewall: this NEVER touches a human-curated canonical document
 * (is_canonical && generation_source='human') — the authoritative tier keeps
 * its human gate. It enriches raw / already-auto documents only.
 *
 * The LLM call may target a model distinct from interactive chat via
 * `config('kb.autowiki.ai_provider' / '.ai_model')` (empty => default chat).
 *
 * NOT `final` — `AutoWikiCompilerJob` tests mock this to pin the job's
 * gate/debounce/dispatch contract without the LLM plumbing (same rationale as
 * {@see \App\Services\Kb\Analysis\KbChangeAnalyzer}).
 */
class AutoWikiCompiler
{
    private const MAX_DOC_CHARS = 4000;
    private const MAX_NEIGHBOR_SNIPPET = 400;
    private const MAX_TAGS = 8;
    private const MAX_LIST = 8;
    private const MAX_STRING = 600;

    /** Allowed cross-reference edge types (subset of EdgeType used for wiki links). */
    private const EDGE_TYPES = ['related_to', 'depends_on', 'implements', 'uses', 'decision_for', 'documented_by'];

    public function __construct(
        private readonly AiManager $ai,
        private readonly KbSearchService $search,
    ) {}

    /**
     * Enrich a document into the auto tier. Returns a result describing what
     * happened (applied + provider/model + derived metadata), or applied=false
     * with a reason when skipped (human-curated / empty doc).
     *
     * @return array{applied: bool, reason?: string, provider?: string, model?: string, tags?: list<string>, cross_references?: list<array<string,string>>}
     */
    public function compile(KnowledgeDocument $document): array
    {
        // Firewall: never auto-edit the human-vouched authoritative tier.
        if ((bool) $document->is_canonical
            && (string) ($document->generation_source ?? GenerationSource::Human->value) === GenerationSource::Human->value) {
            return ['applied' => false, 'reason' => 'human_curated'];
        }

        $docText = $this->documentText($document);
        if ($docText === '') {
            return ['applied' => false, 'reason' => 'empty_document'];
        }

        $neighbours = $this->findNeighbours(
            projectKey: (string) $document->project_key,
            excludeDocId: (int) $document->id,
            title: (string) ($document->title ?? ''),
            docText: $docText,
        );

        $systemPrompt = View::make('prompts.kb_autowiki_enrich', [
            'document' => $document,
            'docText' => $docText,
            'neighbours' => $neighbours,
        ])->render();

        $response = $this->resolveProvider()->chat($systemPrompt, 'Produce the JSON now.', $this->chatOptions());
        // Pass neighbours so cross_references are filtered to the real neighbour
        // set (anti-hallucination: the LLM cannot persist an invented link).
        $enrichment = $this->validate($this->decodeLlmJson($response->content), $neighbours);

        // Never write an EMPTY enrichment: a non-JSON / garbage LLM reply would
        // otherwise stamp an empty `_autowiki` block + flip the doc to `auto` +
        // record source_version_hash — corrupting the doc with empty metadata
        // AND permanently idempotency-skipping any retry of the same version.
        // Skip instead so a later re-ingest (or a fixed model) can retry.
        if ($enrichment['tags'] === [] && $enrichment['summary'] === '' && $enrichment['cross_references'] === []) {
            Log::warning('AutoWikiCompiler: empty enrichment, not applied', [
                'document_id' => (int) $document->id,
                'tenant_id' => (string) $document->tenant_id,
                'provider' => $response->provider,
                'model' => $response->model,
            ]);

            return ['applied' => false, 'reason' => 'empty_enrichment', 'provider' => $response->provider, 'model' => $response->model];
        }

        $this->apply($document, $enrichment, $response->provider, $response->model);

        return [
            'applied' => true,
            'provider' => $response->provider,
            'model' => $response->model,
            'tags' => $enrichment['tags'],
            'cross_references' => $enrichment['cross_references'],
        ];
    }

    /**
     * Merge the enrichment into `frontmatter_json._autowiki` and flip the doc
     * to the auto tier. Audited (event 'updated', actor system:autowiki).
     * (Mirroring the tags into per-chunk `metadata.search_tags` so the reranker
     * tag-overlap signal can use them is a follow-up increment; v8.11.1 stores
     * them at the document level.)
     *
     * @param  array{tags: list<string>, summary: string, aliases: list<string>, cross_references: list<array<string,string>>}  $enrichment
     */
    private function apply(KnowledgeDocument $document, array $enrichment, string $provider, string $model): void
    {
        $frontmatter = is_array($document->frontmatter_json) ? $document->frontmatter_json : [];
        $frontmatter['_autowiki'] = [
            'tags' => $enrichment['tags'],
            'summary' => $enrichment['summary'],
            'aliases' => $enrichment['aliases'],
            'cross_references' => $enrichment['cross_references'],
            'provider' => $provider,
            'model' => $model,
            'generated_at' => now()->toIso8601String(),
            'source_version_hash' => $document->version_hash,
        ];

        $document->forceFill([
            'frontmatter_json' => $frontmatter,
            'generation_source' => GenerationSource::Auto->value,
        ])->save();

        if ((bool) config('kb.canonical.audit_enabled', true)) {
            KbCanonicalAudit::create([
                // Explicit tenant_id from the document (R30 defense-in-depth):
                // don't rely on TenantContext being set if the compiler is ever
                // invoked outside the job or the context drifts.
                'tenant_id' => (string) $document->tenant_id,
                'project_key' => (string) $document->project_key,
                'doc_id' => $document->doc_id,
                'slug' => $document->slug,
                'event_type' => 'updated',
                'actor' => 'system:autowiki',
                'after_json' => ['_autowiki' => $frontmatter['_autowiki']],
                'metadata_json' => [
                    'tags' => $enrichment['tags'],
                    'cross_reference_count' => count($enrichment['cross_references']),
                ],
            ]);
        }
    }

    /**
     * The provider for the auto-compile call: the dedicated override
     * (`kb.autowiki.ai_provider`) when set, else the default chat provider.
     */
    private function resolveProvider(): AiProviderInterface
    {
        // config value is already normalized to null (blank => null) in config/kb.php.
        return $this->ai->provider(config('kb.autowiki.ai_provider'));
    }

    /**
     * @return array<string,mixed>
     */
    private function chatOptions(): array
    {
        $model = config('kb.autowiki.ai_model');

        return is_string($model) && $model !== '' ? ['model' => $model] : [];
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
     * Closest existing documents (semantic neighbours), excluding the subject.
     * Reuses the production retrieval path (same as KbChangeAnalyzer).
     *
     * @return list<array{slug: ?string, title: ?string, snippet: string}>
     */
    private function findNeighbours(string $projectKey, int $excludeDocId, string $title, string $docText): array
    {
        $query = trim($title.' '.mb_substr($docText, 0, 500));
        if ($query === '') {
            return [];
        }

        $limit = (int) config('kb.autowiki.neighbor_limit', 5);

        $results = $this->search->search(
            query: $query,
            projectKey: $projectKey,
            limit: $limit + 3,
        );

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

        Log::warning('AutoWikiCompiler: LLM returned non-JSON output', [
            'content_preview' => mb_substr($content, 0, 300),
            'json_error' => json_last_error_msg(),
        ]);

        return [];
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @param  list<array{slug: ?string, title: ?string, snippet: string}>  $neighbours
     * @return array{tags: list<string>, summary: string, aliases: list<string>, cross_references: list<array<string,string>>}
     */
    private function validate(array $decoded, array $neighbours = []): array
    {
        return [
            'tags' => $this->tagList($decoded['tags'] ?? []),
            'summary' => $this->scalar($decoded['summary'] ?? ''),
            'aliases' => $this->stringList($decoded['aliases'] ?? []),
            'cross_references' => $this->crossReferences($decoded['cross_references'] ?? [], $neighbours),
        ];
    }

    private function scalar(mixed $raw): string
    {
        return is_scalar($raw) ? mb_substr(trim((string) $raw), 0, self::MAX_STRING) : '';
    }

    /**
     * @return list<string>
     */
    private function tagList(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $seen = [];
        $out = [];
        foreach ($raw as $entry) {
            if (! is_string($entry)) {
                continue;
            }
            // Normalize: lowercase, strip leading '#', collapse to kebab.
            $tag = strtolower(trim(ltrim($entry, '#')));
            $tag = preg_replace('/[^a-z0-9]+/', '-', $tag) ?? '';
            $tag = trim($tag, '-');
            if ($tag === '' || isset($seen[$tag])) {
                continue;
            }
            $seen[$tag] = true;
            $out[] = $tag;
            if (count($out) >= self::MAX_TAGS) {
                break;
            }
        }

        return $out;
    }

    /**
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
     * Cross-references are filtered to the REAL neighbour set (anti-hallucination,
     * ADR 0014): an entry survives only when its slug matches a neighbour slug
     * OR its title matches a neighbour title (case-insensitive). An invented
     * reference the LLM emits despite the prompt is dropped, so the wiki never
     * persists a dangling/incorrect link. With no neighbours, all are dropped.
     *
     * @param  list<array{slug: ?string, title: ?string, snippet: string}>  $neighbours
     * @return list<array{slug: string, title: string, why: string, edge_type: string}>
     */
    private function crossReferences(mixed $raw, array $neighbours): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $allowedSlugs = [];
        $allowedTitles = [];
        foreach ($neighbours as $n) {
            $ns = is_string($n['slug'] ?? null) ? strtolower(trim($n['slug'])) : '';
            $nt = is_string($n['title'] ?? null) ? strtolower(trim($n['title'])) : '';
            if ($ns !== '') {
                $allowedSlugs[$ns] = true;
            }
            if ($nt !== '') {
                $allowedTitles[$nt] = true;
            }
        }

        $out = [];
        foreach ($raw as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $slug = $this->scalar($entry['slug'] ?? '');
            $title = $this->scalar($entry['title'] ?? '');
            // Drop an entry with no identifying slug AND no title.
            if ($slug === '' && $title === '') {
                continue;
            }
            // Anti-hallucination allowlist: keep ONLY references to a real neighbour.
            $slugOk = $slug !== '' && isset($allowedSlugs[strtolower($slug)]);
            $titleOk = $title !== '' && isset($allowedTitles[strtolower($title)]);
            if (! $slugOk && ! $titleOk) {
                continue;
            }
            $edge = strtolower(trim((string) ($entry['edge_type'] ?? 'related_to')));
            if (! in_array($edge, self::EDGE_TYPES, true)) {
                $edge = 'related_to';
            }
            $out[] = [
                'slug' => $slug,
                'title' => $title,
                'why' => $this->scalar($entry['why'] ?? ''),
                'edge_type' => $edge,
            ];
            if (count($out) >= self::MAX_LIST) {
                break;
            }
        }

        return $out;
    }
}
