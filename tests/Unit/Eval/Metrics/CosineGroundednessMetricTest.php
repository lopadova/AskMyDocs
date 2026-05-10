<?php

declare(strict_types=1);

namespace Tests\Unit\Eval\Metrics;

use App\Ai\AiManager;
use App\Ai\EmbeddingsResponse;
use App\Eval\Metrics\CosineGroundednessMetric;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Padosoft\EvalHarness\Datasets\DatasetSample;
use Tests\TestCase;

/**
 * R23: CosineGroundednessMetric implements Padosoft\EvalHarness\Metrics\Metric.
 * R16: every test exercises the behaviour its name promises — the
 *      assertions inspect the SCORE the metric returned, not just
 *      that it was a MetricScore.
 */
final class CosineGroundednessMetricTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_high_score_when_answer_aligns_with_cited_chunk(): void
    {
        [$doc, $chunk] = $this->seedDocAndChunk(
            'hr-portal',
            'policies/remote-work-policy.md',
            'Up to 3 remote workdays per week with manager approval.',
        );

        // Same vector → cosine = 1.0
        $aligned = $this->vectorOnes(8);
        $ai = $this->aiReturning([$aligned, $aligned]);

        $sample = $this->sample('aligned', 'hr-portal');
        $payload = $this->payload(
            answer: 'Three remote days per week with manager approval.',
            citations: [['source_path' => 'policies/remote-work-policy.md']],
            projectKey: 'hr-portal',
        );

        $score = (new CosineGroundednessMetric($ai))->score($sample, $payload);

        $this->assertEqualsWithDelta(1.0, $score->score, 1e-6);
        $this->assertSame(['policies/remote-work-policy.md'], $score->details['cited_paths']);
    }

    public function test_returns_zero_when_answer_is_orthogonal_to_cited_chunk(): void
    {
        [$doc, $chunk] = $this->seedDocAndChunk(
            'hr-portal',
            'policies/pto-guidelines.md',
            'Employees accrue 2 PTO days per month.',
        );

        // Orthogonal vectors: [1,0,...] vs [0,1,0,...] → cosine = 0
        $a = [1.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0];
        $b = [0.0, 1.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0];
        $ai = $this->aiReturning([$a, $b]);

        $sample = $this->sample('orthogonal', 'hr-portal');
        $payload = $this->payload(
            answer: 'Hallucinated answer about something else entirely.',
            citations: [['source_path' => 'policies/pto-guidelines.md']],
            projectKey: 'hr-portal',
        );

        $score = (new CosineGroundednessMetric($ai))->score($sample, $payload);

        $this->assertSame(0.0, $score->score);
    }

    public function test_returns_one_when_payload_carries_no_citations(): void
    {
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldNotReceive('generateEmbeddings');

        $sample = $this->sample('refusal', 'hr-portal');
        $payload = $this->payload(
            answer: "I don't have grounding context.",
            citations: [],
            projectKey: 'hr-portal',
        );

        $score = (new CosineGroundednessMetric($ai))->score($sample, $payload);

        $this->assertSame(1.0, $score->score);
        $this->assertSame('no_citations', $score->details['reason']);
    }

    public function test_returns_one_when_citations_do_not_resolve_to_seeded_docs(): void
    {
        // No KnowledgeDocument seeded → loadChunkTextForCitations returns ''.
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldNotReceive('generateEmbeddings');

        $sample = $this->sample('unresolved', 'hr-portal');
        $payload = $this->payload(
            answer: 'Some answer.',
            citations: [['source_path' => 'unknown/doc.md']],
            projectKey: 'hr-portal',
        );

        $score = (new CosineGroundednessMetric($ai))->score($sample, $payload);

        $this->assertSame(1.0, $score->score);
        $this->assertSame('citations_unresolved', $score->details['reason']);
    }

    public function test_returns_zero_when_payload_is_unparseable(): void
    {
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldNotReceive('generateEmbeddings');

        $sample = $this->sample('bad', 'hr-portal');
        $score = (new CosineGroundednessMetric($ai))->score($sample, 'not-json');

        $this->assertSame(0.0, $score->score);
        $this->assertSame('unparseable_payload', $score->details['reason']);
    }

    public function test_returns_zero_with_empty_answer(): void
    {
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldNotReceive('generateEmbeddings');

        $sample = $this->sample('empty', 'hr-portal');
        $payload = $this->payload(
            answer: '',
            citations: [['source_path' => 'policies/remote-work-policy.md']],
            projectKey: 'hr-portal',
        );

        $score = (new CosineGroundednessMetric($ai))->score($sample, $payload);

        $this->assertSame(0.0, $score->score);
        $this->assertSame('empty_answer', $score->details['reason']);
    }

    private function sample(string $id, string $projectKey): DatasetSample
    {
        return new DatasetSample(
            id: $id,
            input: ['question' => 'q', 'project_key' => $projectKey],
            expectedOutput: 'expected',
            metadata: [],
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

    /**
     * @return list<float>
     */
    private function vectorOnes(int $dim): array
    {
        return array_fill(0, $dim, 1.0);
    }

    /**
     * @param  list<list<float>>  $vectors
     */
    private function aiReturning(array $vectors): AiManager
    {
        $ai = Mockery::mock(AiManager::class);
        $response = new EmbeddingsResponse(
            embeddings: $vectors,
            provider: 'fake',
            model: 'test-model',
        );
        $ai->shouldReceive('generateEmbeddings')->andReturn($response);

        return $ai;
    }

    /**
     * @return array{0: KnowledgeDocument, 1: KnowledgeChunk}
     */
    private function seedDocAndChunk(string $projectKey, string $sourcePath, string $chunkText): array
    {
        $doc = KnowledgeDocument::create([
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

        $chunk = KnowledgeChunk::create([
            'knowledge_document_id' => $doc->id,
            'project_key' => $projectKey,
            'chunk_order' => 0,
            'chunk_hash' => hash('sha256', $chunkText),
            'heading_path' => 'Intro',
            'chunk_text' => $chunkText,
            'metadata' => [],
        ]);

        return [$doc, $chunk];
    }
}
