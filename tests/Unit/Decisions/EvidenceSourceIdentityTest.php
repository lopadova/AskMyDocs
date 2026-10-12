<?php

namespace Tests\Unit\Decisions;

use App\Services\Chat\Reasoning\EvidenceProjector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EvidenceSourceIdentityTest extends TestCase
{
    private function evidence(): array
    {
        return ['documents' => [['document_id' => 12, 'evidence' => [['content' => 'Original passage', 'evidence_hash' => 'doc-hash']]]],
            'api_tools' => [['execution_id' => 86, 'evidence_hash' => 'tool-hash', 'result' => ['records' => [['id' => 'SHIP-1']]]]]];
    }

    public function test_unique_hash_resolves_source_not_arbitrary_malformed_key_value(): void
    {
        $projector = new EvidenceProjector;
        $tool = $projector->resolveSourceIdentity($this->evidence(), ['document_id=null,' => 999, 'evidence_hash' => 'tool-hash']);
        $this->assertNull($tool['document_id']);
        $this->assertSame(86, $tool['tool_execution_id']);
        $doc = $projector->resolveSourceIdentity($this->evidence(), ['evidence_hash' => 'doc-hash']);
        $this->assertSame(12, $doc['document_id']);
        $this->assertNull($doc['tool_execution_id']);
    }

    public static function unchangedClaims(): array
    {
        return [
            'explicit wrong source' => [['tool_execution_id' => 999, 'evidence_hash' => 'tool-hash']],
            'explicit wrong kind' => [['document_id' => 86, 'evidence_hash' => 'tool-hash']],
            'both kinds set' => [['document_id' => 12, 'tool_execution_id' => 86, 'evidence_hash' => 'tool-hash']],
            'wrong hash' => [['document_id=null,' => 86, 'evidence_hash' => 'absent']],
            'missing hash' => [['document_id=null,' => 86]],
            'invalid hash' => [['evidence_hash' => ['tool-hash']]],
        ];
    }

    #[DataProvider('unchangedClaims')]
    public function test_explicit_identity_and_unmatched_hash_are_never_rewritten(array $claim): void
    {
        $this->assertSame($claim, (new EvidenceProjector)->resolveSourceIdentity($this->evidence(), $claim));
    }

    public function test_hash_ambiguous_between_executions_or_source_kinds_is_not_recovered(): void
    {
        $projector = new EvidenceProjector;
        $claim = ['evidence_hash' => 'tool-hash'];
        $evidence = $this->evidence();
        $evidence['api_tools'][] = [...$evidence['api_tools'][0], 'execution_id' => 87];
        $this->assertSame($claim, $projector->resolveSourceIdentity($evidence, $claim));
        $evidence = $this->evidence();
        $evidence['documents'][0]['evidence'][0]['evidence_hash'] = 'tool-hash';
        $this->assertSame($claim, $projector->resolveSourceIdentity($evidence, $claim));
    }

    public function test_prompt_has_exact_canonical_source_fields_without_changing_original_payload(): void
    {
        $evidence = $this->evidence();
        $prompt = (new EvidenceProjector)->forPrompt($evidence);
        $this->assertSame(['document_id' => 12, 'tool_execution_id' => null, 'evidence_hash' => 'doc-hash'],
            $prompt['documents'][0]['evidence'][0]['claim_source']);
        $this->assertSame(['document_id' => null, 'tool_execution_id' => 86, 'evidence_hash' => 'tool-hash'],
            $prompt['api_tools'][0]['claim_source']);
        $this->assertSame(['records.0'], $prompt['api_tools'][0]['result']['record_paths']);
        $this->assertSame($this->evidence(), $evidence);
        $incomplete = ['documents' => [['evidence' => [['content' => 'Incomplete source']]]], 'api_tools' => [[]]];
        $this->assertSame($incomplete, (new EvidenceProjector)->forPrompt($incomplete));
    }

    public function test_an_exact_prose_subject_anchor_excludes_an_unrelated_case_procedure(): void
    {
        $evidence = ['documents' => [
            ['document_id' => 1, 'title' => 'ADA ROSSI refund', 'evidence' => [['content' => 'Refund requested.', 'evidence_hash' => 'ada']]],
            ['document_id' => 2, 'title' => 'Bruno Verdi return', 'evidence' => [['content' => 'Refund uses original payment method.', 'evidence_hash' => 'bruno']]],
        ]];
        $projector = new EvidenceProjector;
        $this->assertSame('entity_mismatch', $projector->bind($evidence,
            ['document_id' => 2, 'evidence_hash' => 'bruno'], ['Ada Rossi'], 'default', 'demo')['reason']);
        $this->assertSame('Refund requested.', $projector->bind($evidence,
            ['document_id' => 1, 'evidence_hash' => 'ada'], ['Ada Rossi'], 'default', 'demo')['quote']);
    }

    public function test_same_document_context_is_paired_even_when_interpretation_has_no_identifiers(): void
    {
        $evidence = $this->evidence();
        $evidence['documents'][0]['evidence'][] = ['content' => 'Original subject and final response.', 'evidence_hash' => 'full'];
        $alternatives = (new EvidenceProjector)->alternatives($evidence, [], 'Original passage', 'default', 'demo', 12);
        $this->assertSame('Original subject and final response.', $alternatives[0]['source']);
        $this->assertCount(1, $alternatives);
    }

    public function test_prose_actor_and_subject_are_resolved_in_the_same_document_without_requiring_literal_actor_in_each_reply(): void
    {
        $evidence = ['documents' => [['document_id' => 1, 'title' => 'ALPINE MAP compatibility', 'evidence' => [
            ['content' => 'Is ALPINE MAP compatible with Device X?', 'evidence_hash' => 'question'],
            ['content' => 'Unfortunately it is not compatible.', 'evidence_hash' => 'reply'],
        ]]]];
        $bound = (new EvidenceProjector)->bind($evidence, ['document_id' => 1, 'evidence_hash' => 'reply'],
            ['Merchant', 'ALPINE MAP', 'Device X'], 'default', 'demo');
        $this->assertSame('Unfortunately it is not compatible.', $bound['quote']);
    }
}
