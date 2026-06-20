<?php

declare(strict_types=1);

namespace App\Services\Engagement;

use App\Ai\AiManager;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * v8.18/W4 — turns the deterministic {@see GamificationQualityMetricsService}
 * numbers into encouraging, motivating narratives + fun period titles.
 *
 * Mirrors {@see \App\Services\Digest\AiDigestNarrator}: gated by
 * `kb.gamification.enabled` AND `kb.gamification.ai.enabled` (R43 — both states
 * tested), uses a DEDICATED free model so it never competes with the primary
 * chat model, and ALWAYS returns a well-formed result — on any LLM failure (or
 * when AI is off) it degrades to DETERMINISTIC copy built from the metrics, with
 * `model = null`. The narrative NEVER throws (R14).
 *
 * Returns, for every scope, `{narrative: array, titles: list<array>, model: ?string}`
 * so the caller persists a complete row regardless of the AI path taken.
 */
final class GamificationNarratorService
{
    public function __construct(private readonly AiManager $ai)
    {
    }

    public function aiEnabled(): bool
    {
        return (bool) config('kb.gamification.enabled', true)
            && (bool) config('kb.gamification.ai.enabled', true);
    }

    /**
     * Personal coaching card for a contributor: strengths, growth areas, 1–2
     * concrete next steps, an encouraging summary, plus fun AI-awarded titles.
     *
     * @param  array<string, mixed>  $metrics
     * @return array{narrative:array<string,mixed>, titles:list<array<string,mixed>>, model:?string}
     */
    public function narrateUser(int $userId, array $metrics): array
    {
        $deterministic = [
            'narrative' => $this->deterministicUser($metrics),
            'titles' => $this->deterministicTitles($metrics),
            'model' => null,
        ];

        return $this->generate(
            $this->userSystemPrompt(),
            $this->facts(['scope' => 'user', 'user_id' => $userId, 'metrics' => $metrics]),
            $deterministic,
        );
    }

    /**
     * Project knowledge-health narrative + 3 concrete improvement actions.
     *
     * @param  array<string, mixed>  $metrics
     * @return array{narrative:array<string,mixed>, titles:list<array<string,mixed>>, model:?string}
     */
    public function narrateProject(string $projectKey, array $metrics): array
    {
        $deterministic = [
            'narrative' => $this->deterministicProject($projectKey, $metrics),
            'titles' => [],
            'model' => null,
        ];

        return $this->generate(
            $this->projectSystemPrompt(),
            $this->facts(['scope' => 'project', 'project_key' => $projectKey, 'metrics' => $metrics]),
            $deterministic,
        );
    }

    /**
     * Tenant executive narrative: cross-project + cross-dev interpretation, silos,
     * complementarity, plus advice for the org and named individuals.
     *
     * @param  array<string, mixed>  $metrics
     * @return array{narrative:array<string,mixed>, titles:list<array<string,mixed>>, model:?string}
     */
    public function narrateTenant(array $metrics): array
    {
        $deterministic = [
            'narrative' => $this->deterministicTenant($metrics),
            'titles' => [],
            'model' => null,
        ];

        return $this->generate(
            $this->tenantSystemPrompt(),
            $this->facts(['scope' => 'tenant', 'metrics' => $metrics]),
            $deterministic,
        );
    }

    /**
     * Shared LLM call + JSON parse with a deterministic fallback. Never throws.
     *
     * @param  array{narrative:array<string,mixed>, titles:list<array<string,mixed>>, model:?string}  $fallback
     * @return array{narrative:array<string,mixed>, titles:list<array<string,mixed>>, model:?string}
     */
    private function generate(string $system, string $user, array $fallback): array
    {
        if (! $this->aiEnabled()) {
            return $fallback;
        }

        try {
            $model = config('kb.gamification.ai.model');
            $provider = $this->ai->provider(config('kb.gamification.ai.provider'));
            $response = $provider->chat($system, $user, $this->chatOptions());

            $decoded = $this->decodeJson($response->content);
            if ($decoded === null) {
                return $fallback;
            }

            return [
                'narrative' => is_array($decoded['narrative'] ?? null) ? $decoded['narrative'] : $fallback['narrative'],
                'titles' => $this->normaliseTitles($decoded['titles'] ?? $fallback['titles']),
                'model' => is_string($model) && $model !== '' ? $model : 'default',
            ];
        } catch (Throwable $e) {
            Log::warning('GamificationNarratorService: narrative generation failed; using deterministic copy.', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return $fallback;
        }
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    private function facts(array $facts): string
    {
        return "Knowledge-curation facts (JSON):\n".
            json_encode($facts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT).
            "\n\nReturn ONLY the JSON object described in the system prompt.";
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJson(string $raw): ?array
    {
        $text = trim($raw);
        // Strip a ```json … ``` fence if the model wrapped the object.
        if (str_starts_with($text, '```')) {
            $text = (string) preg_replace('/^```[a-zA-Z]*\n?|\n?```$/', '', $text);
        }
        // Narrow to the outermost { … } so leading/trailing prose can't break decode.
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end < $start) {
            return null;
        }
        $json = substr($text, $start, $end - $start + 1);

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  mixed  $titles
     * @return list<array<string, mixed>>
     */
    private function normaliseTitles($titles): array
    {
        if (! is_array($titles)) {
            return [];
        }

        $out = [];
        foreach ($titles as $t) {
            if (! is_array($t) || ! isset($t['label']) || ! is_string($t['label']) || $t['label'] === '') {
                continue;
            }
            $out[] = [
                'key' => is_string($t['key'] ?? null) ? $t['key'] : strtolower(preg_replace('/[^a-z0-9]+/i', '-', $t['label'])),
                'label' => $t['label'],
                'icon' => is_string($t['icon'] ?? null) && $t['icon'] !== '' ? $t['icon'] : '🏅',
                'reason' => is_string($t['reason'] ?? null) ? $t['reason'] : '',
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function chatOptions(): array
    {
        $options = [];
        $model = config('kb.gamification.ai.model');
        if (is_string($model) && $model !== '') {
            $options['model'] = $model;
        }
        $maxTokens = (int) config('kb.gamification.ai.max_tokens', 400);
        if ($maxTokens > 0) {
            $options['max_tokens'] = $maxTokens;
        }

        return $options;
    }

    // -----------------------------------------------------------------
    // System prompts (ask for STRICT JSON so the result is machine-usable)
    // -----------------------------------------------------------------

    private function userSystemPrompt(): string
    {
        return implode(' ', [
            'You are an upbeat knowledge-base coach writing a short personal review for a contributor.',
            'Tone: encouraging, motivating, specific — celebrate strengths, frame growth areas kindly.',
            'Use ONLY the numbers provided; never invent facts.',
            'Return ONLY a JSON object: {"narrative":{"headline":string,"strengths":[string],"growth":[string],',
            '"next_steps":[string],"summary":string},"titles":[{"key":string,"label":string,"icon":string,"reason":string}]}.',
            'Award 1–3 fun, context-aware period titles (e.g. "Il Cartografo", "Evidence Champion", "Lo Steward").',
            'No markdown, no prose outside the JSON.',
        ]);
    }

    private function projectSystemPrompt(): string
    {
        return implode(' ', [
            'You are a knowledge-base health analyst writing a short narrative for one project.',
            'Interpret the curation-quality numbers and give exactly 3 concrete improvement actions.',
            'Use ONLY the numbers provided; never invent facts.',
            'Return ONLY a JSON object: {"narrative":{"headline":string,"summary":string,"actions":[string]}}.',
            'No markdown, no prose outside the JSON.',
        ]);
    }

    private function tenantSystemPrompt(): string
    {
        return implode(' ', [
            'You are a knowledge-operations advisor writing a short executive narrative for an organisation.',
            'Compare projects, read the cross-dev strength matrix, note silos and complementarity,',
            'and give advice for both the org and named individuals (by user_id).',
            'Use ONLY the numbers provided; never invent facts.',
            'Return ONLY a JSON object: {"narrative":{"headline":string,"summary":string,"actions":[string],"advice":[string]}}.',
            'No markdown, no prose outside the JSON.',
        ]);
    }

    // -----------------------------------------------------------------
    // Deterministic fallbacks (used when AI is off OR the call fails)
    // -----------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $m
     * @return array<string, mixed>
     */
    private function deterministicUser(array $m): array
    {
        $authored = (int) ($m['authored_docs'] ?? 0);
        $canon = (float) ($m['canonicalization_rate'] ?? 0.0);
        $front = (float) ($m['frontmatter_completeness_rate'] ?? 0.0);
        $edges = (int) ($m['graph_edges'] ?? 0);

        $strengths = [];
        if ($canon >= 0.6) {
            $strengths[] = 'Strong canonicalization — most of your docs reach accepted status.';
        }
        if ($front >= 0.6) {
            $strengths[] = 'Thorough frontmatter — your docs are well-structured and discoverable.';
        }
        if ($edges >= 5) {
            $strengths[] = 'Great cross-linking — your knowledge connects across the graph.';
        }
        if ($strengths === []) {
            $strengths[] = "You've authored {$authored} doc(s) — every contribution counts.";
        }

        $growth = [];
        if ($front < 0.6) {
            $growth[] = 'Add complete frontmatter (slug, type, evidence) to more of your docs.';
        }
        if ($canon < 0.6) {
            $growth[] = 'Push more drafts through to accepted canonical status.';
        }
        if ($growth === []) {
            $growth[] = 'Keep the momentum — consider mentoring others on curation quality.';
        }

        return [
            'headline' => "Your curation snapshot ({$authored} authored)",
            'strengths' => $strengths,
            'growth' => $growth,
            'next_steps' => array_slice($growth, 0, 2),
            'summary' => "You authored {$authored} doc(s) with a "
                .round($canon * 100).'% canonicalization rate and '
                .round($front * 100).'% frontmatter completeness. Keep it up!',
        ];
    }

    /**
     * @param  array<string, mixed>  $m
     * @return list<array<string, mixed>>
     */
    private function deterministicTitles(array $m): array
    {
        $titles = [];
        if ((int) ($m['graph_edges'] ?? 0) >= 5) {
            $titles[] = ['key' => 'cartographer', 'label' => 'Il Cartografo', 'icon' => '🗺️', 'reason' => 'Connects knowledge across the graph.'];
        }
        if ((float) ($m['evidence_coverage_rate'] ?? 0.0) >= 0.6) {
            $titles[] = ['key' => 'evidence-champion', 'label' => 'Evidence Champion', 'icon' => '🔬', 'reason' => 'Backs docs with evidence tiers.'];
        }
        if ((float) ($m['avg_health_score'] ?? 0.0) >= 0.7 || (float) ($m['avg_health_score'] ?? 0.0) >= 70) {
            $titles[] = ['key' => 'steward', 'label' => 'Lo Steward', 'icon' => '🌳', 'reason' => 'Keeps docs fresh and healthy.'];
        }

        return $titles;
    }

    /**
     * @param  array<string, mixed>  $m
     * @return array<string, mixed>
     */
    private function deterministicProject(string $projectKey, array $m): array
    {
        $health = (float) ($m['health_score'] ?? 0.0);
        $actions = [];
        if ((float) ($m['frontmatter_completeness_rate'] ?? 0.0) < 0.7) {
            $actions[] = 'Backfill frontmatter on canonical docs missing slug/type/evidence.';
        }
        if ((float) ($m['canonicalization_rate'] ?? 0.0) < 0.7) {
            $actions[] = 'Review the draft/review backlog and promote stable docs to accepted.';
        }
        if ((float) ($m['evidence_coverage_rate'] ?? 0.0) < 0.6) {
            $actions[] = 'Attach evidence tiers to claims that currently lack them.';
        }
        while (count($actions) < 3) {
            $actions[] = 'Keep cross-linking related docs to strengthen the knowledge graph.';
        }

        return [
            'headline' => "Knowledge health for {$projectKey}",
            'summary' => "Project {$projectKey} has a composite knowledge-health score of {$health}/100 across "
                .(int) ($m['total_docs'] ?? 0).' docs.',
            'actions' => array_slice($actions, 0, 3),
        ];
    }

    /**
     * @param  array<string, mixed>  $m
     * @return array<string, mixed>
     */
    private function deterministicTenant(array $m): array
    {
        $org = (float) ($m['org_health_score'] ?? 0.0);
        $projects = (int) ($m['project_count'] ?? 0);

        return [
            'headline' => 'Organisation knowledge health',
            'summary' => "Across {$projects} project(s) the organisation knowledge-health score is {$org}/100.",
            'actions' => [
                'Prioritise the lowest-health project for a curation sprint.',
                'Pair strong canonizers with projects that have a low canonicalization rate.',
            ],
            'advice' => [
                'Recognise top stewards publicly to reinforce good curation habits.',
            ],
        ];
    }
}
