<?php

declare(strict_types=1);

namespace Tests\Unit\Compliance;

use App\Compliance\ProvenanceChain;
use App\Compliance\RagRefusalQualityMetric;
use Tests\TestCase;

class ComplianceScaffoldHelpersTest extends TestCase
{
    public function test_provenance_chain_keeps_known_keys_and_defaults_missing_values(): void
    {
        $trace = (new ProvenanceChain())->trace([
            'eval_trace_id' => 'trace-123',
            'retrieval' => ['top_k' => 5],
            'chunk' => ['id' => 44],
        ]);

        $this->assertSame('trace-123', $trace['eval_trace_id']);
        $this->assertSame(['top_k' => 5], $trace['retrieval']);
        $this->assertSame(['id' => 44], $trace['chunk']);
        $this->assertSame([], $trace['document']);
        $this->assertNull($trace['frontmatter_author']);
    }

    public function test_rag_refusal_quality_metric_computes_score_delta_and_rate(): void
    {
        $metric = (new RagRefusalQualityMetric())->compute([
            'cohort' => 'dpo',
            'total' => 8,
            'refusals' => 2,
            'baseline' => 0.8,
        ]);

        $this->assertSame('dpo', $metric['cohort']);
        $this->assertSame(0.75, $metric['score']);
        $this->assertSame(0.05, $metric['delta']);
        $this->assertSame(0.25, $metric['refusal_rate']);
    }
}
