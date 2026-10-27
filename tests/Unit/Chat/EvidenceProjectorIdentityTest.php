<?php

declare(strict_types=1);

namespace Tests\Unit\Chat;

use App\Services\Chat\Reasoning\EvidenceProjector;
use PHPUnit\Framework\TestCase;

final class EvidenceProjectorIdentityTest extends TestCase
{
    private function evidence(): array
    {
        return ['api_tools' => [['execution_id' => 42, 'evidence_hash' => 'hash', 'tenant_id' => 'acme', 'project_key' => 'orders',
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
}
