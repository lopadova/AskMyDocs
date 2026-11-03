<?php

declare(strict_types=1);

namespace Tests\Unit\Decisions;

use App\Agent\Evidence\ToolResultLineage;
use App\Agent\Grounding\AgentClaimGroundingValidator;
use App\Services\Chat\Reasoning\EvidenceProjector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ToolResultLineageTest extends TestCase
{
    private function observation(int $id, array $result, array $identities = ['reference']): array
    {
        return ['execution_id' => $id, 'tool' => 'lookup_'.$id, 'tenant_id' => 'tenant-a', 'project_key' => 'project-a',
            'result' => $result, 'evidence_hash' => hash('sha256', json_encode($result, JSON_UNESCAPED_UNICODE)),
            'presentation' => ['collection_path' => 'rows', 'identity_fields' => $identities], 'arguments' => []];
    }

    private function fixture(string|int $identifier = 7654321098765): array
    {
        $parent = $this->observation(11, ['rows' => [['reference' => 'PURCHASE-Q91', 'lines' => [
            ['variant' => 'MODEL-R73', 'identifiers' => [['external_key' => $identifier]]],
            ['variant' => 'MODEL-W46', 'identifiers' => [['external_key' => 7654321098766]]],
        ]]]]);
        $child = $this->observation(12, ['rows' => [['resource_key' => $identifier, 'available' => 0, 'location' => 2]]], ['resource_key']);
        $child['arguments'] = ['lookup' => $identifier];
        $child['argument_bindings'] = (new ToolResultLineage)->capture(
            ['lookup' => ['$from' => 'parent', 'path' => 'rows.0.lines.0.identifiers.0.external_key']], $child['arguments'],
            [['id' => 'parent', 'execution_id' => 11, 'status' => 'completed']], ['api_tools' => [$parent]]);
        return ['api_tools' => [$parent, $child]];
    }

    private function claim(array $evidence): array
    {
        return ['tool_execution_id' => 12, 'evidence_hash' => $evidence['api_tools'][1]['evidence_hash'],
            'record_path' => 'rows.0', 'text' => 'PURCHASE-Q91: MODEL-R73 has available quantity 0.'];
    }

    public function test_exact_nested_dependency_binds_a_related_record_without_modifying_the_response(): void
    {
        $e = $this->fixture();
        $original = $e;
        $projector = new EvidenceProjector;
        $bound = $projector->bind($e, $this->claim($e), ['PURCHASE-Q91', 'MODEL-R73'], 'tenant-a', 'project-a');
        $this->assertArrayNotHasKey('reason', $bound);
        $this->assertSame($e['api_tools'][1]['result']['rows'][0], $bound['record']);
        $this->assertSame(11, $bound['dependency_context'][0]['execution_id']);
        $this->assertNotContains('MODEL-W46', $bound['dependency_context'][0]['identifiers']);
        $this->assertSame($original, $e);
        $prompt = $projector->forPrompt($e);
        $this->assertNotEmpty($prompt['api_tools'][1]['record_sources'][0]['verified_dependency_identity']);
        $tables = $projector->forTable($e['api_tools'], ['focus' => ['identifiers' => ['PURCHASE-Q91']]], 'tenant-a', 'project-a');
        $this->assertCount(2, $tables);
    }

    public function test_verified_identity_context_does_not_allow_other_siblings_or_invented_references(): void
    {
        $e = $this->fixture();
        $projector = new EvidenceProjector;
        $this->assertSame('entity_mismatch', $projector->bind($e, $this->claim($e), ['PURCHASE-Q91', 'MODEL-W46'], 'tenant-a', 'project-a')['reason']);
        $this->assertSame('entity_mismatch', $projector->bind($e, $this->claim($e), ['PURCHASE-X12'], 'tenant-a', 'project-a')['reason']);
        $bound = $projector->bind($e, $this->claim($e), ['PURCHASE-Q91'], 'tenant-a', 'project-a');
        $validator = new AgentClaimGroundingValidator;
        $claim = [...$this->claim($e), 'quote' => $bound['quote']];
        $this->assertTrue($validator->validate('Availability for PURCHASE-Q91?', $e, [$claim])['valid']);
        $claim['text'] = 'PURCHASE-Q91: MODEL-W46 has available quantity 0.';
        $this->assertSame('unattested_literal_reference', $validator->validate('Availability?', $e, [$claim])['reason']);
    }

    public function test_a_multi_item_focus_can_bind_one_requested_item_without_attesting_its_sibling(): void
    {
        $e = $this->fixture();
        $bound = (new EvidenceProjector)->bind($e, $this->claim($e), ['PURCHASE-Q91', 'MODEL-R73', 'MODEL-W46'], 'tenant-a', 'project-a');
        $this->assertArrayNotHasKey('reason', $bound);
        $claim = [...$this->claim($e), 'quote' => $bound['quote']];
        $validator = new AgentClaimGroundingValidator;
        $this->assertTrue($validator->validate('Availability for both requested items?', $e, [$claim])['valid']);
        $claim['text'] = 'PURCHASE-Q91: MODEL-W46 has available quantity 0.';
        $this->assertSame('unattested_literal_reference', $validator->validate('Availability for both requested items?', $e, [$claim])['reason']);
    }

    public static function invalidProofs(): array
    {
        return array_map(fn ($reason) => [$reason], ['no_dependency', 'revoked_parent', 'changed_parent', 'changed_child',
            'changed_arguments', 'wrong_parent_hash', 'duplicate_parent', 'foreign_tenant', 'foreign_project',
            'future_parent', 'wildcard_path', 'undeclared_custom_identity', 'query_echo_only']);
    }

    #[DataProvider('invalidProofs')]
    public function test_unproved_or_invalid_dependencies_remain_rejected(string $variant): void
    {
        $e = $this->fixture();
        switch ($variant) {
            case 'no_dependency': unset($e['api_tools'][1]['argument_bindings']); break;
            case 'revoked_parent': $e['api_tools'] = [$e['api_tools'][1]]; break;
            case 'changed_parent': $e['api_tools'][0]['result']['rows'][0]['reference'] = 'OTHER-14'; break;
            case 'changed_child': $e['api_tools'][1]['result']['rows'][0]['resource_key']++; break;
            case 'changed_arguments': $e['api_tools'][1]['arguments']['lookup']++; break;
            case 'wrong_parent_hash': $e['api_tools'][1]['argument_bindings'][0]['parent_evidence_hash'] = str_repeat('a', 64); break;
            case 'duplicate_parent': $e['api_tools'][] = $e['api_tools'][0]; break;
            case 'foreign_tenant': $e['api_tools'][0]['tenant_id'] = 'tenant-b'; break;
            case 'foreign_project': $e['api_tools'][0]['project_key'] = 'project-b'; break;
            case 'future_parent': $e['api_tools'][1]['argument_bindings'][0]['parent_execution_id'] = 12; break;
            case 'wildcard_path': $e['api_tools'][1]['argument_bindings'][0]['source_path'] = 'rows.*.lines.*.external_key'; break;
            case 'undeclared_custom_identity': $e['api_tools'][1]['presentation']['identity_fields'] = []; break;
            case 'query_echo_only':
                $e['api_tools'][1]['arguments']['lookup'] = 'PURCHASE-Q91';
                $e['api_tools'][1]['argument_bindings'][0]['value_hash'] = hash('sha256', 'PURCHASE-Q91');
                break;
        }
        $claim = ['tool_execution_id' => 12, 'evidence_hash' => $this->fixture()['api_tools'][1]['evidence_hash'], 'record_path' => 'rows.0'];
        $this->assertSame('entity_mismatch', (new EvidenceProjector)->bind($e, $claim, ['PURCHASE-Q91'], 'tenant-a', 'project-a')['reason']);
    }

    public function test_literal_values_and_wrapper_echoes_never_create_dependencies(): void
    {
        $e = $this->fixture();
        $service = new ToolResultLineage;
        $completed = [['id' => 'parent', 'execution_id' => 11, 'status' => 'completed']];
        $this->assertSame([], $service->capture(['lookup' => 7654321098765], ['lookup' => 7654321098765], $completed, $e));
        $e['api_tools'][0]['result']['query'] = 7654321098765;
        $e['api_tools'][0]['evidence_hash'] = hash('sha256', json_encode($e['api_tools'][0]['result'], JSON_UNESCAPED_UNICODE));
        $this->assertSame([], $service->capture(['lookup' => ['$from' => 'parent', 'path' => 'query']], ['lookup' => 7654321098765], $completed, $e));
    }

    public function test_leading_zero_identifiers_are_not_conflated_with_numbers(): void
    {
        $e = $this->fixture('0007654321098765');
        $this->assertArrayNotHasKey('reason', (new EvidenceProjector)->bind($e, $this->claim($e), ['PURCHASE-Q91'], 'tenant-a', 'project-a'));
        $e['api_tools'][1]['arguments']['lookup'] = 7654321098765;
        $this->assertSame('entity_mismatch', (new EvidenceProjector)->bind($e, $this->claim($e), ['PURCHASE-Q91'], 'tenant-a', 'project-a')['reason']);
    }

    public function test_a_child_collection_without_an_exact_selected_record_remains_ambiguous(): void
    {
        $e = $this->fixture();
        $e['api_tools'][1]['result']['rows'][] = [...$e['api_tools'][1]['result']['rows'][0], 'location' => 3];
        $e['api_tools'][1]['evidence_hash'] = hash('sha256', json_encode($e['api_tools'][1]['result'], JSON_UNESCAPED_UNICODE));
        $claim = $this->claim($e); unset($claim['record_path']);
        $this->assertSame('ambiguous_record', (new EvidenceProjector)->bind($e, $claim, ['PURCHASE-Q91'], 'tenant-a', 'project-a')['reason']);
    }

    public function test_transitive_order_lookup_cannot_hide_a_conflicting_sibling_item(): void
    {
        $e = $this->fixture();
        $root = $this->observation(10, ['rows' => [['reference' => 'PURCHASE-Q91']]]);
        $e['api_tools'][0]['arguments'] = ['lookup' => 'PURCHASE-Q91'];
        $e['api_tools'][0]['argument_bindings'] = (new ToolResultLineage)->capture(
            ['lookup' => ['$from' => 'root', 'path' => 'rows.0.reference']], ['lookup' => 'PURCHASE-Q91'],
            [['id' => 'root', 'status' => 'completed', 'execution_id' => 10]], ['api_tools' => [$root]]);
        $e['api_tools'][] = $root;
        $projector = new EvidenceProjector;
        $valid = $projector->bind($e, $this->claim($e), ['PURCHASE-Q91', 'MODEL-R73'], 'tenant-a', 'project-a');
        $this->assertArrayNotHasKey('reason', $valid);
        $this->assertCount(2, $valid['dependency_context']);
        $this->assertSame('entity_mismatch', $projector->bind($e, $this->claim($e), ['PURCHASE-Q91', 'MODEL-W46'], 'tenant-a', 'project-a')['reason']);
    }

    public function test_custom_record_identity_must_match_the_actual_returned_record_not_a_query_echo(): void
    {
        $e = $this->fixture();
        $e['api_tools'][1]['result']['query'] = 7654321098765;
        $e['api_tools'][1]['result']['rows'][0]['resource_key'] = 7654321098766;
        $e['api_tools'][1]['evidence_hash'] = hash('sha256', json_encode($e['api_tools'][1]['result'], JSON_UNESCAPED_UNICODE));
        $this->assertSame('entity_mismatch', (new EvidenceProjector)->bind($e, $this->claim($e), ['PURCHASE-Q91'], 'tenant-a', 'project-a')['reason']);
    }

    public function test_a_foreign_key_in_an_unanchored_parent_is_not_the_requested_entity_identity(): void
    {
        $e = $this->fixture();
        $e['api_tools'][0]['result']['rows'][0]['foreign_reference'] = 'OTHER-P13';
        $e['api_tools'][0]['evidence_hash'] = hash('sha256', json_encode($e['api_tools'][0]['result'], JSON_UNESCAPED_UNICODE));
        $e['api_tools'][1]['argument_bindings'][0]['parent_evidence_hash'] = $e['api_tools'][0]['evidence_hash'];
        $this->assertSame('entity_mismatch', (new EvidenceProjector)->bind($e, $this->claim($e), ['OTHER-P13'], 'tenant-a', 'project-a')['reason']);
    }
}
