<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Mcp\Tools\KbDocumentBySlugTool;
use App\Mcp\Tools\KbDocumentsByTypeTool;
use App\Mcp\Tools\KbGraphNeighborsTool;
use App\Mcp\Tools\KbRecentChangesTool;
use App\Models\KbEdge;
use App\Models\KbNode;
use App\Models\KnowledgeDocument;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use Tests\TestCase;

/**
 * Security review v8.8 — proves the project-scoped / slug / type / graph MCP
 * tools scope every query to the MCP-resolved tenant, so a client bound to
 * tenant A cannot read tenant B's canonical documents or graph by passing a
 * shared `project_key` + `slug` / `node_uid`.
 *
 * Same posture as {@see KbReadToolsTenantScopeTest}: tests run unauthenticated
 * on purpose (AccessScopeScope bypasses on the null-user path), so the only
 * thing standing between the caller and tenant B's rows is the forTenant()
 * scope the fix added. If a scope regresses, the cross-tenant assertions below
 * flip from "absent" to a leak.
 *
 * Every query path exercised here is non-vector (slug/type/recency/graph
 * lookups), so the SQLite test schema (JSON vector columns, no pgvector) is
 * fine — no similarity SQL is touched.
 */
final class KbToolsProjectScopeTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $tenants;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenants = $this->app->make(TenantContext::class);
    }

    protected function tearDown(): void
    {
        $this->tenants->reset();
        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // KbDocumentBySlugTool — slug is unique per (tenant, project), NOT global
    // -----------------------------------------------------------------

    public function test_document_by_slug_denies_cross_tenant(): void
    {
        $this->seedCanonicalDoc('tenant-b', 'demo', 'dec-x', 'decision', 'Tenant B decision');

        // A client bound to tenant-a must NOT resolve tenant-b's shared slug.
        $this->tenants->set('tenant-a');

        $response = (new KbDocumentBySlugTool())->handle(
            new Request(['slug' => 'dec-x', 'project_key' => 'demo']),
        );

        // The tool returns a not_found JSON payload (it does NOT throw).
        $this->assertStringContainsString('not_found', $this->responseText($response));
    }

    public function test_document_by_slug_returns_own_tenant(): void
    {
        $doc = $this->seedCanonicalDoc('tenant-b', 'demo', 'dec-x', 'decision', 'Tenant B decision');

        $this->tenants->set('tenant-b');

        $response = (new KbDocumentBySlugTool())->handle(
            new Request(['slug' => 'dec-x', 'project_key' => 'demo']),
        );

        $text = $this->responseText($response);
        $this->assertStringNotContainsString('not_found', $text);
        $this->assertStringContainsString('dec-x', $text);
        $this->assertStringContainsString($doc->title, $text);
    }

    // -----------------------------------------------------------------
    // KbDocumentsByTypeTool — typed listing scoped per tenant
    // -----------------------------------------------------------------

    public function test_documents_by_type_excludes_cross_tenant(): void
    {
        $this->seedCanonicalDoc('tenant-b', 'demo', 'dec-typed', 'decision', 'Tenant B typed decision');

        $this->tenants->set('tenant-a');

        $response = (new KbDocumentsByTypeTool())->handle(
            new Request(['type' => 'decision', 'project_key' => 'demo']),
        );

        $text = $this->responseText($response);
        // tenant-a sees none of tenant-b's typed docs.
        $this->assertStringContainsString('"count":0', $text);
        $this->assertStringNotContainsString('dec-typed', $text);
    }

    public function test_documents_by_type_returns_own_tenant(): void
    {
        $this->seedCanonicalDoc('tenant-b', 'demo', 'dec-typed', 'decision', 'Tenant B typed decision');

        $this->tenants->set('tenant-b');

        $response = (new KbDocumentsByTypeTool())->handle(
            new Request(['type' => 'decision', 'project_key' => 'demo']),
        );

        $text = $this->responseText($response);
        $this->assertStringContainsString('dec-typed', $text);
    }

    // -----------------------------------------------------------------
    // KbRecentChangesTool — recent list scoped per tenant
    // -----------------------------------------------------------------

    public function test_recent_changes_excludes_cross_tenant(): void
    {
        $this->seedCanonicalDoc('tenant-b', 'demo', 'dec-recent', 'decision', 'Tenant B recent decision');

        $this->tenants->set('tenant-a');

        $response = (new KbRecentChangesTool())->handle(new Request(['limit' => 50]));

        $text = $this->responseText($response);
        // tenant-a's recent list must not surface tenant-b's document.
        $this->assertStringNotContainsString('dec-recent', $text);
        $this->assertStringNotContainsString('Tenant B recent decision', $text);
    }

    public function test_recent_changes_returns_own_tenant(): void
    {
        $this->seedCanonicalDoc('tenant-b', 'demo', 'dec-recent', 'decision', 'Tenant B recent decision');

        $this->tenants->set('tenant-b');

        $response = (new KbRecentChangesTool())->handle(new Request(['limit' => 50]));

        $this->assertStringContainsString('Tenant B recent decision', $this->responseText($response));
    }

    // -----------------------------------------------------------------
    // KbGraphNeighborsTool — graph is always tenant-scoped
    // -----------------------------------------------------------------

    public function test_graph_neighbors_denies_cross_tenant(): void
    {
        $this->seedGraph('tenant-b', 'demo');

        $this->tenants->set('tenant-a');

        $response = (new KbGraphNeighborsTool())->handle(
            new Request(['node_uid' => 'dec-shared', 'project_key' => 'demo']),
        );

        $text = $this->responseText($response);
        // tenant-a gets no neighbours for a node that only exists in tenant-b.
        $this->assertStringContainsString('"count":0', $text);
        $this->assertStringNotContainsString('mod-shared', $text);
    }

    public function test_graph_neighbors_returns_own_tenant(): void
    {
        $this->seedGraph('tenant-b', 'demo');

        $this->tenants->set('tenant-b');

        $response = (new KbGraphNeighborsTool())->handle(
            new Request(['node_uid' => 'dec-shared', 'project_key' => 'demo']),
        );

        $text = $this->responseText($response);
        $this->assertStringContainsString('mod-shared', $text);
        $this->assertStringNotContainsString('"count":0', $text);
    }

    // -----------------------------------------------------------------
    // seeding helpers — all set the active tenant BEFORE create so
    // BelongsToTenant auto-fills tenant_id (mirrors KbReadToolsTenantScopeTest).
    // -----------------------------------------------------------------

    private function seedCanonicalDoc(
        string $tenantId,
        string $projectKey,
        string $slug,
        string $type,
        string $title,
    ): KnowledgeDocument {
        static $counter = 0;
        $counter++;

        $this->tenants->set($tenantId);

        return KnowledgeDocument::create([
            'project_key' => $projectKey,
            'source_type' => 'markdown',
            'title' => $title,
            'source_path' => "{$type}s/{$slug}-{$tenantId}.md",
            'language' => 'en',
            'access_scope' => 'internal',
            'status' => 'active',
            'document_hash' => str_pad((string) $counter, 64, 'a'),
            'version_hash' => str_pad((string) $counter, 64, 'b'),
            'doc_id' => strtoupper(substr($type, 0, 3)).'-'.str_pad((string) $counter, 4, '0', STR_PAD_LEFT),
            'slug' => $slug,
            'canonical_type' => $type,
            'canonical_status' => 'accepted',
            'is_canonical' => true,
            'retrieval_priority' => 70,
            'indexed_at' => now(),
        ]);
    }

    /**
     * Seed a tenant-scoped 1-hop graph: dec-shared --decision_for--> mod-shared,
     * with both canonical docs present so the neighbour formatter has a node +
     * document to surface. Mirrors MultiTenantRetrievalIsolationTest::seedTwoTenantsWithSharedSlugs.
     */
    private function seedGraph(string $tenantId, string $projectKey): void
    {
        $decision = $this->seedCanonicalDoc($tenantId, $projectKey, 'dec-shared', 'decision', 'Shared decision');
        $module = $this->seedCanonicalDoc($tenantId, $projectKey, 'mod-shared', 'module-kb', 'Shared module');

        // Tenant context is still set to $tenantId from the last seed; the
        // nodes/edges below inherit it via BelongsToTenant.
        KbNode::create([
            'project_key' => $projectKey,
            'node_uid' => 'dec-shared',
            'node_type' => 'decision',
            'label' => 'Shared decision',
            'source_doc_id' => $decision->doc_id,
            'payload_json' => ['dangling' => false],
        ]);
        KbNode::create([
            'project_key' => $projectKey,
            'node_uid' => 'mod-shared',
            'node_type' => 'module',
            'label' => 'Shared module',
            'source_doc_id' => $module->doc_id,
            'payload_json' => ['dangling' => false],
        ]);
        KbEdge::create([
            'edge_uid' => 'dec-shared->mod-shared:decision_for',
            'from_node_uid' => 'dec-shared',
            'to_node_uid' => 'mod-shared',
            'edge_type' => 'decision_for',
            'project_key' => $projectKey,
            'source_doc_id' => $decision->doc_id,
            'weight' => 1.0,
            'provenance' => 'wikilink',
        ]);
    }

    private function responseText(mixed $response): string
    {
        return (string) $response->content();
    }
}
