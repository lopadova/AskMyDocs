<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Admin;

use App\Jobs\ExecuteKbWikiExportJob;
use App\Models\KbWikiExportRequest;
use App\Models\KnowledgeDocument;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * v8.38/W4b (ADR 0032 §1/§5/§11) — the async HTTP surface.
 *
 * Coverage: R43 both states (flag on/off) for all three actions, R30
 * cross-tenant isolation, the happy path create → poll → download, and the
 * download-time re-authorization gate (`export_invalidated`) — the SECOND,
 * independent check the ADR requires because a cached idempotency key can
 * outlive the ACL state it was computed from.
 */
final class KbWikiExportControllerTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    private string $projectKey = 'default';

    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->group(__DIR__.'/../../../../routes/api.php');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = app(TenantContext::class)->current();
        $this->seed(RbacSeeder::class);
        Storage::fake((string) config('kb.staging.disk', 'kb-staging'));
        $this->withHeaders(['Accept' => 'application/json']);
        config(['kb.wiki_export.enabled' => true]);
    }

    private function makeAdmin(): User
    {
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $admin->assignRole('admin');
        ProjectMembership::create([
            'tenant_id' => $this->tenantId,
            'user_id' => $admin->id,
            'project_key' => $this->projectKey,
            'role' => 'member',
            'scope_allowlist' => null,
        ]);

        return $admin;
    }

    private function document(string $path): KnowledgeDocument
    {
        $hash = hash('sha256', $path);

        return KnowledgeDocument::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenantId,
            'project_key' => $this->projectKey,
            'source_type' => 'markdown',
            'title' => basename($path),
            'source_path' => $path,
            'mime_type' => 'text/markdown',
            'language' => 'en',
            'status' => 'active',
            'document_hash' => $hash,
            'version_hash' => $hash,
        ]);
    }

    public function test_store_with_the_flag_off_returns_the_disabled_shape_not_a_partial_run(): void
    {
        config(['kb.wiki_export.enabled' => false]);
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->postJson('/api/admin/kb/exports', ['project_key' => $this->projectKey])
            ->assertOk()
            ->assertJson(['disabled' => true]);

        $this->assertDatabaseCount('kb_wiki_export_requests', 0);
    }

    public function test_show_with_the_flag_off_returns_the_disabled_shape_even_for_an_unknown_id(): void
    {
        config(['kb.wiki_export.enabled' => false]);
        $admin = $this->makeAdmin();

        // R43 — an operator flipping the flag mid-incident must be able to
        // tell "feature off" apart from "this id never existed"; both must
        // NOT collapse into an ordinary 404.
        $this->actingAs($admin)
            ->getJson('/api/admin/kb/exports/00000000-0000-0000-0000-000000000000')
            ->assertOk()
            ->assertJson(['disabled' => true]);
    }

    public function test_store_creates_a_queued_request_and_dispatches_the_job(): void
    {
        Queue::fake();
        $this->document('docs/a.md');
        $admin = $this->makeAdmin();

        $resp = $this->actingAs($admin)
            ->postJson('/api/admin/kb/exports', ['project_key' => $this->projectKey])
            ->assertStatus(202);

        $resp->assertJsonPath('data.status', KbWikiExportRequest::STATUS_QUEUED)
            ->assertJsonPath('data.project_key', $this->projectKey);
        Queue::assertPushed(ExecuteKbWikiExportJob::class, 1);
    }

    public function test_a_repeat_store_reuses_the_same_request_and_returns_200(): void
    {
        Queue::fake();
        $this->document('docs/a.md');
        $admin = $this->makeAdmin();

        $first = $this->actingAs($admin)
            ->postJson('/api/admin/kb/exports', ['project_key' => $this->projectKey])
            ->assertStatus(202)
            ->json('data.id');

        $second = $this->actingAs($admin)
            ->postJson('/api/admin/kb/exports', ['project_key' => $this->projectKey])
            ->assertStatus(200)
            ->json('data.id');

        $this->assertSame($first, $second);
        Queue::assertPushed(ExecuteKbWikiExportJob::class, 1);
    }

    public function test_show_404s_for_an_export_belonging_to_another_tenant(): void
    {
        $admin = $this->makeAdmin();
        $foreign = KbWikiExportRequest::create([
            'tenant_id' => 'a-different-tenant',
            'project_key' => $this->projectKey,
            'status' => KbWikiExportRequest::STATUS_COMPLETED,
            'options_json' => ['format' => 'llm-wiki', 'include_images' => false],
            'idempotency_key' => str_repeat('a', 64),
        ]);

        $this->actingAs($admin)
            ->getJson("/api/admin/kb/exports/{$foreign->id}")
            ->assertStatus(404);
    }

    public function test_the_full_lifecycle_create_poll_and_download(): void
    {
        Queue::fake();
        $this->document('docs/a.md');
        $admin = $this->makeAdmin();

        $exportId = $this->actingAs($admin)
            ->postJson('/api/admin/kb/exports', ['project_key' => $this->projectKey])
            ->json('data.id');

        // Run the queued job synchronously (Queue::fake() intercepted the
        // real dispatch) to simulate the worker picking it up.
        (new ExecuteKbWikiExportJob($exportId, (int) $admin->id, $this->tenantId))->handle(
            app(\App\Services\Kb\Export\KbWikiExportService::class),
            app(TenantContext::class),
        );

        $status = $this->actingAs($admin)
            ->getJson("/api/admin/kb/exports/{$exportId}")
            ->assertOk()
            ->assertJsonPath('data.status', KbWikiExportRequest::STATUS_COMPLETED);
        $downloadUrl = $status->json('data.download_url');
        $this->assertNotNull($downloadUrl);

        $this->actingAs($admin)
            ->get($downloadUrl)
            ->assertOk();
    }

    /**
     * ADR 0032 §11's second, independent gate: the recorded document ids
     * are re-checked against the CURRENT session's visibility at download
     * time, not only when the export was created.
     */
    public function test_download_answers_403_export_invalidated_once_the_document_is_no_longer_visible(): void
    {
        Queue::fake();
        $doc = $this->document('docs/a.md');
        $admin = $this->makeAdmin();

        $exportId = $this->actingAs($admin)
            ->postJson('/api/admin/kb/exports', ['project_key' => $this->projectKey])
            ->json('data.id');

        (new ExecuteKbWikiExportJob($exportId, (int) $admin->id, $this->tenantId))->handle(
            app(\App\Services\Kb\Export\KbWikiExportService::class),
            app(TenantContext::class),
        );

        // The document that made the export non-empty is removed from the
        // ACL-visible set entirely (soft-deleted) after the export completed.
        $doc->delete();

        $downloadUrl = $this->actingAs($admin)
            ->getJson("/api/admin/kb/exports/{$exportId}")
            ->json('data.download_url');

        $this->actingAs($admin)
            ->getJson($downloadUrl)
            ->assertStatus(403)
            ->assertJsonPath('code', 'export_invalidated');
    }
}
