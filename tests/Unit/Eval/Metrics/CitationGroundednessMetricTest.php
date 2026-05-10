<?php

declare(strict_types=1);

namespace Tests\Unit\Eval\Metrics;

use App\Eval\Metrics\CitationGroundednessMetric;
use App\Models\KnowledgeDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\EvalHarness\Datasets\DatasetSample;
use Tests\TestCase;

/**
 * R23: CitationGroundednessMetric implements Padosoft\EvalHarness\Metrics\Metric.
 * R16: every test below exercises the behaviour its name promises — the
 *      assertions inspect the SCORE the metric actually returned, not just
 *      that it returned a MetricScore instance.
 */
final class CitationGroundednessMetricTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_perfect_score_when_every_expected_citation_is_present(): void
    {
        $sample = $this->sample(
            id: 'happy',
            projectKey: 'hr-portal',
            expected: ['policies/remote-work-policy.md'],
        );
        $payload = $this->payload(
            answer: 'Up to 3 days per week.',
            citations: [['source_path' => 'policies/remote-work-policy.md']],
            projectKey: 'hr-portal',
        );

        $score = (new CitationGroundednessMetric)->score($sample, $payload);

        $this->assertSame(1.0, $score->score);
        $this->assertSame(1, $score->details['hits']);
        $this->assertSame([], $score->details['misses']);
    }

    public function test_returns_partial_credit_when_one_expected_citation_is_missing(): void
    {
        $sample = $this->sample(
            id: 'partial',
            projectKey: 'hr-portal',
            expected: ['policies/remote-work-policy.md', 'policies/pto-guidelines.md'],
        );
        $payload = $this->payload(
            answer: 'Up to 3 days per week.',
            citations: [['source_path' => 'policies/remote-work-policy.md']],
            projectKey: 'hr-portal',
        );

        $score = (new CitationGroundednessMetric)->score($sample, $payload);

        $this->assertSame(0.5, $score->score);
        $this->assertSame(1, $score->details['hits']);
        $this->assertSame(['policies/pto-guidelines.md'], $score->details['misses']);
    }

    public function test_returns_one_when_refusal_sample_emits_empty_citations(): void
    {
        $sample = $this->sample(
            id: 'refusal-clean',
            projectKey: 'hr-portal',
            expected: [],
        );
        $payload = $this->payload(
            answer: "I don't have information about that.",
            citations: [],
            projectKey: 'hr-portal',
        );

        $score = (new CitationGroundednessMetric)->score($sample, $payload);

        $this->assertSame(1.0, $score->score);
        $this->assertSame('refusal_clean', $score->details['reason']);
    }

    public function test_returns_zero_when_refusal_sample_fabricates_a_citation(): void
    {
        $sample = $this->sample(
            id: 'refusal-fabricated',
            projectKey: 'hr-portal',
            expected: [],
        );
        $payload = $this->payload(
            answer: 'Per the report it is 9.4%.',
            citations: [['source_path' => 'reports/q3-revenue.md']],
            projectKey: 'hr-portal',
        );

        $score = (new CitationGroundednessMetric)->score($sample, $payload);

        $this->assertSame(0.0, $score->score);
        $this->assertSame('refusal_with_fabricated_citations', $score->details['reason']);
        $this->assertSame(['reports/q3-revenue.md'], $score->details['fabricated_paths']);
    }

    public function test_caps_score_at_half_when_phantom_citation_is_present(): void
    {
        // Seed only the expected doc so the phantom path resolves to nothing.
        $this->seedDoc('hr-portal', 'policies/remote-work-policy.md');

        $sample = $this->sample(
            id: 'phantom',
            projectKey: 'hr-portal',
            expected: ['policies/remote-work-policy.md'],
        );
        $payload = $this->payload(
            answer: 'Per ACME policies.',
            citations: [
                ['source_path' => 'policies/remote-work-policy.md'],
                ['source_path' => 'policies/non-existent-fabricated.md'],
            ],
            projectKey: 'hr-portal',
        );

        $score = (new CitationGroundednessMetric)->score($sample, $payload);

        // hits = 1, misses = 0, denom = 1 → recall = 1.0, but a phantom
        // is present → cap at 0.5.
        $this->assertSame(0.5, $score->score);
        $this->assertSame(['policies/non-existent-fabricated.md'], $score->details['phantom_paths']);
    }

    public function test_does_not_penalise_extra_citation_that_resolves_to_a_real_doc(): void
    {
        // Both docs are real seeded rows; the extra one is "context",
        // not phantom — graph-expander legitimately surfaces related docs.
        $this->seedDoc('hr-portal', 'policies/remote-work-policy.md');
        $this->seedDoc('hr-portal', 'policies/pto-guidelines.md');

        $sample = $this->sample(
            id: 'extra-real',
            projectKey: 'hr-portal',
            expected: ['policies/remote-work-policy.md'],
        );
        $payload = $this->payload(
            answer: 'Up to 3 days per week.',
            citations: [
                ['source_path' => 'policies/remote-work-policy.md'],
                ['source_path' => 'policies/pto-guidelines.md'],
            ],
            projectKey: 'hr-portal',
        );

        $score = (new CitationGroundednessMetric)->score($sample, $payload);

        $this->assertSame(1.0, $score->score);
        $this->assertSame([], $score->details['phantom_paths']);
    }

    public function test_returns_zero_with_unparseable_payload(): void
    {
        $sample = $this->sample('id', 'hr-portal', ['policies/remote-work-policy.md']);

        $score = (new CitationGroundednessMetric)->score($sample, 'not-json');

        $this->assertSame(0.0, $score->score);
        $this->assertSame('unparseable_payload', $score->details['reason']);
    }

    /**
     * @param  list<string>  $expected
     */
    private function sample(string $id, string $projectKey, array $expected): DatasetSample
    {
        return new DatasetSample(
            id: $id,
            input: ['question' => 'q', 'project_key' => $projectKey],
            expectedOutput: 'expected',
            metadata: ['expected_citations' => $expected],
        );
    }

    /**
     * @param  list<array<string, mixed>>  $citations
     */
    private function payload(string $answer, array $citations, string $projectKey): string
    {
        return json_encode([
            'answer' => $answer,
            'citations' => $citations,
            'meta' => ['project_key' => $projectKey],
        ], JSON_THROW_ON_ERROR);
    }

    private function seedDoc(string $projectKey, string $sourcePath): KnowledgeDocument
    {
        return KnowledgeDocument::create([
            'project_key' => $projectKey,
            'source_type' => 'md',
            'title' => 'Seeded',
            'source_path' => $sourcePath,
            'mime_type' => 'text/markdown',
            'language' => 'en',
            'access_scope' => 'project',
            'status' => 'indexed',
            'document_hash' => hash('sha256', $sourcePath),
            'version_hash' => hash('sha256', $sourcePath.'/v1'),
            'metadata' => [],
        ]);
    }
}
