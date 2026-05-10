<?php

declare(strict_types=1);

namespace Tests\Feature\Pii;

use App\Models\ChatLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * v4.3/W1 sub-PR 4.5 — A4 — ChatLogObserver feature test.
 *
 * Covers `chat_logs.answer` (LLM output may echo PII) AND
 * `chat_logs.sources` JSON (citation snippets) on `creating` events.
 */
final class ChatLogAnswerRedactionTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        $app['config']->set('pii-redactor.strategy', 'mask');
    }

    public function test_default_off_keeps_answer_and_sources_verbatim(): void
    {
        config()->set('kb.pii_redactor.enabled', false);
        config()->set('kb.pii_redactor.redact_answers', false);

        $log = $this->makeLog(
            answer: 'The contact is mario@example.com per HR policy.',
            sources: [['snippet' => 'Reach giulia@example.org for benefits.']],
        );

        $this->assertStringContainsString('mario@example.com', (string) $log->fresh()->answer);
        $sources = $log->fresh()->sources;
        $this->assertSame('Reach giulia@example.org for benefits.', $sources[0]['snippet']);
    }

    public function test_master_switch_off_short_circuits_answers_knob(): void
    {
        config()->set('kb.pii_redactor.enabled', false);
        config()->set('kb.pii_redactor.redact_answers', true);

        $log = $this->makeLog(
            answer: 'mario@example.com handles ops',
            sources: [['snippet' => 'iban IT60X0542811101000000123456 used']],
        );

        $this->assertStringContainsString('mario@example.com', (string) $log->fresh()->answer);
    }

    public function test_both_knobs_on_redact_answer_and_walks_sources_json(): void
    {
        config()->set('kb.pii_redactor.enabled', true);
        config()->set('kb.pii_redactor.redact_answers', true);

        $log = $this->makeLog(
            answer: 'The contact is mario@example.com per HR policy.',
            sources: [
                ['snippet' => 'Reach giulia@example.org for benefits.'],
                ['snippet' => 'See policy.md', 'meta' => ['email' => 'paolo@example.it']],
            ],
        );
        $persisted = $log->fresh();

        // R26 — full email pattern must not survive in answer.
        $this->assertDoesNotMatchRegularExpression(
            '/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i',
            (string) $persisted->answer,
        );

        // Recursive walk — every nested snippet + meta value redacted.
        $sources = $persisted->sources;
        $this->assertIsArray($sources);
        $flatStrings = $this->flattenStrings($sources);
        foreach ($flatStrings as $s) {
            $this->assertDoesNotMatchRegularExpression(
                '/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i',
                $s,
                'Nested source string must not contain raw emails: '.$s,
            );
        }
    }

    /**
     * @param  array<int|string, mixed>  $values
     * @return list<string>
     */
    private function flattenStrings(array $values): array
    {
        $out = [];
        foreach ($values as $v) {
            if (is_string($v)) {
                $out[] = $v;
                continue;
            }
            if (is_array($v)) {
                $out = array_merge($out, $this->flattenStrings($v));
            }
        }

        return $out;
    }

    /**
     * @param  array<int|string, mixed>  $sources
     */
    private function makeLog(string $answer, array $sources): ChatLog
    {
        return ChatLog::create([
            'session_id' => (string) Str::uuid(),
            'user_id' => null,
            'question' => 'q',
            'answer' => $answer,
            'project_key' => 'hr-portal',
            'ai_provider' => 'openai',
            'ai_model' => 'gpt-4o',
            'chunks_count' => 2,
            'sources' => $sources,
            'prompt_tokens' => 10,
            'completion_tokens' => 20,
            'total_tokens' => 30,
            'latency_ms' => 100,
            'created_at' => now(),
        ]);
    }
}
