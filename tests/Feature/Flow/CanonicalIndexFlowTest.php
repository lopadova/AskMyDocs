<?php

declare(strict_types=1);

namespace Tests\Feature\Flow;

use App\Flow\Definitions\CanonicalIndexFlow;
use App\Models\KbCanonicalAudit;
use App\Models\KbEdge;
use App\Models\KbNode;
use App\Models\KnowledgeDocument;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Padosoft\LaravelFlow\Facades\Flow;
use Padosoft\LaravelFlow\FlowExecutionOptions;
use Padosoft\LaravelFlow\FlowRun;
use Tests\TestCase;

/**
 * End-to-end coverage for the {@see CanonicalIndexFlow} saga: 3 steps +
 * 1 compensator on `populate-nodes`. Asserts engine wires the steps,
 * stamps tenant_id on every persisted Flow row, builds the graph
 * correctly under tenant isolation, and writes the `graph_rebuild`
 * editorial audit row.
 */
final class CanonicalIndexFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $this->app->make(TenantContext::class)->reset();
        parent::tearDown();
    }

    public function test_happy_path_populates_self_node_targets_and_edges(): void
    {
        $doc = $this->seedCanonicalDoc('default', 'acme', 'dec-x', [
            'related_slugs' => ['mod-cache'],
            'supersedes_slugs' => ['dec-prev'],
            'superseded_by_slugs' => [],
        ]);

        $run = Flow::execute(
            CanonicalIndexFlow::NAME,
            ['tenant_id' => 'default', 'document_id' => $doc->id],
            FlowExecutionOptions::make(correlationId: 'default'),
        );

        $this->assertSame(FlowRun::STATUS_SUCCEEDED, $run->status);

        // Self-node + 2 dangling targets.
        $this->assertSame(3, KbNode::count());
        $this->assertNotNull(KbNode::where('node_uid', 'dec-x')->first());
        $this->assertNotNull(KbNode::where('node_uid', 'mod-cache')->first());
        $this->assertNotNull(KbNode::where('node_uid', 'dec-prev')->first());

        // 2 outgoing edges (related_to + supersedes).
        $this->assertSame(2, KbEdge::where('from_node_uid', 'dec-x')->count());

        // graph_rebuild audit row.
        $this->assertDatabaseHas('kb_canonical_audit', [
            'project_key' => 'acme',
            'doc_id' => 'DEC-0001',
            'slug' => 'dec-x',
            'event_type' => 'graph_rebuild',
        ]);

        // Persisted Flow rows carry the tenant.
        $runRow = DB::table('flow_runs')->where('id', $run->id)->first();
        $this->assertNotNull($runRow);
        $this->assertSame('default', $runRow->tenant_id);
        $this->assertSame('succeeded', $runRow->status);
    }

    public function test_short_circuits_on_non_canonical_doc_without_writing_graph(): void
    {
        $doc = $this->seedNonCanonicalDoc('default', 'acme');

        $run = Flow::execute(
            CanonicalIndexFlow::NAME,
            ['tenant_id' => 'default', 'document_id' => $doc->id],
            FlowExecutionOptions::make(correlationId: 'default'),
        );

        $this->assertSame(FlowRun::STATUS_SUCCEEDED, $run->status);
        $this->assertSame(0, KbNode::count());
        $this->assertSame(0, KbEdge::count());
        $this->assertSame(0, KbCanonicalAudit::count());
    }

    public function test_r30_cross_tenant_read_returns_document_not_found_short_circuit(): void
    {
        // R30 — every read inside the saga steps applies forTenant() to
        // the input tenant_id. A doc that physically exists under
        // tenant-a must NOT resolve when the flow runs with
        // tenant_id='tenant-b'; the load step short-circuits with
        // `document_not_found` (NOT a tenant-b row that happens to share
        // the same numeric id, NOT tenant-a's row leaking through).
        $tenants = $this->app->make(TenantContext::class);

        $tenants->set('tenant-a');
        $docA = $this->seedCanonicalDoc('tenant-a', 'project-a', 'dec-only-a', [
            'related_slugs' => ['mod-a-shared'],
            'supersedes_slugs' => [],
            'superseded_by_slugs' => [],
        ]);

        // Run the flow under tenant-b's identity but pass tenant-a's
        // numeric document_id. forTenant() must hide the row.
        $tenants->set('tenant-b');
        $run = Flow::execute(
            CanonicalIndexFlow::NAME,
            ['tenant_id' => 'tenant-b', 'document_id' => $docA->id],
            FlowExecutionOptions::make(correlationId: 'tenant-b'),
        );

        $this->assertSame(FlowRun::STATUS_SUCCEEDED, $run->status);
        $loadOutput = $run->stepResults['load-document']->output ?? [];
        $this->assertFalse($loadOutput['indexable'] ?? true);
        $this->assertSame('document_not_found', $loadOutput['reason'] ?? null);

        // Tenant-a's graph must remain untouched (no nodes, no edges,
        // no audit) — the saga short-circuited before any write.
        $this->assertSame(0, KbNode::count());
        $this->assertSame(0, KbEdge::count());
        $this->assertSame(0, KbCanonicalAudit::count());
    }

    public function test_tenant_isolation_two_tenants_yield_distinct_node_rows(): void
    {
        // Each tenant uses its own slug so the schema-level UNIQUE on
        // (project_key, slug) is honoured. The point of this test is the
        // node row's tenant_id stamping, not the slug-uniqueness scope.
        $tenants = $this->app->make(TenantContext::class);

        $tenants->set('tenant-a');
        $docA = $this->seedCanonicalDoc('tenant-a', 'project-a', 'dec-a', [
            'related_slugs' => ['mod-a-shared'],
            'supersedes_slugs' => [],
            'superseded_by_slugs' => [],
        ]);
        Flow::execute(
            CanonicalIndexFlow::NAME,
            ['tenant_id' => 'tenant-a', 'document_id' => $docA->id],
            FlowExecutionOptions::make(correlationId: 'tenant-a'),
        );

        $tenants->set('tenant-b');
        $docB = $this->seedCanonicalDoc('tenant-b', 'project-b', 'dec-b', [
            'related_slugs' => ['mod-b-shared'],
            'supersedes_slugs' => [],
            'superseded_by_slugs' => [],
        ]);
        Flow::execute(
            CanonicalIndexFlow::NAME,
            ['tenant_id' => 'tenant-b', 'document_id' => $docB->id],
            FlowExecutionOptions::make(correlationId: 'tenant-b'),
        );

        // Each tenant gets its own self-node + dangling target nodes.
        $aNodes = KbNode::where('tenant_id', 'tenant-a')->count();
        $bNodes = KbNode::where('tenant_id', 'tenant-b')->count();
        $this->assertSame(2, $aNodes);
        $this->assertSame(2, $bNodes);

        // Each tenant's audit row carries the correct tenant_id.
        $this->assertSame(1, KbCanonicalAudit::where('tenant_id', 'tenant-a')->count());
        $this->assertSame(1, KbCanonicalAudit::where('tenant_id', 'tenant-b')->count());
    }

    public function test_r30_cross_tenant_edge_updateorcreate_does_not_silently_overwrite(): void
    {
        // Iteration 3 — Copilot flagged that PopulateCanonicalEdgesStep::createEdge()
        // called KbEdge::updateOrCreate() without a tenant scope. The
        // bare updateOrCreate would MATCH another tenant's row by
        // (project_key, edge_uid) and silently UPDATE its from/to/source/
        // tenant_id columns — clean cross-tenant data corruption.
        //
        // The fix scopes the lookup via forTenant() AND mirrors tenant_id
        // in the values array. This test asserts the lookup phase: with
        // forTenant() applied, an edge owned by tenant-a is INVISIBLE to
        // a tenant-b query, so the updateOrCreate falls through to INSERT.
        //
        // The DB-level UNIQUE on (project_key, edge_uid) is currently
        // global (not tenant-scoped) so the INSERT then surfaces a
        // unique-constraint violation — which is the CORRECT, LOUD
        // outcome (vs. silent overwrite). Surfacing the conflict lets
        // operators investigate whether the cross-tenant collision is
        // intentional. The previous behaviour (silent overwrite) was
        // strictly worse: tenant-a's edge would simply vanish into
        // tenant-b without any signal.
        //
        // We assert tenant-a's row is structurally untouched after the
        // failed tenant-b write attempt.
        $tenants = $this->app->make(TenantContext::class);

        // Seed the FK-required nodes (kb_edges has composite FK on
        // (project_key, from/to_node_uid) → kb_nodes(project_key, node_uid)).
        // The unique on (project_key, node_uid) is global in the test
        // schema so only one tenant can hold each node — that's fine for
        // this test, we don't seed nodes for tenant-b.
        KbNode::create([
            'tenant_id' => 'tenant-a',
            'node_uid' => 'dec-tenant-a',
            'node_type' => 'decision',
            'label' => 'dec-tenant-a',
            'project_key' => 'shared-project',
            'source_doc_id' => null,
            'payload_json' => null,
        ]);
        KbNode::create([
            'tenant_id' => 'tenant-a',
            'node_uid' => 'mod-target-a',
            'node_type' => 'module',
            'label' => 'mod-target-a',
            'project_key' => 'shared-project',
            'source_doc_id' => null,
            'payload_json' => null,
        ]);

        // Seed tenant-a's edge — passes the FK because both nodes exist.
        KbEdge::create([
            'tenant_id' => 'tenant-a',
            'edge_uid' => 'dec-tenant-a->mod-target-a:related_to',
            'project_key' => 'shared-project',
            'from_node_uid' => 'dec-tenant-a',
            'to_node_uid' => 'mod-target-a',
            'edge_type' => 'related_to',
            'source_doc_id' => null,
            'weight' => 1.0,
            'provenance' => 'manual-seed-tenant-a',
            'payload_json' => null,
        ]);

        // Run the same updateOrCreate the step uses, scoped to tenant-b.
        // With forTenant() applied, no row matches → INSERT, which then
        // throws on the global (project_key, edge_uid) unique.
        // Without the fix, the call would silently UPDATE tenant-a's row.
        $tenants->set('tenant-b');
        $exceptionThrown = false;
        try {
            KbEdge::query()
                ->forTenant('tenant-b')
                ->updateOrCreate(
                    [
                        'project_key' => 'shared-project',
                        'edge_uid' => 'dec-tenant-a->mod-target-a:related_to',
                    ],
                    [
                        'tenant_id' => 'tenant-b',
                        'from_node_uid' => 'dec-tenant-a',
                        'to_node_uid' => 'mod-target-a',
                        'edge_type' => 'related_to',
                        'source_doc_id' => null,
                        'weight' => 1.0,
                        'provenance' => 'frontmatter_related',
                        'payload_json' => null,
                    ]
                );
        } catch (\Illuminate\Database\QueryException $e) {
            // Either UNIQUE on (project_key, edge_uid) or FK on
            // (project_key, from/to_node_uid) — both prove the lookup
            // was scoped to tenant-b (not silently UPDATE-ing tenant-a's
            // row).
            $exceptionThrown = true;
        }
        $this->assertTrue(
            $exceptionThrown,
            'forTenant() must hide tenant-a row → INSERT attempt → constraint violation (loud, not silent overwrite)'
        );

        // Tenant-a's edge is structurally untouched — provenance still
        // 'manual-seed-tenant-a' and tenant_id still 'tenant-a'.
        $aEdge = KbEdge::where('tenant_id', 'tenant-a')
            ->where('project_key', 'shared-project')
            ->where('edge_uid', 'dec-tenant-a->mod-target-a:related_to')
            ->first();
        $this->assertNotNull($aEdge, 'tenant-a edge must survive');
        $this->assertSame('manual-seed-tenant-a', (string) $aEdge->provenance);
        $this->assertSame('tenant-a', (string) $aEdge->tenant_id);
    }

    /**
     * @param  array<string, list<string>>  $derived
     */
    private function seedCanonicalDoc(string $tenantId, string $projectKey, string $slug, array $derived): KnowledgeDocument
    {
        $tenants = $this->app->make(TenantContext::class);
        $tenants->set($tenantId);
        return KnowledgeDocument::create([
            'project_key' => $projectKey,
            'source_type' => 'markdown',
            'title' => $slug,
            'source_path' => "decisions/{$slug}.md",
            'mime_type' => 'text/markdown',
            'language' => 'en',
            'access_scope' => 'public',
            'status' => 'active',
            'document_hash' => hash('sha256', $tenantId.$projectKey.$slug.'doc'),
            'version_hash' => hash('sha256', $tenantId.$projectKey.$slug.'ver'),
            'metadata' => null,
            'doc_id' => 'DEC-0001',
            'slug' => $slug,
            'canonical_type' => 'decision',
            'canonical_status' => 'accepted',
            'is_canonical' => true,
            'retrieval_priority' => 80,
            'frontmatter_json' => ['_derived' => $derived],
        ]);
    }

    private function seedNonCanonicalDoc(string $tenantId, string $projectKey): KnowledgeDocument
    {
        $tenants = $this->app->make(TenantContext::class);
        $tenants->set($tenantId);
        return KnowledgeDocument::create([
            'project_key' => $projectKey,
            'source_type' => 'markdown',
            'title' => 'Plain',
            'source_path' => 'docs/plain.md',
            'mime_type' => 'text/markdown',
            'language' => 'en',
            'access_scope' => 'public',
            'status' => 'active',
            'document_hash' => str_repeat('a', 64),
            'version_hash' => str_repeat('b', 64),
            'metadata' => null,
            'is_canonical' => false,
        ]);
    }
}
