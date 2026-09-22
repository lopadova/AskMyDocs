<?php

declare(strict_types=1);

namespace Tests\Feature\Kb;

use App\Models\KbNode;
use App\Models\KnowledgeDocument;
use App\Services\Kb\DocumentDeleter;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * R30/R31 — proves {@see \App\Services\Kb\DocumentDeleter::cascadeGraphFor()}
 * scopes its `kb_nodes` delete by `tenant_id` so deleting a doc under
 * tenant A never cascade-deletes tenant B's graph nodes that share the
 * same `(project_key, slug/doc_id)` shape.
 *
 * Per CLAUDE.md R10 + R30: slug + doc_id are tenant-scoped, NOT global.
 * Two tenants may legitimately share `(project_key='demo', slug='dec-x')`.
 * On `knowledge_documents` the schema says so since 2026_10_02_000011;
 * `kb_nodes` still carries the v3-era global UNIQUE on
 * `(project_key, node_uid)` — its rebuild is the one deferred because
 * kb_edges' composite FK targets it — so this test drops that index and
 * the dependent FK to reach the scenario. The delete query MUST filter by
 * tenant_id regardless, so that rebuild cannot reintroduce the leak.
 */
final class DocumentDeleterCrossTenantCascadeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('kb');

        // `knowledge_documents` needs nothing here since 2026_10_02_000011:
        // its three composite uniques now start with `tenant_id`, so two
        // tenants holding the same `(project_key, slug)` is exactly what the
        // schema permits. `kb_nodes` still carries the v3-era global
        // `(project_key, node_uid)` unique — its rebuild is the one deferred
        // by 2026_05_26_000001 (the composite FK from kb_edges targets it) —
        // so that index is dropped here to reach the scenario, and the WHERE
        // filter in cascadeGraphFor() remains the line of defence for the
        // graph tables.
        //
        // SQLite's composite FK on kb_edges → kb_nodes points at the unique
        // we're about to drop. Since this scenario never touches kb_edges,
        // the cleanest fix is to drop the kb_edges table entirely so the
        // dependent FK can't fire a "foreign key mismatch" during the
        // cascadeGraphFor delete inside DB::transaction (where SQLite's
        // FK-check kicks in regardless of the connection-level PRAGMA).
        Schema::dropIfExists('kb_edges');
        Schema::table('kb_nodes', function ($table) {
            $table->dropUnique('uq_kb_nodes_project_uid');
        });
    }

    protected function tearDown(): void
    {
        $this->app->make(TenantContext::class)->reset();
        parent::tearDown();
    }

    public function test_force_delete_only_cascades_nodes_for_the_owning_tenant(): void
    {
        $tenantContext = app(TenantContext::class);

        // Tenant A — canonical doc + matching kb_node.
        $tenantContext->set('tenant-a');
        $docA = $this->seedCanonicalDoc('tenant-a', 'demo', 'dec-cache-v2', 'DEC-0001', 'a');
        $this->seedKbNode('tenant-a', 'demo', 'dec-cache-v2', 'DEC-0001', 'A label');

        // Tenant B — SAME (project_key, slug, doc_id), different content/version.
        $tenantContext->set('tenant-b');
        $docB = $this->seedCanonicalDoc('tenant-b', 'demo', 'dec-cache-v2', 'DEC-0001', 'b');
        $this->seedKbNode('tenant-b', 'demo', 'dec-cache-v2', 'DEC-0001', 'B label');

        $this->assertSame(2, KbNode::count(), 'Pre-delete: both tenants own a node.');

        // Force-delete tenant A's doc — must NOT touch tenant B's node.
        (new DocumentDeleter())->deleteDbOnly($docA);

        $this->assertSame(
            0,
            KbNode::where('tenant_id', 'tenant-a')->count(),
            "Tenant A's kb_nodes row must be removed by the cascade.",
        );
        $this->assertSame(
            1,
            KbNode::where('tenant_id', 'tenant-b')->count(),
            "Tenant B's kb_nodes row must NOT be removed by tenant A's delete (R30).",
        );

        $tenantBNode = KbNode::where('tenant_id', 'tenant-b')->first();
        $this->assertNotNull($tenantBNode);
        $this->assertSame('B label', $tenantBNode->label);

        // Tenant B's document also untouched.
        $this->assertNotNull(KnowledgeDocument::find($docB->id));
    }

    public function test_force_delete_via_slug_branch_also_tenant_scoped(): void
    {
        // Same as above but exercise the doc_id-null fallback branch in
        // cascadeGraphFor (matches by node_uid = slug).
        $tenantContext = app(TenantContext::class);

        $tenantContext->set('tenant-a');
        $docA = $this->seedCanonicalDoc('tenant-a', 'demo', 'no-id-slug', null, 'a');
        $this->seedKbNode('tenant-a', 'demo', 'no-id-slug', null, 'A label');

        $tenantContext->set('tenant-b');
        $this->seedCanonicalDoc('tenant-b', 'demo', 'no-id-slug', null, 'b');
        $this->seedKbNode('tenant-b', 'demo', 'no-id-slug', null, 'B label');

        (new DocumentDeleter())->deleteDbOnly($docA);

        $this->assertSame(0, KbNode::where('tenant_id', 'tenant-a')->count());
        $this->assertSame(1, KbNode::where('tenant_id', 'tenant-b')->count());
    }

    private function seedCanonicalDoc(
        string $tenantId,
        string $projectKey,
        string $slug,
        ?string $docId,
        string $hashSeed,
    ): KnowledgeDocument {
        // Insert via the model so the returned instance is hydrated, but
        // pass tenant_id explicitly so the BelongsToTenant auto-fill picks
        // up exactly the tenant we want regardless of the singleton's
        // current value.
        return KnowledgeDocument::create([
            'tenant_id' => $tenantId,
            'project_key' => $projectKey,
            'source_type' => 'markdown',
            'title' => "Doc {$slug} {$hashSeed}",
            'source_path' => "decisions/{$slug}-{$hashSeed}.md",
            'language' => 'en',
            'access_scope' => 'internal',
            'status' => 'active',
            'document_hash' => str_pad($hashSeed, 64, $hashSeed[0]),
            'version_hash' => str_pad($hashSeed, 64, $hashSeed[0]),
            'doc_id' => $docId,
            'slug' => $slug,
            'canonical_type' => 'decision',
            'canonical_status' => 'accepted',
            'is_canonical' => true,
            'retrieval_priority' => 70,
        ]);
    }

    private function seedKbNode(
        string $tenantId,
        string $projectKey,
        string $slug,
        ?string $docId,
        string $label,
    ): void {
        // Insert via DB facade to bypass BelongsToTenant's auto-fill on
        // the model and to stay independent of mass-assignment config.
        DB::table('kb_nodes')->insert([
            'tenant_id' => $tenantId,
            'node_uid' => $slug,
            'node_type' => 'decision',
            'label' => $label,
            'project_key' => $projectKey,
            'source_doc_id' => $docId,
            'payload_json' => json_encode(['dangling' => false]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
