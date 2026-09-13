<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\Versioning;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\User;
use App\Services\Kb\DocumentDeleter;
use App\Services\Kb\Versioning\ConversionArtifactStore;
use App\Services\Kb\Versioning\DocumentVersionService;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * v8.36 / ADR 0030 §5-§9 — the read side of the artifacts: artifact-aware
 * diff (both / none / mixed / missing file), the content endpoint, restore
 * provenance, the CLI + MCP surfaces (R44) and erasure (§8).
 */
final class DocumentVersionArtifactsTest extends TestCase
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
        Storage::fake('kb');
        config(['kb.sources.disk' => 'kb', 'kb.sources.path_prefix' => '', 'kb.conversion_artifacts.enabled' => true]);
    }

    private function makeAdmin(): User
    {
        $admin = User::create(['name' => 'Admin', 'email' => 'admin-'.uniqid().'@demo.local', 'password' => Hash::make('secret123')]);
        $admin->assignRole('admin');

        return $admin;
    }

    /**
     * A version row with one chunk; `$artifact` writes the stored Markdown
     * and points `markdown_path` at it (null = reconstruction only).
     */
    private function version(string $seed, string $status, string $chunkText, ?string $artifact = null, string $path = 'docs/dec.md'): KnowledgeDocument
    {
        $hash = hash('sha256', $seed);
        $attributes = [
            'project_key' => 'eng',
            'source_path' => $path,
            'source_type' => 'markdown',
            'title' => 'Decision '.$seed,
            'mime_type' => 'text/markdown',
            'language' => 'it',
            'access_scope' => 'internal',
            'status' => $status,
            'document_hash' => $hash,
            'version_hash' => $hash,
            'metadata' => ['disk' => 'kb', 'prefix' => ''],
            'indexed_at' => now()->subMinutes(strlen($seed)),
        ];
        if ($artifact !== null) {
            $store = app(ConversionArtifactStore::class);
            $final = $store->pathFor(app(TenantContext::class)->current(), 'eng', $path, $hash);
            $store->publish('kb', $store->writeTemp('kb', $final, $artifact), $final);
            $attributes['markdown_path'] = $final;
            $attributes['content_hash'] = hash('sha256', $artifact); // the integrity hash of the stored bytes (ADR 0030 §4)
            $attributes['version_actor'] = 'user:7';
            $attributes['version_reason'] = 'seeded';
        }
        $doc = KnowledgeDocument::create($attributes);
        KnowledgeChunk::create([
            'knowledge_document_id' => $doc->id,
            'project_key' => 'eng',
            'chunk_order' => 0,
            'chunk_hash' => hash('sha256', $seed.'chunk'),
            'heading_path' => 'Decision',
            'chunk_text' => $chunkText,
            'metadata' => [],
        ]);

        return $doc;
    }

    public function test_diff_of_two_artifact_versions_is_faithful_and_says_so(): void
    {
        $a = $this->version('v1', 'archived', 'index a', "# Doc\n\nline one\n");
        $b = $this->version('v2', 'active', 'index b', "# Doc\n\nline two\n");

        $diff = app(DocumentVersionService::class)->diff($a, $b);

        $this->assertSame('artifact', $diff['from_source']);
        $this->assertSame('artifact', $diff['to_source']);
        $texts = array_column($diff['rows'], 'text');
        $this->assertContains('line one', $texts);
        $this->assertContains('line two', $texts);
        $this->assertNotContains('index a', $texts);
    }

    public function test_diff_without_artifacts_falls_back_to_reconstruction_as_in_v8_7(): void
    {
        $a = $this->version('v1', 'archived', 'index a');
        $b = $this->version('v2', 'active', 'index b');

        $diff = app(DocumentVersionService::class)->diff($a, $b);

        $this->assertSame('reconstruction', $diff['from_source']);
        $this->assertSame('reconstruction', $diff['to_source']);
        $this->assertContains('index a', array_column($diff['rows'], 'text'));
    }

    public function test_a_mixed_pair_falls_back_only_on_the_side_without_an_artifact(): void
    {
        $a = $this->version('v1', 'archived', 'index a');
        $b = $this->version('v2', 'active', 'index b', "# Doc\n\nartifact b\n");

        $diff = app(DocumentVersionService::class)->diff($a, $b);

        $this->assertSame('reconstruction', $diff['from_source']);
        $this->assertSame('artifact', $diff['to_source']);
    }

    public function test_a_missing_file_behind_markdown_path_is_logged_and_degrades_to_reconstruction(): void
    {
        $a = $this->version('v1', 'active', 'index a', "# Doc\n\nartifact a\n");
        Storage::disk('kb')->delete((string) $a->markdown_path);
        Log::spy();

        $content = app(DocumentVersionService::class)->contentFor($a);

        $this->assertSame(['content' => 'index a', 'source' => 'reconstruction', 'integrity' => null], $content);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $msg): bool => str_contains($msg, 'artifact missing'))->once();
    }

    public function test_index_reports_provenance_and_has_artifact_additively(): void
    {
        $old = $this->version('v1', 'archived', 'index a');
        $new = $this->version('v2', 'active', 'index b', "# Doc\n\nartifact b\n");

        $this->actingAs($this->makeAdmin())->getJson("/api/admin/kb/documents/{$new->id}/versions")
            ->assertOk()
            ->assertJsonPath('data.0.id', $new->id)
            ->assertJsonPath('data.0.has_artifact', true)
            ->assertJsonPath('data.0.version_actor', 'user:7')
            ->assertJsonPath('data.0.version_reason', 'seeded')
            ->assertJsonPath('data.0.content_hash', $new->content_hash)
            ->assertJsonPath('data.1.id', $old->id)
            ->assertJsonPath('data.1.has_artifact', false)
            ->assertJsonPath('data.1.version_actor', null)
            // the v8.7 keys are all still there (R27)
            ->assertJsonPath('data.0.is_live', true)
            ->assertJsonPath('data.0.version_hash', $new->version_hash);
    }

    public function test_content_endpoint_returns_the_artifact_and_its_source(): void
    {
        $doc = $this->version('v1', 'active', 'index a', "# Doc\n\nartifact a\n");

        $this->actingAs($this->makeAdmin())->getJson("/api/admin/kb/documents/{$doc->id}/versions/{$doc->id}/content")
            ->assertOk()
            ->assertJsonPath('data.id', $doc->id)
            ->assertJsonPath('data.source', 'artifact')
            ->assertJsonPath('data.content_hash', $doc->content_hash)
            ->assertJsonPath('data.content', "# Doc\n\nartifact a\n");
    }

    public function test_content_endpoint_refuses_a_version_outside_the_family_and_diff_reports_sources(): void
    {
        $doc = $this->version('v1', 'active', 'index a', "# Doc\n\nartifact a\n");
        $other = $this->version('o1', 'active', 'other', null, 'docs/other.md');
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->getJson("/api/admin/kb/documents/{$doc->id}/versions/{$other->id}/content")->assertStatus(404);

        $old = $this->version('v0', 'archived', 'index 0');
        $this->actingAs($admin)->getJson("/api/admin/kb/documents/{$doc->id}/versions/diff?from={$old->id}&to={$doc->id}")
            ->assertOk()
            ->assertJsonPath('data.from_source', 'reconstruction')
            ->assertJsonPath('data.to_source', 'artifact');
    }

    /**
     * ADR 0030 §6 — `version_actor` / `version_reason` are the CREATION
     * provenance and survive a restore untouched; the restore itself is
     * recorded apart (`metadata.restores[]`, surfaced as `restored_by` /
     * `restored_at`), and the artifact stays where it was.
     */
    public function test_restore_keeps_the_creation_provenance_and_records_who_restored_apart(): void
    {
        $old = $this->version('v1', 'archived', 'index a', "# Doc\n\nartifact a\n");
        $old->update(['version_actor' => 'system:ocr', 'version_reason' => 'ocr re-run (fake)']);
        $live = $this->version('v2', 'active', 'index b');
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->postJson("/api/admin/kb/documents/{$old->id}/restore-version")->assertOk();

        $restored = $old->fresh();
        $this->assertSame('active', $restored->status);
        $this->assertSame('system:ocr', $restored->version_actor, 'creation provenance is immutable');
        $this->assertSame('ocr re-run (fake)', $restored->version_reason);
        $this->assertSame('user:'.$admin->id, $restored->metadata['restores'][0]['actor']);
        $this->assertSame($live->id, $restored->metadata['restores'][0]['previous_live_id']);
        $this->assertSame($old->markdown_path, $restored->markdown_path);
        Storage::disk('kb')->assertExists((string) $restored->markdown_path);

        $this->actingAs($admin)->getJson("/api/admin/kb/documents/{$old->id}/versions")
            ->assertOk()
            ->assertJsonPath('data.0.id', $old->id)
            ->assertJsonPath('data.0.version_actor', 'system:ocr')
            ->assertJsonPath('data.0.restored_by', 'user:'.$admin->id)
            ->assertJsonPath('data.1.restored_by', null);
    }

    /**
     * ADR 0030 §5 — `content_hash` is the integrity check of the stored bytes:
     * a tampered artifact is never served as faithful; the content falls
     * back to the reconstruction and says `integrity: mismatch`.
     */
    public function test_a_tampered_artifact_is_reported_as_a_mismatch_and_falls_back_to_reconstruction(): void
    {
        $old = $this->version('v1', 'archived', 'index a', "# Doc\n\nartifact a\n");
        $live = $this->version('v2', 'active', 'index b', "# Doc\n\nartifact b\n");
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->getJson("/api/admin/kb/documents/{$live->id}/versions/{$old->id}/content")
            ->assertOk()->assertJsonPath('data.source', 'artifact')->assertJsonPath('data.integrity', 'verified');

        Storage::disk('kb')->put((string) $old->markdown_path, "# Doc\n\ntampered\n");

        $this->actingAs($admin)->getJson("/api/admin/kb/documents/{$live->id}/versions/{$old->id}/content")
            ->assertOk()
            ->assertJsonPath('data.source', 'reconstruction')
            ->assertJsonPath('data.integrity', 'mismatch')
            ->assertJsonPath('data.content', 'index a');
        $this->actingAs($admin)->getJson("/api/admin/kb/documents/{$live->id}/versions/diff?from={$old->id}&to={$live->id}")
            ->assertOk()
            ->assertJsonPath('data.from_source', 'reconstruction')
            ->assertJsonPath('data.from_integrity', 'mismatch')
            ->assertJsonPath('data.to_integrity', 'verified');
    }

    /**
     * ADR 0030 §3 — on a local disk a symlink under `.artifacts/` cannot make
     * a contained key resolve outside the root: read and delete refuse it.
     */
    public function test_a_symlink_under_the_artifact_root_cannot_reach_outside_it(): void
    {
        $outside = sys_get_temp_dir().'/askmydocs-outside-'.uniqid();
        mkdir($outside, 0755, true);
        file_put_contents($outside.'/secret.md', 'outside');
        $base = Storage::disk('kb')->path('.artifacts/t/eng');
        mkdir($base, 0755, true);
        symlink($outside, $base.'/link');
        $path = '.artifacts/t/eng/link/secret.md';
        $this->assertTrue(Storage::disk('kb')->exists($path), 'the symlinked file is visible through the disk');

        $store = app(ConversionArtifactStore::class);
        try {
            $this->assertNull($store->read('kb', $path), 'read refuses a path that resolves outside the root');
            $this->assertFalse($store->delete('kb', $path), 'delete refuses it too');
            $this->assertFileExists($outside.'/secret.md');
        } finally {
            unlink($base.'/link');
            unlink($outside.'/secret.md');
            rmdir($outside);
        }
    }

    public function test_cli_lists_the_family_diffs_and_refuses_a_blank_tenant(): void
    {
        $old = $this->version('v1', 'archived', 'index a', "# Doc\n\nline one\n");
        $new = $this->version('v2', 'active', 'index b', "# Doc\n\nline two\n");
        $tenant = app(TenantContext::class)->current();

        $this->artisan('kb:doc-versions', ['document' => $new->id, '--tenant' => ''])
            ->expectsOutputToContain('--tenant must be a non-empty tenant id.')
            ->assertExitCode(1);

        $this->artisan('kb:doc-versions', ['document' => $new->id, '--tenant' => $tenant, '--diff' => "{$old->id}:{$new->id}"])
            ->expectsOutputToContain('user:7')
            ->expectsOutputToContain('(artifact)')
            ->expectsOutputToContain('+ line two')
            ->assertExitCode(0);

        // R30 — another tenant's id resolves nothing.
        $this->artisan('kb:doc-versions', ['document' => $new->id, '--tenant' => 'other-tenant'])
            ->assertExitCode(1);
    }

    public function test_mcp_tool_lists_metadata_only_and_is_tenant_scoped(): void
    {
        $doc = $this->version('v1', 'active', 'index a', "# Doc\n\nartifact a\n");
        $tool = new \App\Mcp\Tools\KbDocumentVersionsTool;

        $response = $tool->handle(new \Laravel\Mcp\Request(['document_id' => $doc->id]), app(DocumentVersionService::class), app(TenantContext::class));
        $payload = json_decode((string) $response->content(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $payload['total']);
        $this->assertSame('user:7', $payload['versions'][0]['version_actor']);
        $this->assertTrue($payload['versions'][0]['has_artifact']);
        $this->assertArrayNotHasKey('content', $payload['versions'][0]);
        $this->assertStringNotContainsString('artifact a', (string) $response->content());

        // R30 — another tenant cannot see the family; the annotations say read-only.
        app(TenantContext::class)->set('other-tenant');
        $other = $tool->handle(new \Laravel\Mcp\Request(['document_id' => $doc->id]), app(DocumentVersionService::class), app(TenantContext::class));
        app(TenantContext::class)->reset();
        $this->assertTrue($other->isError());
        $names = array_map(static fn ($a): string => $a->getName(), (new \ReflectionClass($tool))->getAttributes());
        $this->assertContains(\Laravel\Mcp\Server\Tools\Annotations\IsReadOnly::class, $names);
    }

    public function test_hard_delete_removes_the_rows_own_artifact(): void
    {
        $doc = $this->version('v1', 'active', 'index a', "# Doc\n\nartifact a\n");
        $path = (string) $doc->markdown_path;
        Storage::disk('kb')->assertExists($path);

        $result = app(DocumentDeleter::class)->delete($doc, force: true);

        Storage::disk('kb')->assertMissing($path);
        $this->assertDatabaseMissing('knowledge_documents', ['id' => $doc->id]);
        // R27 additive: the hard delete reports the artifact cleanup, never silently.
        $this->assertTrue($result['artifact_deleted']);
    }

    public function test_soft_delete_leaves_the_artifact_in_place(): void
    {
        $doc = $this->version('v1', 'active', 'index a', "# Doc\n\nartifact a\n");

        app(DocumentDeleter::class)->delete($doc, force: false);

        Storage::disk('kb')->assertExists((string) $doc->markdown_path);
    }
}
