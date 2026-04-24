<?php

namespace Tests\Feature\Api\Admin\Kb;

use App\Models\KbCanonicalAudit;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * PR9 / Phase G2 — admin KB document detail endpoints.
 *
 * Mirrors the KbTreeControllerTest harness: routes mounted through
 * `defineRoutes()` so the Sanctum-less Testbench still wires the
 * `role` middleware; `RbacSeeder` in setUp; Cache::flush() to clear
 * Spatie's permission cache between rollback cycles.
 *
 * Every scenario hits the real DB + real Storage::fake('kb') (R4-safe).
 * The controller exercises the withTrashed() route-binding shim, so
 * the "show with ?with_trashed=1 returns a trashed doc" scenario
 * is the critical regression gate.
 */
class KbDocumentControllerTest extends TestCase
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
    // show
    // ------------------------------------------------------------------

    public function test_show_returns_document_with_counts_and_top_20_audits(): void
    {
        $admin = $this->makeAdmin();
        $doc = $this->makeDoc('hr-portal', 'policies/remote-work.md', canonical: true, slug: 'remote-work');

        // Chunks aggregate — show should surface chunks_count.
        KnowledgeChunk::create([
            'knowledge_document_id' => $doc->id,
            'project_key' => $doc->project_key,
            'chunk_order' => 0,
            'chunk_hash' => hash('sha256', 'c0'),
            'chunk_text' => 'chunk 0',
            'metadata' => [],
        ]);
        KnowledgeChunk::create([
            'knowledge_document_id' => $doc->id,
            'project_key' => $doc->project_key,
            'chunk_order' => 1,
            'chunk_hash' => hash('sha256', 'c1'),
            'chunk_text' => 'chunk 1',
            'metadata' => [],
        ]);

        // 25 audits — show should surface top 20 in recent_audits and
        // the full 25 in audits_count.
        for ($i = 0; $i < 25; $i++) {
            KbCanonicalAudit::create([
                'project_key' => $doc->project_key,
                'doc_id' => $doc->doc_id,
                'slug' => $doc->slug,
                'event_type' => 'updated',
                'actor' => 'test',
                'before_json' => ['i' => $i],
                'after_json' => ['i' => $i + 1],
                'metadata_json' => [],
                'created_at' => now()->subMinutes(25 - $i),
            ]);
        }

        $response = $this->actingAs($admin)
            ->getJson('/api/admin/kb/documents/'.$doc->id)
            ->assertOk();

        $response->assertJsonPath('data.id', $doc->id);
        $response->assertJsonPath('data.project_key', 'hr-portal');
        $response->assertJsonPath('data.slug', 'remote-work');
        $response->assertJsonPath('data.is_canonical', true);
        $response->assertJsonPath('data.chunks_count', 2);
        $response->assertJsonPath('data.audits_count', 25);

        $recent = $response->json('data.recent_audits');
        $this->assertIsArray($recent);
        $this->assertCount(20, $recent);
    }

    public function test_show_with_trashed_returns_soft_deleted_doc(): void
    {
        $admin = $this->makeAdmin();
        $doc = $this->makeDoc('hr-portal', 'policies/gone.md', canonical: true, slug: 'gone');
        $doc->delete();

        // Default binding still resolves (withTrashed() shim) so the
        // admin UI can inspect the trashed row.
        $response = $this->actingAs($admin)
            ->getJson('/api/admin/kb/documents/'.$doc->id.'?with_trashed=1')
            ->assertOk();

        $response->assertJsonPath('data.id', $doc->id);
        $this->assertNotNull($response->json('data.deleted_at'));
    }

    // ------------------------------------------------------------------
    // raw
    // ------------------------------------------------------------------

    public function test_raw_returns_markdown_content_and_normalizes_path(): void
    {
        $admin = $this->makeAdmin();
        // Source path is already normalised at ingest time; write the
        // identical normalised key on the fake disk so the lookup
        // lines up (R1).
        $doc = $this->makeDoc('hr-portal', 'policies/remote-work.md', canonical: true, slug: 'remote-work');

        Storage::disk('kb')->put('policies/remote-work.md', "# Remote Work\n\nBody.");

        $response = $this->actingAs($admin)
            ->getJson('/api/admin/kb/documents/'.$doc->id.'/raw')
            ->assertOk();

        $response->assertJsonPath('path', 'policies/remote-work.md');
        $response->assertJsonPath('disk', 'kb');
        $this->assertStringContainsString('# Remote Work', $response->json('content'));
        $this->assertSame(hash('sha256', $response->json('content')), $response->json('content_hash'));
    }

    public function test_raw_returns_404_when_file_missing(): void
    {
        $admin = $this->makeAdmin();
        $doc = $this->makeDoc('hr-portal', 'policies/ghost.md', canonical: true, slug: 'ghost');
        // No Storage::put — file is missing on disk.

        $this->actingAs($admin)
            ->getJson('/api/admin/kb/documents/'.$doc->id.'/raw')
            ->assertStatus(404)
            ->assertJsonPath('path', 'policies/ghost.md');
    }

    // ------------------------------------------------------------------
    // download
    // ------------------------------------------------------------------

    public function test_download_streams_file(): void
    {
        $admin = $this->makeAdmin();
        $doc = $this->makeDoc('hr-portal', 'policies/remote-work.md', canonical: true, slug: 'remote-work');
        Storage::disk('kb')->put('policies/remote-work.md', "# DL\n");

        $response = $this->actingAs($admin)
            ->get('/api/admin/kb/documents/'.$doc->id.'/download');

        $response->assertOk();
        $disposition = $response->headers->get('Content-Disposition') ?? '';
        $this->assertStringContainsString('remote-work.md', $disposition);
    }

    // ------------------------------------------------------------------
    // print
    // ------------------------------------------------------------------

    public function test_print_returns_html_with_print_id_and_title(): void
    {
        $admin = $this->makeAdmin();
        $doc = $this->makeDoc('hr-portal', 'policies/remote-work.md', canonical: true, slug: 'remote-work');
        Storage::disk('kb')->put('policies/remote-work.md', "# Printable body\n");

        $response = $this->actingAs($admin)
            ->get('/api/admin/kb/documents/'.$doc->id.'/print')
            ->assertOk();

        $body = $response->getContent();
        $this->assertNotFalse($body);
        $this->assertStringContainsString('id="doc-print"', $body);
        $this->assertStringContainsString($doc->title, $body);
        $this->assertStringContainsString('Printable body', $body);
    }

    // ------------------------------------------------------------------
    // restore
    // ------------------------------------------------------------------

    public function test_restore_un_deletes_a_trashed_doc(): void
    {
        $admin = $this->makeAdmin();
        $doc = $this->makeDoc('hr-portal', 'policies/gone.md', canonical: true, slug: 'gone');
        $doc->delete();

        $response = $this->actingAs($admin)
            ->postJson('/api/admin/kb/documents/'.$doc->id.'/restore')
            ->assertOk();

        $response->assertJsonPath('data.id', $doc->id);
        $response->assertJsonPath('data.deleted_at', null);

        // Global scope now hides nothing — doc is back.
        $live = KnowledgeDocument::find($doc->id);
        $this->assertNotNull($live);
        $this->assertNull($live->deleted_at);
    }

    public function test_restore_on_live_doc_returns_409(): void
    {
        $admin = $this->makeAdmin();
        $doc = $this->makeDoc('hr-portal', 'policies/live.md', canonical: true, slug: 'live');

        $this->actingAs($admin)
            ->postJson('/api/admin/kb/documents/'.$doc->id.'/restore')
            ->assertStatus(409);
    }

    // ------------------------------------------------------------------
    // destroy (soft + force)
    // ------------------------------------------------------------------

    public function test_destroy_soft_deletes_by_default(): void
    {
        $admin = $this->makeAdmin();
        $doc = $this->makeDoc('hr-portal', 'policies/soft.md', canonical: true, slug: 'soft');
        Storage::disk('kb')->put('policies/soft.md', "# Body\n");

        $response = $this->actingAs($admin)
            ->deleteJson('/api/admin/kb/documents/'.$doc->id)
            ->assertOk();

        $this->assertSame('soft', $response->json('mode'));

        $doc->refresh();
        $this->assertNotNull($doc->deleted_at);
        $this->assertTrue(Storage::disk('kb')->exists('policies/soft.md'));
    }

    public function test_destroy_with_force_hard_deletes_and_removes_file(): void
    {
        $admin = $this->makeAdmin();
        $doc = $this->makeDoc('hr-portal', 'policies/hard.md', canonical: true, slug: 'hard');
        $doc->metadata = ['disk' => 'kb', 'prefix' => ''];
        $doc->save();
        Storage::disk('kb')->put('policies/hard.md', "# Body\n");

        $response = $this->actingAs($admin)
            ->deleteJson('/api/admin/kb/documents/'.$doc->id.'?force=1')
            ->assertOk();

        $this->assertSame('hard', $response->json('mode'));

        // Row is gone from the DB (even through withTrashed()).
        $this->assertNull(KnowledgeDocument::withTrashed()->find($doc->id));
        $this->assertFalse(Storage::disk('kb')->exists('policies/hard.md'));
    }

    // ------------------------------------------------------------------
    // history
    // ------------------------------------------------------------------

    public function test_history_paginated_and_ordered_desc_by_created_at(): void
    {
        $admin = $this->makeAdmin();
        $doc = $this->makeDoc('hr-portal', 'policies/historical.md', canonical: true, slug: 'historical');

        // 25 audits with created_at staggered so the ORDER BY is
        // observable. Newest first (desc) — ids 25 down to 6 on page 1.
        for ($i = 0; $i < 25; $i++) {
            KbCanonicalAudit::create([
                'project_key' => $doc->project_key,
                'doc_id' => $doc->doc_id,
                'slug' => $doc->slug,
                'event_type' => 'updated',
                'actor' => 'test',
                'before_json' => null,
                'after_json' => ['i' => $i],
                'metadata_json' => [],
                'created_at' => now()->subMinutes(25 - $i),
            ]);
        }

        $response = $this->actingAs($admin)
            ->getJson('/api/admin/kb/documents/'.$doc->id.'/history')
            ->assertOk();

        $rows = $response->json('data');
        $this->assertIsArray($rows);
        $this->assertCount(20, $rows);

        // Desc order — the first row's created_at should be >= the last.
        $first = $rows[0]['created_at'] ?? null;
        $last = $rows[19]['created_at'] ?? null;
        $this->assertNotNull($first);
        $this->assertNotNull($last);
        $this->assertGreaterThanOrEqual($last, $first);

        // Pagination envelope exists.
        $this->assertSame(25, $response->json('meta.total'));
    }

    // ------------------------------------------------------------------
    // RBAC — guest 401 + viewer 403 on all endpoints
    // ------------------------------------------------------------------

    public function test_guest_gets_401_on_all_endpoints(): void
    {
        $doc = $this->makeDoc('hr-portal', 'policies/rbac.md', canonical: true, slug: 'rbac');

        // Every endpoint is mounted under `auth:sanctum + role:…`. Using
        // getJson/postJson/deleteJson keeps `Accept: application/json` on
        // the request so the auth middleware returns 401 JSON instead
        // of redirecting to a non-existent `login` route (Laravel's
        // default behaviour for non-JSON requests).
        $this->getJson('/api/admin/kb/documents/'.$doc->id)->assertStatus(401);
        $this->getJson('/api/admin/kb/documents/'.$doc->id.'/raw')->assertStatus(401);
        $this->getJson('/api/admin/kb/documents/'.$doc->id.'/download')->assertStatus(401);
        $this->getJson('/api/admin/kb/documents/'.$doc->id.'/print')->assertStatus(401);
        $this->postJson('/api/admin/kb/documents/'.$doc->id.'/restore')->assertStatus(401);
        $this->deleteJson('/api/admin/kb/documents/'.$doc->id)->assertStatus(401);
        $this->getJson('/api/admin/kb/documents/'.$doc->id.'/history')->assertStatus(401);
    }

    public function test_non_admin_gets_403_on_all_endpoints(): void
    {
        $viewer = $this->makeViewer('viewer-g2');
        $doc = $this->makeDoc('hr-portal', 'policies/rbac.md', canonical: true, slug: 'rbac');

        $this->actingAs($viewer)
            ->getJson('/api/admin/kb/documents/'.$doc->id)
            ->assertStatus(403);
        $this->actingAs($viewer)
            ->postJson('/api/admin/kb/documents/'.$doc->id.'/restore')
            ->assertStatus(403);
        $this->actingAs($viewer)
            ->deleteJson('/api/admin/kb/documents/'.$doc->id)
            ->assertStatus(403);
        $this->actingAs($viewer)
            ->getJson('/api/admin/kb/documents/'.$doc->id.'/history')
            ->assertStatus(403);
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

    private function makeViewer(string $slug, ?string $email = null): User
    {
        $user = User::create([
            'name' => $slug,
            'email' => $email ?? $slug.'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $user->assignRole('viewer');

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
            'document_hash' => hash('sha256', $projectKey.'/'.$sourcePath),
            'version_hash' => hash('sha256', $projectKey.'/'.$sourcePath.'/v1'),
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
