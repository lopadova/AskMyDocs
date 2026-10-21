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

    public function test_an_expanded_passage_supersedes_its_contained_excerpt_in_either_merge_order(): void
    {
        $brief = ['chunk_id' => 10, 'content' => 'The customer requested a refund.', 'evidence_hash' => 'brief', 'research_flow_ids' => [0]];
        $full = ['chunk_id' => 10, 'content' => 'The item was unavailable. The customer requested a refund. The merchant confirmed cancellation.',
            'evidence_hash' => 'full', 'research_flow_ids' => [1]];
        foreach ([[$brief, $full], [$full, $brief]] as $passages) {
            $envelope = app(AgentEvidenceFactory::class)->empty();
            foreach ($passages as $passage) {
                $envelope->addDocument(['document_id' => 1, 'evidence' => [$passage]]);
            }
            $result = $envelope->documents()[0]['evidence'];
            $this->assertCount(1, $result);
            $this->assertSame('full', $result[0]['evidence_hash']);
            $this->assertSame($full['content'], $result[0]['content']);
            $this->assertEqualsCanonicalizing([0, 1], $result[0]['research_flow_ids']);
        }
    }

    public function test_configured_record_identities_reach_the_grounding_envelope(): void
    {
        $tool = new AgentToolDefinition(name: 'search_erp', displayName: 'ERP', description: 'Records', kind: 'api',
            inputSchema: ['type' => 'object'], readOnly: true, idempotent: true,
            physicalMinimum: 1, physicalLikely: 1, physicalMaximum: 1,
            metadata: ['items_path' => 'items', 'output_schema' => ['type' => 'object', 'properties' => [
                'items' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'erp_reference' => ['type' => 'string', 'x-identity' => true],
                ]]],
            ]]]);
        $envelope = app(AgentEvidenceFactory::class)->empty();
        $envelope->addToolResult($tool, [], ['items' => [['erp_reference' => 'ORDER-A']]], 42);
        $this->assertSame(['erp_reference'], $envelope->apiTools()[0]['presentation']['identity_fields']);
    }

    public function test_noncontained_or_different_chunk_passages_are_not_discarded(): void
    {
        $envelope = app(AgentEvidenceFactory::class)->empty();
        $envelope->addDocument(['document_id' => 1, 'evidence' => [
            ['chunk_id' => 10, 'content' => 'Refund requested.', 'evidence_hash' => 'a'],
            ['chunk_id' => 10, 'content' => 'Refund declined.', 'evidence_hash' => 'b'],
            ['chunk_id' => 11, 'content' => 'Refund requested. Cancellation accepted.', 'evidence_hash' => 'c'],
        ]]);
        $this->assertCount(3, $envelope->documents()[0]['evidence']);
    }
}
