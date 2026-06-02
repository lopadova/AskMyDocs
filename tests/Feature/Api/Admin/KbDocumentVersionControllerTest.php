<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Admin;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * v8.7/W5 — Cloud Time Machine: version timeline + diff + restore.
 */
final class KbDocumentVersionControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->group(__DIR__.'/../../../../routes/api.php');
    }

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->reset();
        $this->seed(RbacSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function makeVersion(
        string $hashSeed,
        string $status,
        string $body,
        bool $canonical = false,
    ): KnowledgeDocument {
        $doc = KnowledgeDocument::create(array_merge([
            'project_key' => 'eng',
            'source_path' => 'docs/dec.md',
            'source_type' => 'markdown',
            'title' => 'Decision',
            'mime_type' => 'text/markdown',
            'language' => 'it',
            'access_scope' => 'internal',
            'status' => $status,
            'document_hash' => hash('sha256', $hashSeed),
            'version_hash' => hash('sha256', $hashSeed),
            'metadata' => [],
            'indexed_at' => now()->subMinutes(strlen($hashSeed)),
        ], $canonical ? [
            'is_canonical' => true,
            'doc_id' => 'dec-1',
            'slug' => 'dec-1',
            'canonical_type' => 'decision',
            'canonical_status' => 'accepted',
            'retrieval_priority' => 80,
        ] : []));

        KnowledgeChunk::create([
            'knowledge_document_id' => $doc->id,
            'project_key' => 'eng',
            'chunk_order' => 0,
            'chunk_hash' => hash('sha256', $hashSeed.'chunk'),
            'heading_path' => 'Decision',
            'chunk_text' => $body,
            'metadata' => [],
        ]);

        return $doc;
    }

    public function test_index_lists_the_version_timeline(): void
    {
        $admin = $this->makeAdmin();
        $v1 = $this->makeVersion('v1aaa', 'archived', 'old body');
        $live = $this->makeVersion('v2bbb', 'active', 'new body', canonical: true);

        $resp = $this->actingAs($admin)->getJson("/api/admin/kb/documents/{$live->id}/versions");

        $resp->assertOk()->assertJsonCount(2, 'data');
        // Newest (live) first.
        $this->assertSame($live->id, $resp->json('data.0.id'));
        $this->assertTrue($resp->json('data.0.is_live'));
        $this->assertFalse($resp->json('data.1.is_live'));
        $this->assertSame($v1->id, $resp->json('data.1.id'));
    }

    public function test_diff_reports_added_and_removed_lines(): void
    {
        $admin = $this->makeAdmin();
        $v1 = $this->makeVersion('v1aaa', 'archived', "line a\nline b");
        $live = $this->makeVersion('v2bbb', 'active', "line a\nline c");

        $resp = $this->actingAs($admin)
            ->getJson("/api/admin/kb/documents/{$live->id}/versions/diff?from={$v1->id}&to={$live->id}");

        $resp->assertOk()
            ->assertJsonPath('data.added', 1)
            ->assertJsonPath('data.removed', 1);
    }

    public function test_restore_makes_an_archived_version_live_and_transfers_canonical_identity(): void
    {
        $admin = $this->makeAdmin();
        // The archived version is the "older canonical" — its identity was
        // vacated when archived, so it currently has no slug.
        $archived = $this->makeVersion('v1aaa', 'archived', 'old body');
        $live = $this->makeVersion('v2bbb', 'active', 'new body', canonical: true);

        $this->actingAs($admin)->postJson("/api/admin/kb/documents/{$archived->id}/restore-version")
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.is_canonical', true)
            ->assertJsonPath('data.slug', 'dec-1');

        $archived->refresh();
        $live->refresh();
        $this->assertSame('active', $archived->status);
        $this->assertSame('dec-1', $archived->slug);
        $this->assertTrue((bool) $archived->is_canonical);
        // The outgoing live version is archived + its identity vacated.
        $this->assertSame('archived', $live->status);
        $this->assertNull($live->slug);
        $this->assertFalse((bool) $live->is_canonical);
    }

    /**
     * R21 — concurrent-restore sweep.
     *
     * Simulates the PostgreSQL EvalPlanQual scenario where a second version
     * was activated by a concurrent restore while *this* restore was waiting
     * on the lock. The $live SELECT FOR UPDATE returns null in that case
     * (formerly-active row is now archived), so without the sweep the
     * concurrent version stays active alongside our just-activated version.
     *
     * SQLite does not support FOR UPDATE, so we cannot exercise the lock
     * path directly. Instead we replicate the post-race DB state by hand
     * (two active rows) and assert that restore() leaves exactly one active.
     */
    public function test_restore_sweep_archives_concurrently_activated_version(): void
    {
        $admin = $this->makeAdmin();

        // v1 — the target we are about to restore.
        $v1 = $this->makeVersion('v1ccc', 'archived', 'old body');

        // v2 — was the live version before a concurrent restore activated v3.
        // In the race scenario it is already archived before we reach the
        // $live lockForUpdate, so the $live query returns null.
        $this->makeVersion('v2ddd', 'archived', 'mid body');

        // v3 — activated by the concurrent thread; its status is 'active' now.
        // This is what the sweep must archive to restore the one-active
        // invariant.
        $concurrentlyActive = $this->makeVersion('v3eee', 'active', 'concurrent body');

        $this->actingAs($admin)
            ->postJson("/api/admin/kb/documents/{$v1->id}/restore-version")
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $v1->refresh();
        $concurrentlyActive->refresh();

        $this->assertSame('active', $v1->status, 'Target version must be active after restore');
        $this->assertSame('archived', $concurrentlyActive->status, 'Concurrently-activated version must be swept to archived');

        // Exactly one active version in the family.
        $activeCount = KnowledgeDocument::query()
            ->where('project_key', 'eng')
            ->where('source_path', 'docs/dec.md')
            ->where('status', 'active')
            ->count();
        $this->assertSame(1, $activeCount, 'Exactly one active version must exist after sweep');
    }

    public function test_restoring_the_live_version_is_422(): void
    {
        $admin = $this->makeAdmin();
        $live = $this->makeVersion('v2bbb', 'active', 'body', canonical: true);

        $this->actingAs($admin)->postJson("/api/admin/kb/documents/{$live->id}/restore-version")
            ->assertStatus(422);
    }

    public function test_non_admin_gets_403(): void
    {
        $viewer = User::create([
            'name' => 'Viewer', 'email' => 'v-'.uniqid().'@demo.local', 'password' => Hash::make('secret'),
        ]);
        $viewer->assignRole('viewer');
        $live = $this->makeVersion('v2bbb', 'active', 'body');

        $this->actingAs($viewer)->getJson("/api/admin/kb/documents/{$live->id}/versions")->assertStatus(403);
    }

    public function test_guest_gets_401(): void
    {
        $this->getJson('/api/admin/kb/documents/1/versions')->assertStatus(401);
    }

    private function makeAdmin(): User
    {
        $admin = User::create([
            'name' => 'Admin', 'email' => 'admin-'.uniqid().'@demo.local', 'password' => Hash::make('secret123'),
        ]);
        $admin->assignRole('admin');

        return $admin;
    }
}
