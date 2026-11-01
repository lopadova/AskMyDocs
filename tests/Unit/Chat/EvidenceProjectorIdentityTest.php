<?php

declare(strict_types=1);

namespace Tests\Unit\Chat;

use App\Services\Chat\Reasoning\EvidenceProjector;
use PHPUnit\Framework\TestCase;

final class EvidenceProjectorIdentityTest extends TestCase
{
    private function evidence(): array
    {
        return ['api_tools' => [['execution_id' => 42, 'evidence_hash' => 'hash', 'tenant_id' => 'acme', 'project_key' => 'orders', 'display_name' => 'Warehouse ERP',
            'presentation' => ['collection_path' => 'orders', 'identity_fields' => ['erp_id', 'erp_reference']],
            'result' => ['query' => 'ORDER-A', 'orders' => [
                ['erp_id' => 1, 'erp_reference' => 'ORDER-A', 'customerId' => 'CUSTOMER-X', 'status' => 'shipped'],
                ['erp_id' => 2, 'erp_reference' => 'ORDER-B', 'customerId' => 'ORDER-A', 'status' => 'cancelled'],
            ]]]]];
    }

    public function test_declared_identity_supports_custom_fields_without_treating_brand_as_a_required_key(): void
    {
        $result = (new EvidenceProjector)->bind($this->evidence(),
            ['tool_execution_id' => 42, 'evidence_hash' => 'hash', 'record_path' => 'orders.0'], ['ORDER-A', 'Brand'], 'acme', 'orders');
        $this->assertSame('shipped', $result['record']['status']);
        $this->assertSame('orders.0', $result['path']);
        $this->assertSame('Warehouse ERP', $result['source_title']);
    }

    public function test_query_echo_and_foreign_keys_cannot_authorize_the_wrong_record(): void
    {
        $result = (new EvidenceProjector)->bind($this->evidence(),
            ['tool_execution_id' => 42, 'evidence_hash' => 'hash', 'record_path' => 'orders.1'], ['ORDER-A'], 'acme', 'orders');
        $this->assertSame('entity_mismatch', $result['reason']);
    }

    public function test_declared_identity_cannot_bypass_source_hash_or_tenant(): void
    {
        $projector = new EvidenceProjector;
        $claim = ['tool_execution_id' => 42, 'evidence_hash' => 'wrong', 'record_path' => 'orders.0'];
        $this->assertSame('source_hash_mismatch', $projector->bind($this->evidence(), $claim, ['ORDER-A'], 'acme', 'orders')['reason']);
        $claim['evidence_hash'] = 'hash';
        $this->assertSame('source_scope_mismatch', $projector->bind($this->evidence(), $claim, ['ORDER-A'], 'other', 'orders')['reason']);
    }

    public function test_declared_identity_supports_a_root_array_without_authorizing_query_echoes(): void
    {
        $evidence = ['api_tools' => [['execution_id' => 42, 'evidence_hash' => 'hash',
            'presentation' => ['identity_fields' => ['ord_codiceweb']],
            'arguments' => ['ord_codiceweb' => 'ORDER-A'],
            'result' => [['ord_codiceweb' => 'ORDER-B'], ['ord_codiceweb' => 'ORDER-A']],
        ]]];
        $projector = new EvidenceProjector;
        $claim = ['tool_execution_id' => 42, 'evidence_hash' => 'hash'];
        $this->assertSame('$.1', $projector->bind($evidence, $claim, ['ORDER-A'], 'acme', 'orders')['path']);
        $claim['record_path'] = '$.0';
        $this->assertSame('entity_mismatch', $projector->bind($evidence, $claim, ['ORDER-A'], 'acme', 'orders')['reason']);
    }

    public function test_record_references_resolve_the_parent_without_exposing_a_nested_path_choice(): void
    {
        $evidence = $this->evidence();
        $evidence['api_tools'][0]['result']['orders'][0]['articles'] = [['id' => 'CHILD-1', 'sku' => 'SKU-1']];
        $projector = new EvidenceProjector;
        $prompt = $projector->forPrompt($evidence);
        $references = $prompt['api_tools'][0]['record_sources'];
        $this->assertArrayNotHasKey('source_ref', $prompt['api_tools'][0]);
        $this->assertCount(2, $references);
        $this->assertNotSame($references[0]['source_ref'], $references[1]['source_ref']);
        $claim = $projector->resolveSourceIdentity($evidence, ['text' => 'ORDER-A contains SKU-1.', 'source_ref' => $references[0]['source_ref']]);
        $this->assertSame('orders.0', $claim['record_path']);
        $bound = $projector->bind($evidence, $claim, ['ORDER-A'], 'acme', 'orders');
        $this->assertSame('SKU-1', $bound['record']['articles'][0]['sku']);
        $this->assertSame('entity_mismatch', $projector->bind($evidence, $claim, ['ORDER-B'], 'acme', 'orders')['reason']);
        $conflicting = ['source_ref' => $references[0]['source_ref'], 'record_path' => 'orders.0.articles.0'];
        $this->assertSame($conflicting, $projector->resolveSourceIdentity($evidence, $conflicting));
    }

    public function test_empty_responses_offer_no_record_reference_and_stale_paths_do_not_resolve(): void
    {
        $projector = new EvidenceProjector;
        $evidence = $this->evidence();
        $reference = $projector->forPrompt($evidence)['api_tools'][0]['record_sources'][1]['source_ref'];
        $evidence['api_tools'][0]['result']['orders'] = [];
        $prompt = $projector->forPrompt($evidence);
        $this->assertSame([], $prompt['api_tools'][0]['record_sources']);
        $this->assertArrayNotHasKey('source_ref', $prompt['api_tools'][0]);
        $claim = ['source_ref' => $reference];
        $this->assertSame($claim, $projector->resolveSourceIdentity($evidence, $claim));
    }
}
