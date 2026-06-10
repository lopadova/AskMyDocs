<?php

namespace Tests\Feature\Api\Admin\Kb;

use App\Jobs\AnalyzeDocumentDeletionJob;
use App\Models\KbCanonicalAudit;
use App\Models\KnowledgeDocument;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Bulk KB document endpoints (explorer multi-select toolbar):
 * POST bulk-delete / POST bulk-restore.
 *
 * Mirrors the KbDocumentControllerTest harness (defineRoutes mount,
 * RbacSeeder, Cache::flush, Storage::fake('kb')). Every scenario hits
 * the real DB + real fake disk.
 *
 * RBAC note (R32): both endpoints are POST-only, and the
 * AdminAuthorizationMatrixTest can only probe GET routes (it issues
 * getJson; a POST-only URI would 405 during routing). The explicit
 * per-role 403 + guest 401 scenarios at the bottom of this file are
 * the compensating coverage — do not remove them.
 */
class KbBulkControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->group(__DIR__.'/../../../../../routes/api.php');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Cache::flush();

        config()->set('kb.sources.disk', 'kb');
        config()->set('kb.sources.path_prefix', '');
        Storage::fake('kb');
    }

    // ------------------------------------------------------------------
    // bulk-delete — soft (default)
    // ------------------------------------------------------------------

    public function test_bulk_delete_soft_deletes_by_default_and_keeps_files(): void
    {
        $admin = $this->makeAdmin();
        $a = $this->makeDoc('hr-portal', 'policies/a.md', canonical: true, slug: 'a');
        $b = $this->makeDoc('hr-portal', 'policies/b.md', canonical: false, slug: null);
        Storage::disk('kb')->put('policies/a.md', "# A\n");
        Storage::disk('kb')->put('policies/b.md', "# B\n");

        $response = $this->actingAs($admin)
            ->postJson('/api/admin/kb/documents/bulk-delete', ['ids' => [$a->id, $b->id]])
            ->assertOk();

        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('mode', 'soft');
        $response->assertJsonPath('summary.requested', 2);
        $response->assertJsonPath('summary.deleted', 2);
        $response->assertJsonPath('summary.failed', 0);
        $response->assertJsonPath('results.0.status', 'deleted');
        $response->assertJsonPath('results.0.mode', 'soft');
        $response->assertJsonPath('results.0.file_deleted', false);

        $this->assertSoftDeleted('knowledge_documents', ['id' => $a->id]);
        $this->assertSoftDeleted('knowledge_documents', ['id' => $b->id]);
        // Soft delete never touches the disk (R2 — retention reversibility).
        $this->assertTrue(Storage::disk('kb')->exists('policies/a.md'));
        $this->assertTrue(Storage::disk('kb')->exists('policies/b.md'));
    }

    public function test_bulk_delete_reports_already_trashed_for_soft_on_trashed_row(): void
    {
        $admin = $this->makeAdmin();
        $doc = $this->makeDoc('hr-portal', 'policies/gone.md', canonical: true, slug: 'gone');
        $doc->delete();
        $deletedAt = $doc->fresh()?->deleted_at;

        $response = $this->actingAs($admin)
            ->postJson('/api/admin/kb/documents/bulk-delete', ['ids' => [$doc->id]])
            ->assertOk();

        $response->assertJsonPath('results.0.status', 'already_trashed');
        $response->assertJsonPath('summary.already_trashed', 1);
        $response->assertJsonPath('summary.deleted', 0);

        // The original deleted_at must survive — no double-delete restamp.
        $this->assertEquals($deletedAt, KnowledgeDocument::withTrashed()->find($doc->id)?->deleted_at);
    }

    public function test_bulk_delete_skips_impact_analysis(): void
    {
        Queue::fake();
        $admin = $this->makeAdmin();
        $doc = $this->makeDoc('hr-portal', 'policies/quiet.md', canonical: true, slug: 'quiet');

        $this->actingAs($admin)
            ->postJson('/api/admin/kb/documents/bulk-delete', ['ids' => [$doc->id]])
            ->assertOk();

        // Bulk sweeps never opt into the obsolescence-impact LLM analysis
        // (DocumentDeleter contract) — proven, not assumed.
        Queue::assertNotPushed(AnalyzeDocumentDeletionJob::class);
    }

    // ------------------------------------------------------------------
    // bulk-delete — force
    // ------------------------------------------------------------------

    public function test_bulk_delete_force_hard_deletes_removes_files_and_audits(): void
    {
        $admin = $this->makeAdmin();
        $doc = $this->makeDoc('hr-portal', 'policies/hard.md', canonical: true, slug: 'hard');
        $doc->metadata = ['disk' => 'kb', 'prefix' => ''];
        $doc->save();
        Storage::disk('kb')->put('policies/hard.md', "# Body\n");

        $response = $this->actingAs($admin)
            ->postJson('/api/admin/kb/documents/bulk-delete', ['ids' => [$doc->id], 'force' => true])
            ->assertOk();

        $response->assertJsonPath('mode', 'hard');
        $response->assertJsonPath('results.0.status', 'deleted');
        $response->assertJsonPath('results.0.mode', 'hard');
        $response->assertJsonPath('results.0.file_deleted', true);

        $this->assertNull(KnowledgeDocument::withTrashed()->find($doc->id));
        Storage::disk('kb')->assertMissing('policies/hard.md');

        // Hard delete writes the immutable forensic trail (R10).
        $this->assertDatabaseHas('kb_canonical_audit', [
            'project_key' => 'hr-portal',
            'slug' => 'hard',
            'event_type' => 'deprecated',
        ]);
    }

    public function test_bulk_delete_force_promotes_already_trashed_row(): void
    {
        $admin = $this->makeAdmin();
        $doc = $this->makeDoc('hr-portal', 'policies/promote.md', canonical: true, slug: 'promote');
        $doc->delete();

        $response = $this->actingAs($admin)
            ->postJson('/api/admin/kb/documents/bulk-delete', ['ids' => [$doc->id], 'force' => true])
            ->assertOk();

        $response->assertJsonPath('results.0.status', 'deleted');
        $response->assertJsonPath('results.0.mode', 'hard');
        $this->assertNull(KnowledgeDocument::withTrashed()->find($doc->id));
    }

    // ------------------------------------------------------------------
    // bulk-delete — tenant isolation + misses
    // ------------------------------------------------------------------

    public function test_bulk_delete_reports_cross_tenant_id_as_not_found_and_leaves_it_untouched(): void
    {
        $admin = $this->makeAdmin();
        $mine = $this->makeDoc('hr-portal', 'policies/mine.md', canonical: true, slug: 'mine');

        $foreign = $this->makeDoc('hr-portal', 'policies/foreign.md', canonical: true, slug: 'foreign');
        $foreign->tenant_id = 'tenant-b';
        $foreign->save();

        $response = $this->actingAs($admin)
            ->postJson('/api/admin/kb/documents/bulk-delete', ['ids' => [$mine->id, $foreign->id]])
            ->assertOk();

        // R30: the foreign id is indistinguishable from a nonexistent one.
        $response->assertJsonPath('results.0.status', 'deleted');
        $response->assertJsonPath('results.1.status', 'not_found');

        $this->assertSoftDeleted('knowledge_documents', ['id' => $mine->id]);
        $this->assertNull(KnowledgeDocument::withTrashed()->find($foreign->id)?->deleted_at);
    }

    public function test_bulk_delete_returns_404_when_no_id_resolves(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->postJson('/api/admin/kb/documents/bulk-delete', ['ids' => [987654, 987655]])
            ->assertNotFound();
    }

    public function test_bulk_delete_validates_payload(): void
    {
        $admin = $this->makeAdmin();

        // Empty list.
        $this->actingAs($admin)
            ->postJson('/api/admin/kb/documents/bulk-delete', ['ids' => []])
            ->assertUnprocessable();

        // Over the 100-id memory bound (R3).
        $this->actingAs($admin)
            ->postJson('/api/admin/kb/documents/bulk-delete', ['ids' => range(1, 101)])
            ->assertUnprocessable();

        // Non-integer ids.
        $this->actingAs($admin)
            ->postJson('/api/admin/kb/documents/bulk-delete', ['ids' => ['abc']])
            ->assertUnprocessable();
    }

    // ------------------------------------------------------------------
    // bulk-restore
    // ------------------------------------------------------------------

    public function test_bulk_restore_revives_trashed_docs_and_reports_misses(): void
    {
        $admin = $this->makeAdmin();
        $trashed = $this->makeDoc('hr-portal', 'policies/trashed.md', canonical: true, slug: 'trashed');
        $trashed->delete();
        $live = $this->makeDoc('hr-portal', 'policies/live.md', canonical: true, slug: 'live');

        $response = $this->actingAs($admin)
            ->postJson('/api/admin/kb/documents/bulk-restore', ['ids' => [$trashed->id, $live->id, 987654]])
            ->assertOk();

        $response->assertJsonPath('results.0.status', 'restored');
        $response->assertJsonPath('results.1.status', 'not_trashed');
        $response->assertJsonPath('results.2.status', 'not_found');
        $response->assertJsonPath('summary.requested', 3);
        $response->assertJsonPath('summary.restored', 1);
        $response->assertJsonPath('summary.not_trashed', 1);
        $response->assertJsonPath('summary.not_found', 1);

        $this->assertNull(KnowledgeDocument::find($trashed->id)?->deleted_at);
    }

    public function test_bulk_restore_ignores_cross_tenant_trashed_doc(): void
    {
        $admin = $this->makeAdmin();
        $foreign = $this->makeDoc('hr-portal', 'policies/foreign.md', canonical: true, slug: 'foreign');
        $foreign->tenant_id = 'tenant-b';
        $foreign->save();
        $foreign->delete();

        // Zero ids resolve in-tenant → whole request is a loud no-op (R14).
        $this->actingAs($admin)
            ->postJson('/api/admin/kb/documents/bulk-restore', ['ids' => [$foreign->id]])
            ->assertNotFound();

        $this->assertNotNull(KnowledgeDocument::withTrashed()->find($foreign->id)?->deleted_at);
    }

    public function test_bulk_restore_returns_404_when_no_id_resolves(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->postJson('/api/admin/kb/documents/bulk-restore', ['ids' => [987654]])
            ->assertNotFound();
    }

    // ------------------------------------------------------------------
    // RBAC (compensates the GET-only authorization matrix, R32)
    // ------------------------------------------------------------------

    public function test_non_admin_roles_get_403_on_both_endpoints(): void
    {
        $doc = $this->makeDoc('hr-portal', 'policies/rbac.md', canonical: true, slug: 'rbac');

        foreach (['viewer', 'editor', 'dpo'] as $role) {
            $user = $this->makeUserWithRole($role);

            $this->actingAs($user)
                ->postJson('/api/admin/kb/documents/bulk-delete', ['ids' => [$doc->id]])
                ->assertForbidden();

            $this->actingAs($user)
                ->postJson('/api/admin/kb/documents/bulk-restore', ['ids' => [$doc->id]])
                ->assertForbidden();
        }

        // Nothing was deleted by the denied attempts.
        $this->assertNull(KnowledgeDocument::find($doc->id)?->deleted_at);
    }

    public function test_guest_gets_401_on_both_endpoints(): void
    {
        $this->postJson('/api/admin/kb/documents/bulk-delete', ['ids' => [1]])
            ->assertUnauthorized();

        $this->postJson('/api/admin/kb/documents/bulk-restore', ['ids' => [1]])
            ->assertUnauthorized();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function makeAdmin(): User
    {
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $admin->assignRole('admin');

        return $admin;
    }

    private function makeUserWithRole(string $role): User
    {
        $user = User::create([
            'name' => ucfirst($role),
            'email' => $role.'-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function makeDoc(
        string $projectKey,
        string $sourcePath,
        bool $canonical,
        ?string $slug,
    ): KnowledgeDocument {
        $docId = $canonical ? 'doc-'.($slug ?? 'x') : null;

        return KnowledgeDocument::create([
            'project_key' => $projectKey,
            'source_type' => 'md',
            'title' => 'Title: '.basename($sourcePath, '.md'),
            'source_path' => $sourcePath,
            'mime_type' => 'text/markdown',
            'language' => 'en',
            'access_scope' => 'project',
            'status' => 'indexed',
            'document_hash' => hash('sha256', $projectKey.'/'.$sourcePath.'/'.uniqid()),
            'version_hash' => hash('sha256', $projectKey.'/'.$sourcePath.'/v1/'.uniqid()),
            'metadata' => [],
            'doc_id' => $docId,
            'slug' => $slug,
            'canonical_type' => $canonical ? 'policy' : null,
            'canonical_status' => $canonical ? 'accepted' : null,
            'is_canonical' => $canonical,
            'retrieval_priority' => $canonical ? 80 : 50,
            'indexed_at' => now(),
        ]);
    }
}
