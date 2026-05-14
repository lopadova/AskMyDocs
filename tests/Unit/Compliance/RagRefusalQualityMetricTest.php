<?php

declare(strict_types=1);

namespace Tests\Unit\Compliance;

use App\Compliance\RagRefusalQualityMetric;
use App\Models\ChatLog;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RagRefusalQualityMetricTest extends TestCase
{
    use RefreshDatabase;

    public function test_compute_with_precomputed_counts_returns_expected_payload(): void
    {
        $metric = new RagRefusalQualityMetric();
        $result = $metric->compute([
            'dimension' => 'project',
            'cohort' => 'docs',
            'total' => 100,
            'refusals' => 8,
            'baseline' => 0.95,
        ]);

        $this->assertSame('rag_refusal_quality', $result['metric']);
        $this->assertSame('project', $result['dimension']);
        $this->assertSame('docs', $result['cohort']);
        $this->assertSame(100, $result['total']);
        $this->assertSame(8, $result['refusals']);
        $this->assertSame(0.08, $result['refusal_rate']);
        $this->assertSame(0.92, $result['score']);
        $this->assertSame(0.03, $result['delta']);
        $this->assertFalse($result['flagged']);
    }

    public function test_compute_flags_cohort_when_delta_exceeds_threshold(): void
    {
        $metric = new RagRefusalQualityMetric();
        $result = $metric->compute([
            'cohort' => 'low-coverage',
            'total' => 50,
            'refusals' => 20,
        ]);

        $this->assertTrue($result['flagged']);
        $this->assertGreaterThan(0.05, $result['delta']);
    }

    public function test_compute_handles_zero_refusals(): void
    {
        $metric = new RagRefusalQualityMetric();
        $result = $metric->compute([
            'cohort' => 'clean',
            'total' => 1000,
            'refusals' => 0,
        ]);

        $this->assertSame(0.0, $result['refusal_rate']);
        $this->assertSame(1.0, $result['score']);
        $this->assertFalse($result['flagged']);
    }

    public function test_compute_all_groups_by_cohort_dimension(): void
    {
        $user = User::create([
            'name' => 'Compliance Tester',
            'email' => 'rag-'.uniqid().'@example.test',
            'password' => Hash::make('secret-pass'),
        ]);

        TenantContext::instance()->set('default');

        // Seed: 6 turns in project A (1 refusal), 4 turns in project B (3 refusals)
        $this->seedLog($user, 'project-a', false);
        $this->seedLog($user, 'project-a', false);
        $this->seedLog($user, 'project-a', false);
        $this->seedLog($user, 'project-a', false);
        $this->seedLog($user, 'project-a', false);
        $this->seedLog($user, 'project-a', true);

        $this->seedLog($user, 'project-b', true);
        $this->seedLog($user, 'project-b', true);
        $this->seedLog($user, 'project-b', true);
        $this->seedLog($user, 'project-b', false);

        $results = (new RagRefusalQualityMetric(TenantContext::instance()))
            ->computeAll('project', 7);

        $byCohort = [];
        foreach ($results as $row) {
            $byCohort[$row['cohort']] = $row;
        }

        $this->assertArrayHasKey('project-a', $byCohort);
        $this->assertArrayHasKey('project-b', $byCohort);
        $this->assertSame(6, $byCohort['project-a']['total']);
        $this->assertSame(1, $byCohort['project-a']['refusals']);
        $this->assertSame(4, $byCohort['project-b']['total']);
        $this->assertSame(3, $byCohort['project-b']['refusals']);
        $this->assertTrue($byCohort['project-b']['flagged']);
    }

    private function seedLog(User $user, string $project, bool $refused): void
    {
        ChatLog::create([
            'tenant_id' => 'default',
            'session_id' => 'sess-'.uniqid(),
            'user_id' => $user->id,
            'question' => 'Q',
            'answer' => $refused ? '' : 'A',
            'project_key' => $project,
            'ai_provider' => 'openai',
            'ai_model' => 'gpt-4o',
            'chunks_count' => $refused ? 0 : 4,
            'refusal_reason' => $refused ? 'no_relevant_context' : null,
        ]);
    }
}
