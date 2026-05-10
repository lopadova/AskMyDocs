<?php

declare(strict_types=1);

namespace App\Eval\Metrics;

use App\Ai\AiManager;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Support\TenantContext;
use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Metrics\Metric;
use Padosoft\EvalHarness\Metrics\MetricScore;
use Throwable;

/**
 * Custom AskMyDocs metric — cosine similarity between the LLM's
 * answer text and the FULL TEXT of the chunks the answer cited.
 *
 * Why we need this:
 *   The package's `cosine-embedding` metric scores
 *   answer-vs-expected_output. That catches paraphrase regressions
 *   but does NOT catch "fluent answer that doesn't track the
 *   citations" — a textbook hallucination signature.
 *
 *   This metric scores answer-vs-cited-chunks. If the cosine drops
 *   below the configured threshold, the answer has drifted from its
 *   own grounding source, regardless of whether it happens to
 *   match the expected_output by string equality.
 *
 * Scoring:
 *   - Decode the SUT payload as JSON; extract `answer` + `citations`.
 *   - Resolve the cited source_paths back to KnowledgeChunk text via
 *     the seeded corpus (project_key + source_path lookup).
 *   - Embed both the answer and the concatenated cited-chunk text
 *     through AiManager (which honours the same Http::fake bound
 *     by EvalRegistrar in CI mode).
 *   - Return cosine similarity in [0.0, 1.0]. Empty citations OR
 *     no resolvable chunks → 1.0 (degenerate case: the answer is
 *     ungrounded by design — the refusal-quality / citation
 *     metric handles the actual scoring there).
 *
 * R30: every Eloquent read against KnowledgeDocument / KnowledgeChunk
 * is explicitly tenant-scoped via forTenant(TenantContext::current()).
 *
 * R23: this class implements Padosoft\EvalHarness\Metrics\Metric so
 * MetricResolver's boot-time validation accepts the FQCN.
 */
final class CosineGroundednessMetric implements Metric
{
    public function __construct(private readonly AiManager $ai) {}

    public function name(): string
    {
        return 'cosine-groundedness';
    }

    public function score(DatasetSample $sample, string $actualOutput): MetricScore
    {
        $payload = $this->decodePayload($actualOutput);
        if ($payload === null) {
            return new MetricScore(0.0, ['reason' => 'unparseable_payload']);
        }

        $answer = (string) ($payload['answer'] ?? '');
        $citations = (array) ($payload['citations'] ?? []);
        $projectKey = (string) ($payload['meta']['project_key'] ?? $sample->input['project_key'] ?? '');

        if ($answer === '') {
            return new MetricScore(0.0, ['reason' => 'empty_answer']);
        }

        $sourcePaths = [];
        foreach ($citations as $citation) {
            $path = is_array($citation) ? ($citation['source_path'] ?? null) : null;
            if (is_string($path) && $path !== '') {
                $sourcePaths[] = $path;
            }
        }
        $sourcePaths = array_values(array_unique($sourcePaths));

        if ($sourcePaths === []) {
            // Nothing to ground against. The CitationGroundednessMetric
            // handles whether the no-citation outcome was correct;
            // here we degenerate to 1.0 so we don't double-count the
            // refusal path in macro_f1.
            return new MetricScore(1.0, ['reason' => 'no_citations']);
        }

        $chunkText = $this->loadChunkTextForCitations($projectKey, $sourcePaths);
        if ($chunkText === '') {
            return new MetricScore(1.0, [
                'reason' => 'citations_unresolved',
                'cited_paths' => $sourcePaths,
            ]);
        }

        try {
            $embeddings = $this->ai->generateEmbeddings([$answer, $chunkText]);
        } catch (Throwable $e) {
            // Surface as a metric exception so the harness CAPTURES
            // the failure on (sample, metric) without aborting the
            // whole run (R14 — failures must be visible, not silent).
            return new MetricScore(0.0, [
                'reason' => 'embedding_failure',
                'error' => substr($e->getMessage(), 0, 200),
            ]);
        }

        $vectors = $embeddings->embeddings ?? [];
        if (count($vectors) < 2) {
            return new MetricScore(0.0, ['reason' => 'embedding_count_mismatch']);
        }

        $sim = $this->cosine($vectors[0], $vectors[1]);
        // Normalise to [0, 1]. Cosine on unit vectors lives in
        // [-1, 1]; we clip negatives to 0 because a "negatively
        // similar" answer is, for grounding purposes, just as
        // ungrounded as a perpendicular one.
        $score = max(0.0, min(1.0, $sim));

        return new MetricScore($score, [
            'cosine' => $sim,
            'cited_paths' => $sourcePaths,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodePayload(string $raw): ?array
    {
        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Load + concatenate the chunk_text of every chunk belonging to
     * the cited documents. Tenant-scoped explicitly (R30). Empty
     * result → empty string (handled by caller as "citations
     * unresolved").
     *
     * @param  list<string>  $sourcePaths
     */
    private function loadChunkTextForCitations(string $projectKey, array $sourcePaths): string
    {
        if ($projectKey === '' || $sourcePaths === []) {
            return '';
        }

        $tenantId = app(TenantContext::class)->current();

        $documentIds = KnowledgeDocument::query()
            ->forTenant($tenantId)
            ->where('project_key', $projectKey)
            ->whereIn('source_path', $sourcePaths)
            ->pluck('id')
            ->all();

        if ($documentIds === []) {
            return '';
        }

        $text = KnowledgeChunk::query()
            ->forTenant($tenantId)
            ->whereIn('knowledge_document_id', $documentIds)
            ->orderBy('knowledge_document_id')
            ->orderBy('chunk_order')
            ->pluck('chunk_text')
            ->implode("\n\n");

        return trim($text);
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    private function cosine(array $a, array $b): float
    {
        $n = min(count($a), count($b));
        if ($n === 0) {
            return 0.0;
        }
        $dot = 0.0;
        $aSq = 0.0;
        $bSq = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $av = (float) $a[$i];
            $bv = (float) $b[$i];
            $dot += $av * $bv;
            $aSq += $av * $av;
            $bSq += $bv * $bv;
        }
        if ($aSq < 1e-12 || $bSq < 1e-12) {
            return 0.0;
        }

        return $dot / (sqrt($aSq) * sqrt($bSq));
    }
}
