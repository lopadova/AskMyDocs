<?php

declare(strict_types=1);

namespace Tests\Feature\Pii;

use App\Models\AdminInsightsSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v4.3/W1 sub-PR 4.5 — A6 — AdminInsightsSnapshotObserver feature test.
 */
final class AdminInsightsSnapshotObserverTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        $app['config']->set('pii-redactor.strategy', 'mask');
    }

    public function test_default_off_keeps_payload_columns_verbatim(): void
    {
        config()->set('kb.pii_redactor.enabled', false);
        config()->set('kb.pii_redactor.redact_insights_snippets', false);

        $row = AdminInsightsSnapshot::query()->create([
            'snapshot_date' => now()->toDateString(),
            'orphan_docs' => [['slug' => 'mario-rossi-cv', 'last_used_at' => '2024-01-01']],
            'coverage_gaps' => [['topic' => 'contact', 'sample_questions' => ['Email mario@example.com?']]],
            'computed_at' => now(),
        ]);

        $persisted = $row->fresh();
        $this->assertSame('Email mario@example.com?', $persisted->coverage_gaps[0]['sample_questions'][0]);
    }

    public function test_both_knobs_on_walks_all_payload_columns_recursively(): void
    {
        config()->set('kb.pii_redactor.enabled', true);
        config()->set('kb.pii_redactor.redact_insights_snippets', true);

        $row = AdminInsightsSnapshot::query()->create([
            'snapshot_date' => now()->toDateString(),
            'orphan_docs' => [['slug' => 'mario.rossi@example.com', 'last_used_at' => '2024-01-01']],
            'coverage_gaps' => [
                [
                    'topic' => 'contact',
                    'sample_questions' => ['Email mario@example.com?', 'Phone giulia@example.org?'],
                ],
            ],
            'stale_docs' => [['slug' => 'paolo@example.it-runbook', 'days' => 200]],
            'computed_at' => now(),
        ]);

        $persisted = $row->fresh();

        // Walk every payload column + assert no email-shaped string remains.
        foreach (['orphan_docs', 'coverage_gaps', 'stale_docs'] as $col) {
            $payload = $persisted->{$col};
            $this->assertIsArray($payload);
            foreach ($this->flattenStrings($payload) as $s) {
                $this->assertDoesNotMatchRegularExpression(
                    '/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i',
                    $s,
                    'Column '.$col.' must not retain raw email: '.$s,
                );
            }
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
}
