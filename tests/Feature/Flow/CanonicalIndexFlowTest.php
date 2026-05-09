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
