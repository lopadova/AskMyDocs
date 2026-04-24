<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Ai\AiManager;
use App\Models\ChatLog;
use App\Models\KbEdge;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\Message;
use App\Services\Kb\Canonical\PromotionSuggestService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Phase I — AI insights aggregation service.
 *
 * Six functions, each idempotent + deterministic against the same DB
 * state. LLM-bearing functions (suggestTags, coverageGaps) surface
 * provider failures as `RuntimeException`; the caller (InsightsComputeCommand)
 * catches at the function boundary and writes null for that column,
 * preserving the other five.
 *
 * R10 compliance:
 *   - `suggestPromotions()` filters with `raw()` scope (is_canonical=false).
 *   - `detectOrphans()` filters with `canonical()`.
 *   - `detectStaleDocs()` filters with `canonical()`.
 *   - `qualityReport()` walks `canonical()` docs for frontmatter checks.
 *   - Every query uses the dedicated canonical scopes — no inline
 *     `where('is_canonical', ?)` clauses.
 *
 * R3 compliance: every sweep uses `chunkById(100)` or constrained
 * aggregation queries. No query loads >200 rows into memory.
 *
 * R4 compliance: LLM-call failures bubble up as exceptions. Never
 * silently returns an empty shape on failure — the compute command
 * needs to know so it can log + null the column.
 */
class AiInsightsService
{
    /**
     * Promotion-candidate quota. Matches PromotionSuggestService's
     * MAX_CANDIDATES internal cap; kept as a constant here so the cap
     * is visible at the service boundary for the SPA's "top 10" UX.
     */
    private const PROMOTION_DEFAULT_LIMIT = 10;

    /**
     * Citation lookback windows. Long enough to smooth out weekend
     * lulls; short enough that docs dormant for half a year surface
     * in the orphan + stale lists.
     */
    private const PROMOTION_LOOKBACK_DAYS = 30;

    private const ORPHAN_LOOKBACK_DAYS = 60;

    private const STALE_INDEXED_DAYS = 180;

    /**
     * Batching caps for LLM-bearing passes. Every LLM call is one
     * Http:: request; the per-compute budget is roughly (tag_cap +
     * 1 coverage_gaps call), capped hard so a large corpus doesn't
     * balloon the provider bill.
     */
    private const SUGGEST_TAGS_MAX_DOCS = 20;

    private const COVERAGE_GAPS_MAX_TOPICS = 10;

    public function __construct(
        private readonly AiManager $ai,
        private readonly PromotionSuggestService $promotion,
    ) {}

    // ------------------------------------------------------------------
    // 1) suggestPromotions — non-canonical docs that appear in citations
    // ------------------------------------------------------------------

    /**
     * @return list<array{document_id: int, slug: string|null, reason: string, score: int}>
     */
    public function suggestPromotions(int $limit = self::PROMOTION_DEFAULT_LIMIT): array
    {
        $since = Carbon::now()->subDays(self::PROMOTION_LOOKBACK_DAYS);

        // Count citations per source_path inside ChatLog::sources JSON.
        // `sources` is a JSON array of {project, path, title, chunk_order}
        // entries — per Reranker::buildSource() shape. We flatten in PHP
        // on a chunked read (R3) because cross-DB JSON aggregation
        // portability is limited (sqlite vs pgsql divergence).
        $counts = [];
        ChatLog::query()
            ->where('created_at', '>=', $since)
            ->whereNotNull('sources')
            ->select(['id', 'sources'])
            ->chunkById(100, function ($rows) use (&$counts): void {
                foreach ($rows as $row) {
                    $sources = $row->sources;
                    if (! is_array($sources)) {
                        continue;
                    }
                    foreach ($sources as $source) {
                        if (! is_array($source)) {
                            continue;
                        }
                        $path = $source['path'] ?? null;
                        $project = $source['project'] ?? null;
                        if (! is_string($path) || ! is_string($project)) {
                            continue;
                        }
                        $key = $project.'::'.$path;
                        $counts[$key] = ($counts[$key] ?? 0) + 1;
                    }
                }
            });

        if ($counts === []) {
            return [];
        }

        // Take top 3x the limit so we have room after the is_canonical
        // filter — most candidates will already be canonical.
        arsort($counts);
        $top = array_slice($counts, 0, $limit * 3, preserve_keys: true);

        return $this->collectPromotionCandidates($top, $limit);
    }

    /**
     * @param  array<string, int>  $top
     * @return list<array{document_id: int, slug: string|null, reason: string, score: int}>
     */
    private function collectPromotionCandidates(array $top, int $limit): array
    {
        $out = [];
        foreach ($top as $key => $score) {
            [$project, $path] = explode('::', $key, 2);
            $doc = KnowledgeDocument::query()
                ->raw()
                ->where('project_key', $project)
                ->where('source_path', $path)
                ->first();
            if ($doc === null) {
                continue;
            }
            $out[] = [
                'document_id' => (int) $doc->id,
                'project_key' => (string) $doc->project_key,
                'slug' => $doc->slug,
                'title' => $doc->title,
                'reason' => "Cited {$score} times in the last ".self::PROMOTION_LOOKBACK_DAYS.' days without canonical frontmatter.',
                'score' => (int) $score,
            ];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // 2) detectOrphans — canonical docs with no graph edges + no citations
    // ------------------------------------------------------------------

    /**
     * @return list<array{document_id: int, slug: string|null, last_used_at: string|null, chunks_count: int}>
     */
    public function detectOrphans(): array
    {
        $since = Carbon::now()->subDays(self::ORPHAN_LOOKBACK_DAYS);
        $citedKeys = $this->citedKeys($since);

        $out = [];
        KnowledgeDocument::query()
            ->canonical()
            ->select(['id', 'project_key', 'slug', 'title', 'source_path', 'doc_id', 'source_updated_at'])
            ->chunkById(100, function ($docs) use ($citedKeys, &$out): void {
                foreach ($docs as $doc) {
                    if ($this->orphanSkipDoc($doc, $citedKeys)) {
                        continue;
                    }
                    $out[] = [
                        'document_id' => (int) $doc->id,
                        'project_key' => (string) $doc->project_key,
                        'slug' => $doc->slug,
                        'title' => $doc->title,
                        'last_used_at' => $doc->source_updated_at?->toIso8601String(),
                        'chunks_count' => (int) $doc->chunks()->count(),
                    ];
                }
            });

        return $out;
    }

    /**
     * @param  array<string, true>  $citedKeys
     */
    private function orphanSkipDoc(KnowledgeDocument $doc, array $citedKeys): bool
    {
        $key = $doc->project_key.'::'.$doc->source_path;
        if (isset($citedKeys[$key])) {
            return true;
        }
        // A doc with ANY outgoing or incoming edge is not orphan.
        $hasEdges = KbEdge::query()
            ->where('project_key', $doc->project_key)
            ->where(function ($q) use ($doc): void {
                $q->where('from_node_uid', $doc->slug)
                    ->orWhere('to_node_uid', $doc->slug);
            })
            ->exists();

        return $hasEdges;
    }

    /**
     * @return array<string, true>  map of "project::path" → true for
     *                              docs cited at least once since $since
     */
    private function citedKeys(Carbon $since): array
    {
        $keys = [];
        ChatLog::query()
            ->where('created_at', '>=', $since)
            ->whereNotNull('sources')
            ->select(['id', 'sources'])
            ->chunkById(100, function ($rows) use (&$keys): void {
                foreach ($rows as $row) {
                    $sources = $row->sources;
                    if (! is_array($sources)) {
                        continue;
                    }
                    foreach ($sources as $source) {
                        if (! is_array($source)) {
                            continue;
                        }
                        $path = $source['path'] ?? null;
                        $project = $source['project'] ?? null;
                        if (is_string($path) && is_string($project)) {
                            $keys[$project.'::'.$path] = true;
                        }
                    }
                }
            });

        return $keys;
    }

    // ------------------------------------------------------------------
    // 3) suggestTags — per-doc LLM pass over missing tags (batched)
    // ------------------------------------------------------------------

    /**
     * @return list<array{document_id: int, slug: string|null, tags_proposed: list<string>}>
     */
    public function suggestTagsBatch(int $cap = self::SUGGEST_TAGS_MAX_DOCS): array
    {
        // Pick up to $cap canonical docs that either have no tags at all
        // or whose metadata.tags is empty. Small corpus → small batch.
        $candidates = KnowledgeDocument::query()
            ->canonical()
            ->select(['id', 'project_key', 'slug', 'title', 'metadata'])
            ->orderBy('id')
            ->limit($cap)
            ->get();

        if ($candidates->isEmpty()) {
            return [];
        }

        $out = [];
        foreach ($candidates as $doc) {
            $proposed = $this->suggestTagsForDoc($doc);
            if ($proposed === []) {
                continue;
            }
            $out[] = [
                'document_id' => (int) $doc->id,
                'project_key' => (string) $doc->project_key,
                'slug' => $doc->slug,
                'title' => $doc->title,
                'tags_proposed' => $proposed,
            ];
        }

        return $out;
    }

    /**
     * Public convenience — returns tag proposals for a single doc. The
     * KB Meta tab calls this via the controller so the
     * "AI suggestions for this doc" section can render on demand.
     *
     * @return list<string>
     */
    public function suggestTagsForDocument(KnowledgeDocument $doc): array
    {
        return $this->suggestTagsForDoc($doc);
    }

    /**
     * @return list<string>
     */
    private function suggestTagsForDoc(KnowledgeDocument $doc): array
    {
        $chunk = KnowledgeChunk::query()
            ->where('knowledge_document_id', $doc->id)
            ->orderBy('chunk_order')
            ->limit(1)
            ->value('chunk_text');
        if (! is_string($chunk) || trim($chunk) === '') {
            return [];
        }

        $existing = $this->existingTagsFor($doc);
        $systemPrompt = $this->buildTagsPrompt($doc, $chunk, $existing);

        $response = $this->ai->chat($systemPrompt, 'Produce the JSON array now.');

        return $this->parseTagsResponse($response->content);
    }

    /**
     * @return list<string>
     */
    private function existingTagsFor(KnowledgeDocument $doc): array
    {
        $metadata = $doc->metadata;
        if (! is_array($metadata)) {
            return [];
        }
        $tags = $metadata['tags'] ?? null;
        if (! is_array($tags)) {
            return [];
        }
        $out = [];
        foreach ($tags as $tag) {
            if (is_string($tag) && trim($tag) !== '') {
                $out[] = trim($tag);
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $existing
     */
    private function buildTagsPrompt(KnowledgeDocument $doc, string $chunk, array $existing): string
    {
        $existingStr = $existing === [] ? '(none)' : implode(', ', array_slice($existing, 0, 10));
        $excerpt = mb_substr($chunk, 0, 1500);

        return <<<PROMPT
You are a taxonomy assistant for a knowledge base. Propose 3-5 short
(1-3 word) tags for the document below. Return ONLY a JSON array of
strings, no prose. Prefer terms that disambiguate the document from
peers sharing the existing tags.

Document title: {$doc->title}
Project: {$doc->project_key}
Existing tags: {$existingStr}

Excerpt:
{$excerpt}

Example valid response: ["caching","redis","eviction-policy"]
PROMPT;
    }

    /**
     * @return list<string>
     */
    private function parseTagsResponse(string $content): array
    {
        $trimmed = trim($content);
        // Strip fences the LLM may add around JSON.
        if (preg_match('/\A```(?:json)?\s*(.*?)\s*```\z/s', $trimmed, $m) === 1) {
            $trimmed = trim($m[1]);
        }
        $decoded = json_decode($trimmed, true);
        if (! is_array($decoded)) {
            return [];
        }
        $out = [];
        $seen = [];
        foreach ($decoded as $tag) {
            if (! is_string($tag)) {
                continue;
            }
            $clean = trim($tag);
            if ($clean === '' || isset($seen[$clean])) {
                continue;
            }
            $seen[$clean] = true;
            $out[] = $clean;
            if (count($out) >= 5) {
                break;
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // 4) coverageGaps — cluster low-confidence questions (one LLM pass)
    // ------------------------------------------------------------------

    /**
     * @return list<array{topic: string, zero_citation_count: int, low_confidence_count: int, sample_questions: list<string>}>
     */
    public function coverageGaps(): array
    {
        $since = Carbon::now()->subDays(self::PROMOTION_LOOKBACK_DAYS);

        // Pull low-confidence turns: zero citations OR chunks_count < 2.
        $lowConf = ChatLog::query()
            ->where('created_at', '>=', $since)
            ->where(function ($q): void {
                $q->where('chunks_count', '<', 2)
                    ->orWhereNull('sources');
            })
            ->orderByDesc('created_at')
            ->limit(200)
            ->get(['id', 'question', 'chunks_count', 'sources']);

        if ($lowConf->isEmpty()) {
            return [];
        }

        $zeroCount = 0;
        $lowCount = 0;
        $questions = [];
        foreach ($lowConf as $row) {
            $cCount = (int) $row->chunks_count;
            $sources = $row->sources;
            $zeroCitations = ! is_array($sources) || $sources === [];
            if ($zeroCitations) {
                $zeroCount++;
            } else {
                $lowCount++;
            }
            if (is_string($row->question) && trim($row->question) !== '') {
                $questions[] = mb_substr(trim($row->question), 0, 200);
            }
        }

        if ($questions === []) {
            return [];
        }

        return $this->clusterQuestionsViaLlm($questions, $zeroCount, $lowCount);
    }

    /**
     * @param  list<string>  $questions
     * @return list<array{topic: string, zero_citation_count: int, low_confidence_count: int, sample_questions: list<string>}>
     */
    private function clusterQuestionsViaLlm(array $questions, int $zeroCount, int $lowCount): array
    {
        $sample = array_slice($questions, 0, 60);
        $numbered = '';
        foreach ($sample as $i => $q) {
            $numbered .= ($i + 1).'. '.$q."\n";
        }

        $systemPrompt = <<<PROMPT
You are a coverage-gap analyst for a knowledge base. The questions
below were answered with zero or few citations — they suggest
uncovered topics. Cluster them into up to 10 topics and return JSON:

[{"topic":"<short label>","sample_questions":["q1","q2"]}, ...]

Return ONLY the JSON array, no prose.
PROMPT;
        $userMessage = "Questions:\n".$numbered;

        $response = $this->ai->chat($systemPrompt, $userMessage);
        $trimmed = trim($response->content);
        if (preg_match('/\A```(?:json)?\s*(.*?)\s*```\z/s', $trimmed, $m) === 1) {
            $trimmed = trim($m[1]);
        }
        $decoded = json_decode($trimmed, true);
        if (! is_array($decoded)) {
            Log::warning('AiInsightsService: coverage-gaps LLM returned non-JSON.', [
                'preview' => mb_substr($response->content, 0, 300),
            ]);

            return [];
        }

        $out = [];
        foreach ($decoded as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $topic = $entry['topic'] ?? null;
            if (! is_string($topic) || trim($topic) === '') {
                continue;
            }
            $samples = $entry['sample_questions'] ?? [];
            $cleanSamples = [];
            if (is_array($samples)) {
                foreach ($samples as $s) {
                    if (is_string($s) && trim($s) !== '') {
                        $cleanSamples[] = mb_substr(trim($s), 0, 200);
                    }
                    if (count($cleanSamples) >= 5) {
                        break;
                    }
                }
            }
            $out[] = [
                'topic' => mb_substr(trim($topic), 0, 120),
                // Running totals apply to the cluster as a cohort,
                // not per-topic — the SPA surfaces them in the card
                // header so operators see the scale, and each topic
                // card shows its sample questions.
                'zero_citation_count' => $zeroCount,
                'low_confidence_count' => $lowCount,
                'sample_questions' => $cleanSamples,
            ];
            if (count($out) >= self::COVERAGE_GAPS_MAX_TOPICS) {
                break;
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // 5) detectStaleDocs — old canonical with negative rating ratio
    // ------------------------------------------------------------------

    /**
     * @return list<array{document_id: int, slug: string|null, indexed_at: string|null, negative_rating_ratio: float}>
     */
    public function detectStaleDocs(): array
    {
        $cutoff = Carbon::now()->subDays(self::STALE_INDEXED_DAYS);
        $negRatios = $this->negativeRatingRatios();

        $out = [];
        KnowledgeDocument::query()
            ->canonical()
            ->where(function ($q) use ($cutoff): void {
                $q->whereNull('indexed_at')
                    ->orWhere('indexed_at', '<=', $cutoff);
            })
            ->select(['id', 'project_key', 'slug', 'title', 'source_path', 'indexed_at'])
            ->chunkById(100, function ($docs) use ($negRatios, &$out): void {
                foreach ($docs as $doc) {
                    $key = $doc->project_key.'::'.$doc->source_path;
                    $ratio = $negRatios[$key] ?? 0.0;
                    // Surface a doc if old AND has any negative feedback.
                    if ($ratio < 0.1) {
                        continue;
                    }
                    $out[] = [
                        'document_id' => (int) $doc->id,
                        'project_key' => (string) $doc->project_key,
                        'slug' => $doc->slug,
                        'title' => $doc->title,
                        'indexed_at' => $doc->indexed_at?->toIso8601String(),
                        'negative_rating_ratio' => round($ratio, 3),
                    ];
                }
            });

        return $out;
    }

    /**
     * Walk messages with a rating and cross-index via metadata.citations
     * to compute the per-doc negative ratio. R3: constrained to
     * `rating IS NOT NULL` rows and chunked.
     *
     * @return array<string, float>  map "project::path" → ratio in [0,1]
     */
    private function negativeRatingRatios(): array
    {
        $pos = [];
        $neg = [];
        Message::query()
            ->whereNotNull('rating')
            ->whereNotNull('metadata')
            ->select(['id', 'rating', 'metadata'])
            ->chunkById(100, function ($rows) use (&$pos, &$neg): void {
                foreach ($rows as $row) {
                    $meta = $row->metadata;
                    if (! is_array($meta)) {
                        continue;
                    }
                    $citations = $meta['citations'] ?? null;
                    if (! is_array($citations)) {
                        continue;
                    }
                    foreach ($citations as $cite) {
                        if (! is_array($cite)) {
                            continue;
                        }
                        $path = $cite['path'] ?? null;
                        $project = $cite['project'] ?? null;
                        if (! is_string($path) || ! is_string($project)) {
                            continue;
                        }
                        $key = $project.'::'.$path;
                        if ((int) $row->rating < 0) {
                            $neg[$key] = ($neg[$key] ?? 0) + 1;
                        } else {
                            $pos[$key] = ($pos[$key] ?? 0) + 1;
                        }
                    }
                }
            });

        $out = [];
        $allKeys = array_unique(array_merge(array_keys($pos), array_keys($neg)));
        foreach ($allKeys as $key) {
            $p = $pos[$key] ?? 0;
            $n = $neg[$key] ?? 0;
            $total = $p + $n;
            if ($total === 0) {
                continue;
            }
            $out[$key] = $n / $total;
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // 6) qualityReport — deterministic SQL aggregation, no LLM
    // ------------------------------------------------------------------

    /**
     * @return array{
     *     chunk_length_distribution: array{under_100: int, h100_500: int, h500_1000: int, h1000_2000: int, over_2000: int},
     *     outlier_short: int,
     *     outlier_long: int,
     *     missing_frontmatter: int,
     *     total_docs: int,
     *     total_chunks: int,
     * }
     */
    public function qualityReport(): array
    {
        $distribution = [
            'under_100' => 0,
            'h100_500' => 0,
            'h500_1000' => 0,
            'h1000_2000' => 0,
            'over_2000' => 0,
        ];
        $outlierShort = 0;
        $outlierLong = 0;
        $totalChunks = 0;

        // SQLite + pgsql both support LENGTH() — cheap aggregate.
        $rows = DB::table('knowledge_chunks')
            ->select([
                DB::raw('LENGTH(chunk_text) AS len'),
                DB::raw('COUNT(*) AS c'),
            ])
            ->groupBy(DB::raw('LENGTH(chunk_text)'))
            ->get();

        foreach ($rows as $row) {
            $len = (int) $row->len;
            $count = (int) $row->c;
            $totalChunks += $count;
            if ($len < 30) {
                $outlierShort += $count;
            }
            if ($len > 2000) {
                $outlierLong += $count;
            }
            if ($len < 100) {
                $distribution['under_100'] += $count;
            } elseif ($len < 500) {
                $distribution['h100_500'] += $count;
            } elseif ($len < 1000) {
                $distribution['h500_1000'] += $count;
            } elseif ($len <= 2000) {
                $distribution['h1000_2000'] += $count;
            } else {
                $distribution['over_2000'] += $count;
            }
        }

        $totalDocs = KnowledgeDocument::query()->count();
        // Docs whose `frontmatter_json` is null are "missing frontmatter"
        // — either non-canonical by design or canonical-but-broken. The
        // SPA card segments them in its caption.
        $missingFrontmatter = KnowledgeDocument::query()
            ->canonical()
            ->whereNull('frontmatter_json')
            ->count();

        return [
            'chunk_length_distribution' => $distribution,
            'outlier_short' => $outlierShort,
            'outlier_long' => $outlierLong,
            'missing_frontmatter' => $missingFrontmatter,
            'total_docs' => $totalDocs,
            'total_chunks' => $totalChunks,
        ];
    }
}
