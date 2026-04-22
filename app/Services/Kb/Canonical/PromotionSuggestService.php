<?php

declare(strict_types=1);

namespace App\Services\Kb\Canonical;

use App\Ai\AiManager;
use App\Support\Canonical\CanonicalType;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;

/**
 * Extracts **candidate** canonical artifacts from a raw session transcript
 * (chat log, incident post-mortem, code review) via the configured LLM.
 *
 * Human-in-the-loop: this service NEVER writes canonical markdown. It only
 * produces a shortlist of proposed artifacts that a human (via the
 * promotion API / Claude skill) can then review, refine, and commit.
 *
 * The LLM is asked to respond with strict JSON; the service parses,
 * validates the shape, filters invalid candidates silently (better to
 * return fewer good ones than propagate hallucinated garbage), and
 * returns the cleaned list.
 */
class PromotionSuggestService
{
    private const SLUG_RE = '/^[a-z0-9][a-z0-9\-]*$/';
    private const MAX_SLUG_LEN = 80;
    private const MAX_TITLE_LEN = 200;
    private const MAX_REASON_LEN = 500;
    private const MAX_CANDIDATES = 10;
    private const MAX_RELATED = 8;

    public function __construct(private readonly AiManager $ai)
    {
    }

    /**
     * @param  array<string, mixed>  $context  optional hints (e.g. existing_slugs)
     * @return array{candidates: list<array{type: string, slug_proposal: string, title_proposal: string, reason: string, related: list<string>}>}
     */
    public function suggest(string $transcript, ?string $projectKey = null, array $context = []): array
    {
        $trimmed = trim($transcript);
        if ($trimmed === '') {
            return ['candidates' => []];
        }

        $systemPrompt = View::make('prompts.promotion_suggest', [
            'projectKey' => $projectKey,
            'transcript' => $trimmed,
            'context' => $context,
        ])->render();

        $response = $this->ai->chat($systemPrompt, 'Produce the JSON now.');
        $decoded = $this->decodeLlmJson($response->content);

        $candidates = $this->validateCandidates($decoded['candidates'] ?? []);

        return ['candidates' => $candidates];
    }

    // -----------------------------------------------------------------
    // JSON decoding (graceful — LLMs occasionally wrap in code fences)
    // -----------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function decodeLlmJson(string $content): array
    {
        $stripped = $this->stripCodeFences($content);
        $decoded = json_decode($stripped, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        Log::warning('PromotionSuggestService: LLM returned non-JSON output', [
            'content_preview' => mb_substr($content, 0, 300),
            'json_error' => json_last_error_msg(),
        ]);
        return [];
    }

    private function stripCodeFences(string $content): string
    {
        $trimmed = trim($content);
        // ```json ... ``` or ``` ... ```
        if (preg_match('/\A```(?:json)?\s*(.*?)\s*```\z/s', $trimmed, $m) === 1) {
            return trim($m[1]);
        }
        return $trimmed;
    }

    // -----------------------------------------------------------------
    // candidate validation (drop anything the LLM got wrong — don't fail)
    // -----------------------------------------------------------------

    /**
     * @param  mixed  $raw
     * @return list<array{type: string, slug_proposal: string, title_proposal: string, reason: string, related: list<string>}>
     */
    private function validateCandidates(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $candidate) {
            $clean = $this->cleanCandidate($candidate);
            if ($clean === null) {
                continue;
            }
            $out[] = $clean;
            if (count($out) >= self::MAX_CANDIDATES) {
                break;
            }
        }
        return $out;
    }

    /**
     * @return array{type: string, slug_proposal: string, title_proposal: string, reason: string, related: list<string>}|null
     */
    private function cleanCandidate(mixed $candidate): ?array
    {
        if (! is_array($candidate)) {
            return null;
        }

        $type = $this->stringOrNull($candidate, 'type');
        if ($type === null || CanonicalType::tryFrom($type) === null) {
            return null;
        }

        $slug = $this->normalizeSlug($candidate['slug_proposal'] ?? null);
        if ($slug === null) {
            return null;
        }

        $title = $this->stringOrNull($candidate, 'title_proposal');
        if ($title === null) {
            return null;
        }

        $reason = $this->stringOrNull($candidate, 'reason') ?? '';
        $related = $this->normalizeRelatedList($candidate['related'] ?? []);

        return [
            'type' => $type,
            'slug_proposal' => $slug,
            'title_proposal' => mb_substr($title, 0, self::MAX_TITLE_LEN),
            'reason' => mb_substr($reason, 0, self::MAX_REASON_LEN),
            'related' => $related,
        ];
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function stringOrNull(array $candidate, string $key): ?string
    {
        $v = $candidate[$key] ?? null;
        if (! is_string($v)) {
            return null;
        }
        $trimmed = trim($v);
        return $trimmed === '' ? null : $trimmed;
    }

    private function normalizeSlug(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }
        $trimmed = trim($raw);
        if (mb_strlen($trimmed) > self::MAX_SLUG_LEN) {
            return null;
        }
        if (preg_match(self::SLUG_RE, $trimmed) !== 1) {
            return null;
        }
        return $trimmed;
    }

    /**
     * @param  mixed  $raw
     * @return list<string>
     */
    private function normalizeRelatedList(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        $seen = [];
        foreach ($raw as $entry) {
            $slug = $this->normalizeSlug($entry);
            if ($slug === null || isset($seen[$slug])) {
                continue;
            }
            $seen[$slug] = true;
            $out[] = $slug;
            if (count($out) >= self::MAX_RELATED) {
                break;
            }
        }
        return $out;
    }
}
