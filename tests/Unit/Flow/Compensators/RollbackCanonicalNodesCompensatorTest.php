<?php

declare(strict_types=1);

namespace Tests\Unit\Flow\Compensators;

use App\Flow\Compensators\RollbackCanonicalNodesCompensator;
use App\Models\KbNode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\LaravelFlow\FlowContext;
use Padosoft\LaravelFlow\FlowStepResult;
use Tests\TestCase;

final class RollbackCanonicalNodesCompensatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_deletes_only_nodes_recorded_as_created_by_step(): void
    {
        // Pre-existing node — must NOT be touched by the compensator.
        $preExisting = KbNode::create([
            'project_key' => 'acme',
            'node_uid' => 'pre-existing',
            'node_type' => 'module',
            'label' => 'Existing',
            'source_doc_id' => 'PRE',
            'payload_json' => ['dangling' => false],
        ]);

        // Nodes "created by this run" — must be deleted.
        $created1 = KbNode::create([
            'project_key' => 'acme',
            'node_uid' => 'created-1',
            'node_type' => 'unknown',
            'label' => 'C1',
            'payload_json' => ['dangling' => true],
        ]);
        $created2 = KbNode::create([
            'project_key' => 'acme',
            'node_uid' => 'created-2',
            'node_type' => 'unknown',
            'label' => 'C2',
            'payload_json' => ['dangling' => true],
        ]);

        $compensator = $this->app->make(RollbackCanonicalNodesCompensator::class);
        $compensator->compensate(
            $this->context(),
            FlowStepResult::success(['created_node_ids' => [$created1->id, $created2->id]]),
        );

        $this->assertNotNull(KbNode::find($preExisting->id), 'pre-existing node must be preserved');
        $this->assertNull(KbNode::find($created1->id));
        $this->assertNull(KbNode::find($created2->id));
    }

    public function test_no_op_when_no_created_node_ids(): void
    {
        KbNode::create([
            'project_key' => 'acme',
            'node_uid' => 'x',
            'node_type' => 'module',
            'label' => 'X',
        ]);

        $compensator = $this->app->make(RollbackCanonicalNodesCompensator::class);
        $compensator->compensate($this->context(), FlowStepResult::success([]));

        $this->assertSame(1, KbNode::count());
    }

    public function test_idempotent_on_already_deleted_nodes(): void
    {
        $compensator = $this->app->make(RollbackCanonicalNodesCompensator::class);

        // No throw on already-missing IDs — just no-op.
        $compensator->compensate(
            $this->context(),
            FlowStepResult::success(['created_node_ids' => [99_999, 99_998]]),
        );

        $this->assertSame(0, KbNode::count());
    }

    /**
     * Iteration 4 (PR #116) — R30 cross-tenant defence. A tainted /
     * serialized created_node_ids list could otherwise contain node ids
     * that belong to ANOTHER tenant; the compensator MUST NOT delete
     * them when running under tenant A.
     */
    public function test_does_not_delete_nodes_belonging_to_another_tenant(): void
    {
        // Tenant A's created node — must be deleted.
        $tenantANode = KbNode::create([
            'tenant_id' => 'tenant-a',
            'project_key' => 'acme',
            'node_uid' => 'a-created-1',
            'node_type' => 'unknown',
            'label' => 'A1',
            'payload_json' => ['dangling' => true],
        ]);

        // Tenant B's node — leaked into the payload (e.g. attacker-
        // crafted serialised input). Must survive.
        $tenantBNode = KbNode::create([
            'tenant_id' => 'tenant-b',
            'project_key' => 'acme',
            'node_uid' => 'b-survivor',
            'node_type' => 'module',
            'label' => 'B-Survivor',
            'payload_json' => ['dangling' => false],
        ]);

        $compensator = $this->app->make(RollbackCanonicalNodesCompensator::class);
        $compensator->compensate(
            $this->contextForTenant('tenant-a'),
            FlowStepResult::success([
                // Tainted bag: includes both tenant A's id and tenant B's id.
                'created_node_ids' => [$tenantANode->id, $tenantBNode->id],
            ]),
        );

        $this->assertNull(KbNode::find($tenantANode->id), 'tenant-a node must be deleted');
        $this->assertNotNull(
            KbNode::find($tenantBNode->id),
            'tenant-b node MUST survive a tenant-a compensator run',
        );
    }

    private function context(): FlowContext
    {
        return $this->contextForTenant('default');
    }

    private function contextForTenant(string $tenantId): FlowContext
    {
        return new FlowContext(
            flowRunId: 'rb-test',
            definitionName: 'kb.canonical-index',
            input: ['tenant_id' => $tenantId],
            stepOutputs: [],
            dryRun: false,
        );
    }
}
