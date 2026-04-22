<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Services\Kb\Canonical\PromotionSuggestService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Extract candidate canonical artifacts (decision, runbook, rejected-approach, ...) from a raw transcript. Produces a shortlist for human review — writes nothing.')]
#[IsReadOnly]
class KbPromotionSuggestTool extends Tool
{
    private const MAX_TRANSCRIPT_CHARS = 50_000;
    private const MAX_EXISTING_SLUGS = 100;
    private const SLUG_RE = '/^[a-z0-9][a-z0-9\-]*$/';

    public function schema(JsonSchema $schema): array
    {
        return [
            'transcript' => $schema->string()
                ->description('Raw text from a chat log / incident post-mortem / code review session. Max 50 000 chars.')
                ->required(),
            'project_key' => $schema->string()
                ->description('Optional project context; used to tailor slug suggestions and filter existing_slugs.')
                ->nullable(),
            'existing_slugs' => $schema->array()
                ->description('Optional list of slugs already canonicalized in the project — the LLM will prefer them when proposing "related" links. Capped at 100 entries; non-slug-shaped strings are dropped.')
                ->nullable(),
        ];
    }

    public function handle(Request $request, PromotionSuggestService $svc): Response
    {
        $transcript = (string) $request->get('transcript');
        if ($transcript === '') {
            return Response::json(['error' => 'transcript is required', 'candidates' => []]);
        }
        $transcript = mb_substr($transcript, 0, self::MAX_TRANSCRIPT_CHARS);

        $projectKey = $request->get('project_key');
        // Bound + dedupe + slug-normalize so a client can't blow up the LLM
        // prompt (size / cost / latency) with a gigantic or malformed list.
        $existingSlugs = $this->normalizeSlugList($request->get('existing_slugs'));

        $result = $svc->suggest(
            transcript: $transcript,
            projectKey: is_string($projectKey) && $projectKey !== '' ? $projectKey : null,
            context: ['existing_slugs' => $existingSlugs],
        );

        return Response::json([
            'candidates' => $result['candidates'],
            'count' => count($result['candidates']),
        ]);
    }

    /**
     * Bound + dedupe + slug-shape-validate. Matches the same slug regex
     * enforced everywhere else in the canonical pipeline (CanonicalParser,
     * WikilinkExtractor, PromotionSuggestService). Entries that don't
     * match are dropped silently; the list is capped at MAX_EXISTING_SLUGS
     * so a malicious or buggy client can't inflate the LLM prompt.
     *
     * @param  mixed  $raw
     * @return list<string>
     */
    private function normalizeSlugList(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        $seen = [];
        foreach ($raw as $entry) {
            if (! is_string($entry)) {
                continue;
            }
            $trimmed = trim($entry);
            if ($trimmed === '' || isset($seen[$trimmed])) {
                continue;
            }
            if (preg_match(self::SLUG_RE, $trimmed) !== 1) {
                continue;
            }
            $seen[$trimmed] = true;
            $out[] = $trimmed;
            if (count($out) >= self::MAX_EXISTING_SLUGS) {
                break;
            }
        }
        return $out;
    }
}
