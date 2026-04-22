<?php

namespace Tests\Feature\Kb\Canonical;

use App\Ai\AiManager;
use App\Ai\AiResponse;
use App\Services\Kb\Canonical\PromotionSuggestService;
use Mockery;
use Tests\TestCase;

class PromotionSuggestServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_returns_empty_for_empty_transcript(): void
    {
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldNotReceive('chat');

        $result = (new PromotionSuggestService($ai))->suggest('');

        $this->assertSame(['candidates' => []], $result);
    }

    public function test_parses_valid_llm_json_and_returns_candidates(): void
    {
        $ai = $this->fakeAi(json_encode([
            'candidates' => [
                [
                    'type' => 'decision',
                    'slug_proposal' => 'dec-cache-v2',
                    'title_proposal' => 'Cache invalidation v2',
                    'reason' => 'Mentioned twice in the transcript',
                    'related' => ['module-cache', 'runbook-purge'],
                ],
            ],
        ]));

        $result = (new PromotionSuggestService($ai))->suggest('We decided to use tag-based invalidation for cache.');

        $this->assertCount(1, $result['candidates']);
        $c = $result['candidates'][0];
        $this->assertSame('decision', $c['type']);
        $this->assertSame('dec-cache-v2', $c['slug_proposal']);
        $this->assertSame('Cache invalidation v2', $c['title_proposal']);
        $this->assertSame(['module-cache', 'runbook-purge'], $c['related']);
    }

    public function test_strips_markdown_code_fences_around_json(): void
    {
        // LLMs often wrap JSON in ```json ... ``` even when asked not to.
        $ai = $this->fakeAi("```json\n" . json_encode(['candidates' => [['type' => 'decision', 'slug_proposal' => 'dec-x', 'title_proposal' => 'X', 'reason' => 'r', 'related' => []]]]) . "\n```");

        $result = (new PromotionSuggestService($ai))->suggest('anything');
        $this->assertCount(1, $result['candidates']);
    }

    public function test_drops_candidate_with_invalid_type(): void
    {
        $ai = $this->fakeAi(json_encode([
            'candidates' => [
                ['type' => 'bogus-type', 'slug_proposal' => 'x', 'title_proposal' => 'X', 'reason' => 'r', 'related' => []],
                ['type' => 'decision', 'slug_proposal' => 'dec-x', 'title_proposal' => 'X', 'reason' => 'r', 'related' => []],
            ],
        ]));

        $result = (new PromotionSuggestService($ai))->suggest('t');

        $this->assertCount(1, $result['candidates']);
        $this->assertSame('decision', $result['candidates'][0]['type']);
    }

    public function test_drops_candidate_with_malformed_slug(): void
    {
        $ai = $this->fakeAi(json_encode([
            'candidates' => [
                ['type' => 'decision', 'slug_proposal' => 'UpperCase Bad Slug', 'title_proposal' => 'X', 'reason' => 'r', 'related' => []],
                ['type' => 'runbook', 'slug_proposal' => 'runbook-good', 'title_proposal' => 'Good', 'reason' => 'r', 'related' => []],
            ],
        ]));

        $result = (new PromotionSuggestService($ai))->suggest('t');

        $this->assertCount(1, $result['candidates']);
        $this->assertSame('runbook-good', $result['candidates'][0]['slug_proposal']);
    }

    public function test_drops_related_entries_that_are_not_valid_slugs(): void
    {
        $ai = $this->fakeAi(json_encode([
            'candidates' => [
                [
                    'type' => 'decision',
                    'slug_proposal' => 'dec-x',
                    'title_proposal' => 'X',
                    'reason' => 'r',
                    'related' => ['good-slug', 'BAD SLUG', 42, 'another-good'],
                ],
            ],
        ]));

        $result = (new PromotionSuggestService($ai))->suggest('t');
        $this->assertSame(['good-slug', 'another-good'], $result['candidates'][0]['related']);
    }

    public function test_truncates_overly_long_title_and_reason(): void
    {
        $ai = $this->fakeAi(json_encode([
            'candidates' => [
                [
                    'type' => 'decision',
                    'slug_proposal' => 'dec-x',
                    'title_proposal' => str_repeat('a', 500),
                    'reason' => str_repeat('b', 1500),
                    'related' => [],
                ],
            ],
        ]));

        $result = (new PromotionSuggestService($ai))->suggest('t');
        $c = $result['candidates'][0];
        $this->assertSame(200, mb_strlen($c['title_proposal']));
        $this->assertSame(500, mb_strlen($c['reason']));
    }

    public function test_caps_total_candidates(): void
    {
        $candidates = [];
        for ($i = 0; $i < 25; $i++) {
            $candidates[] = ['type' => 'decision', 'slug_proposal' => "dec-$i", 'title_proposal' => "T $i", 'reason' => 'r', 'related' => []];
        }
        $ai = $this->fakeAi(json_encode(['candidates' => $candidates]));

        $result = (new PromotionSuggestService($ai))->suggest('t');

        $this->assertLessThanOrEqual(10, count($result['candidates']));
    }

    public function test_returns_empty_on_non_json_llm_output(): void
    {
        $ai = $this->fakeAi('Sorry, I cannot comply with this request.');

        $result = (new PromotionSuggestService($ai))->suggest('t');

        $this->assertSame(['candidates' => []], $result);
    }

    public function test_dedups_related_slugs(): void
    {
        $ai = $this->fakeAi(json_encode([
            'candidates' => [
                ['type' => 'decision', 'slug_proposal' => 'dec-x', 'title_proposal' => 'X', 'reason' => 'r', 'related' => ['a', 'b', 'a', 'b', 'c']],
            ],
        ]));

        $result = (new PromotionSuggestService($ai))->suggest('t');
        $this->assertSame(['a', 'b', 'c'], $result['candidates'][0]['related']);
    }

    private function fakeAi(string $content): AiManager
    {
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chat')->andReturn(new AiResponse(
            content: $content,
            provider: 'openai',
            model: 'gpt-4o-mini',
        ));
        return $ai;
    }
}
