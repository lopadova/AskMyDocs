<?php

declare(strict_types=1);

namespace Tests\Unit\Flow\Steps\Graph;

use App\Flow\Steps\Graph\TruncateGraphScopeStep;
use App\Models\KbEdge;
use App\Models\KbNode;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\LaravelFlow\Exceptions\FlowInputException;
use Padosoft\LaravelFlow\FlowContext;
use Tests\TestCase;

final class TruncateGraphScopeStepTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $this->app->make(TenantContext::class)->reset();
        parent::tearDown();
    }

    public function test_truncates_nodes_and_edges_for_tenant(): void
    {
        $this->seedNode('tenant-a', 'p', 'n1');
        $this->seedNode('tenant-a', 'p', 'n2');
        $this->seedEdge('tenant-a', 'p', 'n1', 'n2');

        $step = $this->app->make(TruncateGraphScopeStep::class);
        $result = $step->execute($this->context('tenant-a'));

        $this->assertTrue($result->success);
        $this->assertTrue($result->output['truncated']);
        $this->assertSame(2, $result->output['nodes_deleted']);
        $this->assertSame(1, $result->output['edges_deleted']);
        $this->assertSame(0, KbNode::count());
        $this->assertSame(0, KbEdge::count());
    }

    public function test_no_op_when_truncate_false(): void
    {
        $this->seedNode('tenant-a', 'p', 'n1');
        $step = $this->app->make(TruncateGraphScopeStep::class);

        $result = $step->execute($this->context('tenant-a', truncate: false));

        $this->assertFalse($result->output['truncated']);
        $this->assertSame(1, KbNode::count());
    }

    public function test_dry_run_skipped(): void
    {
        $this->seedNode('tenant-a', 'p', 'n1');
        $step = $this->app->make(TruncateGraphScopeStep::class);

        $result = $step->execute($this->context('tenant-a', dryRun: true));

        $this->assertTrue($result->dryRunSkipped);
        $this->assertSame(1, KbNode::count());
    }

    public function test_throws_on_missing_tenant_id(): void
    {
        $step = $this->app->make(TruncateGraphScopeStep::class);
        $context = new FlowContext(
            flowRunId: 'r',
            definitionName: 'kb.rebuild-graph',
            input: ['truncate' => true],
        );

        $this->expectException(FlowInputException::class);
        $step->execute($context);
    }

    public function test_tenant_isolation_does_not_truncate_other_tenants_graph(): void
    {
        // Two tenants with their own project — the SQLite test schema's
        // unique constraint is (project_key, node_uid) so each tenant
        // owns its own project for the isolation guard to be expressible.
        // Production Postgres has the composite UNIQUE on
        // (tenant_id, project_key, node_uid) so the same project_key/node_uid
        // tuple can legitimately coexist across tenants there.
        $this->seedNode('tenant-a', 'pa', 'n1');
        $this->seedNode('tenant-b', 'pb', 'n1');

        $step = $this->app->make(TruncateGraphScopeStep::class);
        $step->execute($this->context('tenant-a'));

        $this->assertSame(0, KbNode::where('tenant_id', 'tenant-a')->count());
        $this->assertSame(1, KbNode::where('tenant_id', 'tenant-b')->count());
    }

    public function test_project_filter_scopes_truncation(): void
    {
        $this->seedNode('tenant-a', 'p1', 'n1');
        $this->seedNode('tenant-a', 'p2', 'n2');

        $step = $this->app->make(TruncateGraphScopeStep::class);
        $step->execute($this->context('tenant-a', projectKey: 'p1'));

        $this->assertSame(0, KbNode::where('project_key', 'p1')->count());
        $this->assertSame(1, KbNode::where('project_key', 'p2')->count());
    }

    private function context(
        string $tenantId,
        string $projectKey = '',
        bool $truncate = true,
        bool $dryRun = false,
    ): FlowContext {
        return new FlowContext(
            flowRunId: 'r',
            definitionName: 'kb.rebuild-graph',
            input: [
                'tenant_id' => $tenantId,
                'project_key' => $projectKey,
                'truncate' => $truncate,
            ],
            dryRun: $dryRun,
        );
    }

    private function seedNode(string $tenantId, string $projectKey, string $nodeUid): void
    {
        $tc = $this->app->make(TenantContext::class);
        $tc->set($tenantId);
        KbNode::create([
            'project_key' => $projectKey,
            'node_uid' => $nodeUid,
            'node_type' => 'decision',
            'label' => $nodeUid,
            'source_doc_id' => $nodeUid,
            'payload_json' => ['dangling' => false],
        ]);
    }

    private function seedEdge(string $tenantId, string $projectKey, string $from, string $to): void
    {
        $tc = $this->app->make(TenantContext::class);
        $tc->set($tenantId);
        KbEdge::create([
            'project_key' => $projectKey,
            'edge_uid' => "{$from}-related_to-{$to}",
            'from_node_uid' => $from,
            'to_node_uid' => $to,
            'edge_type' => 'related_to',
            'source_doc_id' => $from,
            'weight' => 1.0,
            'provenance' => 'inferred',
            'payload_json' => [],
        ]);
    }
}
