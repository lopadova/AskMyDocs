<?php

declare(strict_types=1);

namespace Tests\Unit\Agent;

use App\Agent\Evidence\AgentEvidenceFactory;
use App\Agent\Tools\AgentToolDefinition;
use App\Services\Kb\Retrieval\SearchResult;
use Tests\TestCase;

final class AgentEvidenceEnvelopeTest extends TestCase
{
    public function test_document_evidence_is_grouped_deduplicated_and_masked(): void
    {
        $chunk = [
            'chunk_id' => 10,
            'chunk_hash' => 'hash-10',
            'chunk_text' => 'Contatta mario@example.com per questo ordine.',
            'heading_path' => 'Ordini',
            'rerank_score' => 0.9,
            'project_key' => 'orders',
            'document' => [
                'id' => 7,
                'title' => 'Ordini',
                'source_path' => 'orders.md',
                'source_type' => 'markdown',
            ],
        ];
        $result = new SearchResult(collect([$chunk, $chunk]), collect(), collect());

        $evidence = app(AgentEvidenceFactory::class)->fromSearchResult($result);

        $this->assertCount(1, $evidence->documents());
        $this->assertCount(1, $evidence->documents()[0]['evidence']);
        $this->assertStringContainsString('[EMAIL]', $evidence->documents()[0]['evidence'][0]['content']);
        $this->assertSame(1, $evidence->documents()[0]['chunks_used']);
    }

    public function test_api_evidence_preserves_provenance_but_masks_payloads(): void
    {
        $envelope = app(AgentEvidenceFactory::class)->empty();
        $tool = new AgentToolDefinition(
            name: 'get_orders',
            displayName: 'ERP orders',
            description: 'Orders',
            kind: 'api',
            inputSchema: ['type' => 'object'],
            readOnly: true,
            idempotent: true,
            physicalMinimum: 1,
            physicalLikely: 1,
            physicalMaximum: 1,
            executorReference: 12,
        );

        $envelope->addToolResult(
            $tool,
            ['customer' => 'mario@example.com'],
            ['token' => 'Bearer abcdefghijklmnopqrstuvwxyz', 'orders' => [['id' => 1]]],
            99,
        );

        $api = $envelope->apiTools()[0];
        $this->assertSame(99, $api['execution_id']);
        $this->assertSame(12, $api['executor_reference']);
        $this->assertSame('[EMAIL]', $api['arguments']['customer']);
        $this->assertSame('Bearer [TOKEN]', $api['result']['token']);
        $this->assertNotEmpty($api['evidence_hash']);
        $this->assertTrue($envelope->hasEvidence());
    }
}
