<?php

declare(strict_types=1);

namespace App\Services\Kb\AutoWiki;

use App\Ai\AiManager;
use App\Ai\AiProviderInterface;
use App\Models\KbCanonicalAudit;
use App\Models\KnowledgeDocument;
use App\Services\Kb\DocumentIngestor;
use App\Support\Canonical\GenerationSource;
use App\Support\KbPath;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * v8.11/P3 — concept-page synthesis (Karpathy concept pages / AutoSci concept
 * pages + entity index).
 *
 * A project-level sweep: it finds recurring concepts across a project's
 * documents (the topical `tags` P1 already derived), and for each concept that
 * doesn't yet have its own page it asks the LLM to synthesize a concise
 * `domain-concept` page grounded in the docs that mention it. The page is
 * written to the KB disk as canonical markdown (frontmatter `generation_source:
 * auto`) and ingested through the ONE execution path
 * ({@see DocumentIngestor::ingestMarkdown()}) — so it gets chunks, embeddings,
 * and graph nodes/edges (from its `related:` frontmatter) like any other doc,
 * but lands in the second-class AUTO tier (the firewall ranks it below human).
 *
 * AutoSci `/prefill` dedup: a concept is skipped if a document already owns its
 * slug (human `{concept}` or auto `auto-{concept}`) — never duplicate a page.
 *
 * Not a per-ingest hook: synthesis is explicit (command / API / MCP, and the
 * P9 scheduler later). Tenant-scoped (R30); auto slugs are `auto-`-namespaced
 * so they never squat on the human canonical slug namespace (same rule as P2).
 *
 * NOT `final` — mockable in the tri-surface tests.
 */
class ConceptSynthesizer
{
    private const MAX_SOURCES_PER_CONCEPT = 8;
    private const MAX_SUMMARY_CHARS = 600;

    public function __construct(
        private readonly AiManager $ai,
        private readonly DocumentIngestor $ingestor,
        private readonly TenantContext $tenants,
    ) {}

    /**
     * Synthesize missing concept pages for a project. Returns a summary of what
     * happened. `$limit` overrides the per-run cap.
     *
     * @return array{ran: bool, reason?: string, candidates?: int, created?: list<string>, skipped?: list<string>}
     */
    public function synthesize(string $tenantId, string $projectKey, ?int $limit = null): array
    {
        if (! (bool) config('kb.autowiki.concepts_enabled', true)) {
            return ['ran' => false, 'reason' => 'disabled'];
        }

        $this->tenants->set($tenantId);

        $candidates = $this->detectCandidates($tenantId, $projectKey);
        $cap = $limit ?? (int) config('kb.autowiki.concepts_max_per_run', 5);

        $created = [];
        $skipped = [];
        foreach (array_slice($candidates, 0, max(0, $cap)) as $candidate) {
            if ($this->conceptExists($tenantId, $projectKey, $candidate)) {
                $skipped[] = $candidate['slug'];

                continue;
            }
            $doc = $this->synthesizeOne($tenantId, $projectKey, $candidate);
            if ($doc !== null) {
                $created[] = (string) $doc->slug;
            } else {
                $skipped[] = $candidate['slug'];
            }
        }

        return [
            'ran' => true,
            'candidates' => count($candidates),
            'created' => $created,
            'skipped' => $skipped,
        ];
    }

    /**
     * Detect recurring-concept candidates: tags that appear across at least
     * `concepts_min_frequency` documents in the project. Each candidate carries
     * the source docs (slug + title + summary) it was derived from, capped.
     *
     * @return list<array{concept: string, slug: string, frequency: int, sources: list<array{slug: ?string, title: string, summary: string}>}>
     */
    private function detectCandidates(string $tenantId, string $projectKey): array
    {
        $minFrequency = max(2, (int) config('kb.autowiki.concepts_min_frequency', 3));

        /** @var array<string, array{count: int, sources: list<array{slug: ?string, title: string, summary: string}>}> $byConcept */
        $byConcept = [];

        KnowledgeDocument::query()
            ->forTenant($tenantId)
            ->where('project_key', $projectKey)
            ->select(['id', 'slug', 'title', 'frontmatter_json'])
            ->chunkById(200, function ($docs) use (&$byConcept): void {
                foreach ($docs as $doc) {
                    $tags = $this->tagsOf($doc);
                    if ($tags === []) {
                        continue;
                    }
                    $summary = $this->summaryOf($doc);
                    foreach ($tags as $tag) {
                        $byConcept[$tag] ??= ['count' => 0, 'sources' => []];
                        $byConcept[$tag]['count']++;
                        if (count($byConcept[$tag]['sources']) < self::MAX_SOURCES_PER_CONCEPT) {
                            $byConcept[$tag]['sources'][] = [
                                'slug' => is_string($doc->slug) ? $doc->slug : null,
                                'title' => (string) ($doc->title ?? $tag),
                                'summary' => $summary,
                            ];
                        }
                    }
                }
            });

        $candidates = [];
        foreach ($byConcept as $concept => $data) {
            if ($data['count'] < $minFrequency) {
                continue;
            }
            $candidates[] = [
                'concept' => $concept,
                'slug' => 'auto-'.Str::slug($concept),
                'frequency' => $data['count'],
                'sources' => $data['sources'],
            ];
        }

        // Strongest concepts first so the per-run cap keeps the most material;
        // slug as a stable tiebreaker so which concepts the cap selects is
        // deterministic across runs.
        usort($candidates, static fn (array $a, array $b): int => $b['frequency'] <=> $a['frequency']
            ?: strcmp($a['slug'], $b['slug']));

        return $candidates;
    }

    /** @param array{concept: string, slug: string, frequency: int, sources: list<array{slug: ?string, title: string, summary: string}>} $candidate */
    private function conceptExists(string $tenantId, string $projectKey, array $candidate): bool
    {
        // Skip if a doc already owns either the human slug ({concept}) or the
        // auto slug (auto-{concept}) — never duplicate a concept page.
        $humanSlug = Str::slug($candidate['concept']);

        return KnowledgeDocument::query()
            ->withTrashed()
            ->forTenant($tenantId)
            ->where('project_key', $projectKey)
            ->whereIn('slug', array_values(array_unique([$candidate['slug'], $humanSlug])))
            ->exists();
    }

    /** @param array{concept: string, slug: string, frequency: int, sources: list<array{slug: ?string, title: string, summary: string}>} $candidate */
    private function synthesizeOne(string $tenantId, string $projectKey, array $candidate): ?KnowledgeDocument
    {
        try {
            $system = View::make('prompts.kb_concept_synthesis', [
                'concept' => $candidate['concept'],
                'sources' => $candidate['sources'],
            ])->render();

            $response = $this->resolveProvider()->chat($system, 'Produce the JSON now.', $this->chatOptions());
            $parsed = $this->decodeJson($response->content);

            $title = $this->scalar($parsed['title'] ?? '') ?: Str::title(str_replace('-', ' ', $candidate['concept']));
            $summary = Str::limit($this->scalar($parsed['summary'] ?? ''), self::MAX_SUMMARY_CHARS, '');
            $body = $this->scalar($parsed['body'] ?? '');
            if ($summary === '' || $body === '') {
                Log::warning('ConceptSynthesizer: empty synthesis, skipped', [
                    'concept' => $candidate['concept'], 'project_key' => $projectKey, 'tenant_id' => $tenantId,
                ]);

                return null;
            }

            $markdown = $this->buildMarkdown($candidate, $title, $summary, $body);
            $relativePath = $this->writeToDisk($projectKey, $candidate['slug'], $markdown);

            $doc = $this->ingestor->ingestMarkdown(
                projectKey: $projectKey,
                sourcePath: $relativePath,
                title: $title,
                markdown: $markdown,
                metadata: ['autowiki_concept' => true, 'source_slug_count' => count($candidate['sources'])],
            );

            $this->audit($doc, $candidate);

            return $doc;
        } catch (Throwable $e) {
            // Best-effort per concept: one bad synthesis must not abort the sweep.
            Log::warning('ConceptSynthesizer: synthesis failed (skipped)', [
                'concept' => $candidate['concept'], 'project_key' => $projectKey,
                'tenant_id' => $tenantId, 'error' => $e->getMessage(), 'exception' => $e,
            ]);

            return null;
        }
    }

    /** @param array{concept: string, slug: string, sources: list<array{slug: ?string, title: string, summary: string}>} $candidate */
    private function buildMarkdown(array $candidate, string $title, string $summary, string $body): string
    {
        // Only relate to sources that actually have a slug (a wikilink target
        // must resolve). Capped + deduped.
        $related = [];
        foreach ($candidate['sources'] as $src) {
            if (is_string($src['slug']) && $src['slug'] !== '' && ! in_array($src['slug'], $related, true)) {
                $related[] = $src['slug'];
            }
        }

        $frontmatter = [
            'id' => $candidate['slug'],
            'slug' => $candidate['slug'],
            'type' => 'domain-concept',
            'status' => 'accepted',
            'generation_source' => GenerationSource::Auto->value,
            'title' => $title,
            'summary' => $summary,
            'tags' => [Str::slug($candidate['concept'])],
            'related' => $related,
        ];

        $yaml = Yaml::dump($frontmatter, 4, 2);

        return "---\n{$yaml}---\n\n{$body}\n";
    }

    private function writeToDisk(string $projectKey, string $slug, string $markdown): string
    {
        $relativePath = 'domain-concepts/'.$slug.'.md';
        $prefix = (string) config('kb.sources.path_prefix', '');
        $fullPath = $prefix === ''
            ? KbPath::normalize($relativePath)
            : KbPath::normalize($prefix.'/'.$relativePath);
        $disk = (string) config('kb.sources.disk', 'kb');

        $written = Storage::disk($disk)->put($fullPath, $markdown);
        if ($written === false) {
            throw new \RuntimeException("ConceptSynthesizer: failed to write [{$disk}]: {$fullPath}");
        }

        return $relativePath;
    }

    /** @param array{concept: string, slug: string, frequency: int} $candidate */
    private function audit(KnowledgeDocument $doc, array $candidate): void
    {
        if (! (bool) config('kb.canonical.audit_enabled', true)) {
            return;
        }
        KbCanonicalAudit::create([
            'tenant_id' => (string) $doc->tenant_id,
            'project_key' => (string) $doc->project_key,
            'doc_id' => $doc->doc_id,
            'slug' => $doc->slug,
            'event_type' => 'promoted',
            'actor' => 'system:autowiki',
            'after_json' => [
                'concept' => $candidate['concept'],
                'frequency' => $candidate['frequency'],
                'generation_source' => GenerationSource::Auto->value,
            ],
            'metadata_json' => ['source' => 'concept_synthesizer'],
        ]);
    }

    /** @return list<string> normalized concept tags for a document */
    private function tagsOf(KnowledgeDocument $document): array
    {
        $fm = is_array($document->frontmatter_json) ? $document->frontmatter_json : [];
        $tags = [];
        $autowiki = $fm['_autowiki'] ?? null;
        if (is_array($autowiki) && is_array($autowiki['tags'] ?? null)) {
            $tags = array_merge($tags, $autowiki['tags']);
        }
        $derived = $fm['_derived'] ?? null;
        if (is_array($derived) && is_array($derived['tags'] ?? null)) {
            $tags = array_merge($tags, $derived['tags']);
        }

        $out = [];
        foreach ($tags as $tag) {
            if (! is_string($tag)) {
                continue;
            }
            $norm = Str::slug($tag);
            if ($norm !== '' && ! in_array($norm, $out, true)) {
                $out[] = $norm;
            }
        }

        return $out;
    }

    private function summaryOf(KnowledgeDocument $document): string
    {
        $fm = is_array($document->frontmatter_json) ? $document->frontmatter_json : [];
        foreach ([['_autowiki', 'summary'], ['_derived', 'summary']] as [$ns, $key]) {
            $block = $fm[$ns] ?? null;
            if (is_array($block) && is_string($block[$key] ?? null) && trim($block[$key]) !== '') {
                return Str::limit(trim($block[$key]), self::MAX_SUMMARY_CHARS, '');
            }
        }

        return '';
    }

    private function resolveProvider(): AiProviderInterface
    {
        $provider = config('kb.autowiki.ai_provider');

        return $this->ai->provider(is_string($provider) && $provider !== '' ? $provider : null);
    }

    /** @return array<string,mixed> */
    private function chatOptions(): array
    {
        $opts = ['temperature' => 0.2];
        $model = config('kb.autowiki.ai_model');
        if (is_string($model) && $model !== '') {
            $opts['model'] = $model;
        }

        return $opts;
    }

    /** @return array<string,mixed> */
    private function decodeJson(string $content): array
    {
        $trimmed = trim($content);
        // Strip a ```json fence if the model wrapped the object.
        if (str_starts_with($trimmed, '```')) {
            $trimmed = (string) preg_replace('/^```[a-zA-Z]*\n?|\n?```$/', '', $trimmed);
        }
        $decoded = json_decode($trimmed, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function scalar(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
