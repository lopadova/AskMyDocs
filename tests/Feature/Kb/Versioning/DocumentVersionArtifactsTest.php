<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\Versioning;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\User;
use App\Services\Kb\DocumentDeleter;
use App\Services\Kb\Versioning\ConversionArtifactStore;
use App\Services\Kb\Versioning\DocumentVersionService;
use App\Support\Kb\SourceKeyLock;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Tests\Fixtures\Cache\NoLockStore;
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
     * ADR 0030 §5 / R43 — the timeline badge is a READ plus a hash check, so
     * a `verified` state is memoized: a page does not re-read one object per
     * row on every listing. Only the state that cannot improve on its own is
     * cached — within the window a deleted file keeps its badge, while the
     * content endpoint re-reads and reports the truth.
     */
    public function test_a_verified_artifact_state_is_memoized_for_the_configured_window(): void
    {
        config(['kb.versioning.artifact_state_cache_seconds' => 300]);
        $version = $this->version('v1', 'active', 'index a', "# Doc\n\nartifact a\n");
        $service = app(DocumentVersionService::class);
        $this->assertSame(DocumentVersionService::ARTIFACT_VERIFIED, $service->artifactStateFor($version));

        Storage::disk('kb')->delete((string) $version->markdown_path);

        $this->assertSame(DocumentVersionService::ARTIFACT_VERIFIED, $service->artifactStateFor($version), 'the badge lags by at most the window');
        $this->assertSame(DocumentVersionService::SOURCE_RECONSTRUCTION, $service->contentFor($version)['source'], 'the served bytes never lag: the content path always re-reads');
    }

    /** R43 (OFF path) — with the window at 0 every read verifies: the badge cannot lag at all. */
    public function test_with_the_memo_disabled_every_read_verifies(): void
    {
        config(['kb.versioning.artifact_state_cache_seconds' => 0]);
        $version = $this->version('v1', 'active', 'index a', "# Doc\n\nartifact a\n");
        $service = app(DocumentVersionService::class);
        $this->assertSame(DocumentVersionService::ARTIFACT_VERIFIED, $service->artifactStateFor($version));

        Storage::disk('kb')->delete((string) $version->markdown_path);

        $this->assertSame(DocumentVersionService::ARTIFACT_MISSING, $service->artifactStateFor($version));
    }

    /**
     * The asymmetry that makes the memo safe: a repairable state is never
     * cached, so the identical re-ingest or `kb:artifacts-backfill` that
     * republishes the bytes shows as repaired on the very next listing —
     * never a "missing" badge frozen for the whole window.
     */
    public function test_a_repairable_state_is_never_memoized_so_a_repair_shows_at_once(): void
    {
        config(['kb.versioning.artifact_state_cache_seconds' => 300]);
        $version = $this->version('v1', 'active', 'index a', "# Doc\n\nartifact a\n");
        $path = (string) $version->markdown_path;
        Storage::disk('kb')->delete($path);
        $service = app(DocumentVersionService::class);
        $this->assertSame(DocumentVersionService::ARTIFACT_MISSING, $service->artifactStateFor($version));

        Storage::disk('kb')->put($path, "# Doc\n\nartifact a\n"); // the backfill republishes the same bytes

        $this->assertSame(DocumentVersionService::ARTIFACT_VERIFIED, $service->artifactStateFor($version), 'a repair is visible at once');
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
            try {
                $store->writeTemp('kb', '.artifacts/t/eng/link/new.md', 'bytes');
                $this->fail('writeTemp must refuse a final path whose parent resolves outside the root');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('outside the artifact root', $e->getMessage());
            }
            $this->assertSame(['secret.md'], array_map('basename', glob($outside.'/*') ?: []), 'nothing was written through the symlink');
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

    /**
     * A bare `(int)` cast on the `document` argument turns non-numeric input
     * into `0` silently, so a typo reads back as "Document 0 not found" —
     * blaming a row the operator never asked for instead of naming what they
     * actually typed.
     */
    public function test_a_non_numeric_document_argument_is_refused_by_name_not_cast_to_zero(): void
    {
        $tenant = app(TenantContext::class)->current();

        foreach (['abc', '1.5', '-1', '0', ''] as $bad) {
            $this->artisan('kb:doc-versions', ['document' => $bad, '--tenant' => $tenant])
                ->expectsOutputToContain("Invalid document id: '{$bad}'")
                ->assertExitCode(1);
        }
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

    /**
     * ADR 0030 §5 — `has_artifact` is a READ + VERIFIED claim, never the
     * pointer alone (the ingestor keeps the pointer when a post-commit publish
     * fails; a file can be truncated later): the additive `artifact_state`
     * says why on every surface (HTTP, MCP, CLI) — the same check the content
     * endpoint serves with.
     */
    public function test_has_artifact_is_a_verified_claim_and_artifact_state_says_why_on_every_surface(): void
    {
        $verified = $this->version('v1', 'archived', 'a', "# Doc\n\na\n");
        $missing = $this->version('v2', 'archived', 'b', "# Doc\n\nb\n");
        Storage::disk('kb')->delete((string) $missing->markdown_path);
        $corrupt = $this->version('v3', 'archived', 'c', "# Doc\n\nc\n");
        Storage::disk('kb')->put((string) $corrupt->markdown_path, 'tampered');
        $none = $this->version('v4', 'active', 'd');
        $expected = [
            $verified->id => ['verified', true],
            $missing->id => ['missing', false],
            $corrupt->id => ['mismatch', false],
            $none->id => ['none', false],
        ];

        $rows = $this->actingAs($this->makeAdmin())->getJson("/api/admin/kb/documents/{$none->id}/versions")
            ->assertOk()
            ->json('data');
        $this->assertCount(4, $rows);
        foreach ($rows as $row) {
            [$state, $has] = $expected[$row['id']];
            $this->assertSame($state, $row['artifact_state'], "row {$row['id']}");
            $this->assertSame($has, $row['has_artifact'], "row {$row['id']}");
        }

        $tool = new \App\Mcp\Tools\KbDocumentVersionsTool;
        $payload = json_decode((string) $tool->handle(new \Laravel\Mcp\Request(['document_id' => $none->id]), app(DocumentVersionService::class), app(TenantContext::class))->content(), true, flags: JSON_THROW_ON_ERROR);
        foreach ($payload['versions'] as $row) {
            [$state, $has] = $expected[$row['id']];
            $this->assertSame($state, $row['artifact_state']);
            $this->assertSame($has, $row['has_artifact']);
        }

        $this->artisan('kb:doc-versions', ['document' => $none->id, '--tenant' => app(TenantContext::class)->current()])
            ->expectsOutputToContain('mismatch')
            ->expectsOutputToContain('missing')
            ->assertExitCode(0);
    }

    /** `has_artifact` is a VERIFIED claim: a readable file with no `content_hash` to check against is `unverified`, never "stored". */
    public function test_a_readable_artifact_without_a_content_hash_is_unverified_and_not_claimed_as_stored(): void
    {
        $legacy = $this->version('v1', 'active', 'a', "# Doc\n\na\n");
        $legacy->update(['content_hash' => null]);

        $row = $this->actingAs($this->makeAdmin())->getJson("/api/admin/kb/documents/{$legacy->id}/versions")
            ->assertOk()
            ->json('data.0');
        $this->assertSame('unverified', $row['artifact_state']);
        $this->assertFalse($row['has_artifact']);
        // R44 — the MCP surface says exactly the same (one core: DocumentVersionService).
        $tool = new \App\Mcp\Tools\KbDocumentVersionsTool;
        $payload = json_decode((string) $tool->handle(new \Laravel\Mcp\Request(['document_id' => $legacy->id]), app(DocumentVersionService::class), app(TenantContext::class))->content(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('unverified', $payload['versions'][0]['artifact_state']);
        $this->assertFalse($payload['versions'][0]['has_artifact']);
        // …while the content endpoint still serves the stored bytes, with no integrity verdict.
        $this->actingAs($this->makeAdmin())->getJson("/api/admin/kb/documents/{$legacy->id}/versions/{$legacy->id}/content")
            ->assertOk()
            ->assertJsonPath('data.source', 'artifact')
            ->assertJsonPath('data.integrity', null);
    }

    /**
     * SEC-PATH-001 — a symlink planted at an EXISTING ancestor of a path whose
     * immediate parent does not exist yet is followed by a write: the
     * containment check resolves the nearest existing ancestor, so
     * `writeTemp()` refuses before any byte lands outside the root.
     */
    public function test_a_symlinked_ancestor_of_a_not_yet_created_artifact_directory_is_refused(): void
    {
        $store = app(ConversionArtifactStore::class);
        $tenant = app(TenantContext::class)->current();
        $root = rtrim(Storage::disk('kb')->path(''), '/');
        $outside = sys_get_temp_dir().'/amd-outside-'.uniqid();
        mkdir($outside, 0755, true);
        mkdir($root.'/.artifacts/'.$tenant, 0755, true);
        symlink($outside, $root.'/.artifacts/'.$tenant.'/eng'); // the project directory is a link; `docs/…versions` below it does not exist yet
        $final = $store->pathFor($tenant, 'eng', 'docs/new.md', str_repeat('e', 64));
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('outside the artifact root');
            $store->writeTemp('kb', $final, 'must never land outside');
        } finally {
            $this->assertSame([], glob($outside.'/*') ?: [], 'nothing was written through the link');
            unlink($root.'/.artifacts/'.$tenant.'/eng');
            rmdir($outside);
        }
    }

    /** ADR 0030 §8 — the Flow hard-delete path (`deleteRowsOnly()`) removes the row's own artifact too, and reports it. */
    public function test_delete_rows_only_removes_the_rows_own_artifact_and_reports_it(): void
    {
        $doc = $this->version('v1', 'active', 'index a', "# Doc\n\nartifact a\n");
        $path = (string) $doc->markdown_path;
        Storage::disk('kb')->assertExists($path);

        $result = app(DocumentDeleter::class)->deleteRowsOnly($doc);

        $this->assertTrue($result['artifact_deleted']);
        Storage::disk('kb')->assertMissing($path);
        $this->assertDatabaseMissing('knowledge_documents', ['id' => $doc->id]);
    }

    /** SEC-PATH-001 — the artifact root a sweep enumerates and deletes under is normalized and never escapes through the configured prefix. */
    public function test_the_artifact_root_is_normalized_and_refuses_a_traversing_prefix(): void
    {
        $store = app(ConversionArtifactStore::class);

        $this->assertSame('.artifacts', $store->rootFor(''));
        $this->assertSame('a/b/c/.artifacts', $store->rootFor('a\\b//c/'));
        $this->expectException(\RuntimeException::class);
        $store->rootFor('../outside');
    }

    /** SEC-PATH-001 — `.artifacts` is a reserved segment: a prefix carrying it would draw the containment boundary one level above the real root. */
    public function test_the_artifact_root_refuses_a_prefix_carrying_the_reserved_segment(): void
    {
        $store = app(ConversionArtifactStore::class);
        foreach (['a/.artifacts', '.artifacts', '.artifacts/b', 'x/.artifacts/y'] as $prefix) {
            try {
                $store->rootFor($prefix);
                $this->fail("prefix [{$prefix}] must be refused");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('reserved', $e->getMessage());
            }
        }
        $this->assertSame('a/artifacts/.artifacts', $store->rootFor('a/artifacts'), 'only the exact reserved segment is refused');
        try {
            $store->pathFor('t', 'p', 'docs/x.md', str_repeat('a', 64), 'a/.artifacts');
            $this->fail('pathFor() goes through the same root');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('reserved', $e->getMessage());
        }
        // A stored pointer is held to the same rule: more than one `.artifacts` segment is not contained.
        try {
            $store->read('kb', 'a/.artifacts/.artifacts/t/p/x.md.versions/'.str_repeat('a', 64).'.md');
        } catch (\Throwable) {
            // read() never throws — it logs and returns null; the assertion below covers it
        }
        $this->assertNull($store->read('kb', 'a/.artifacts/.artifacts/t/p/x.md.versions/'.str_repeat('a', 64).'.md'));
        $this->expectException(\RuntimeException::class);
        $store->assertContainedOnDisk('kb', 'a/.artifacts/.artifacts/t/p/x.md.versions/'.str_repeat('a', 64).'.md');
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

    /** R14 — a removal the store refuses (a pointer outside the artifact root, a disk that refuses) is reported as NOT deleted, never masked by a raw "the path is not there" probe. */
    public function test_hard_delete_reports_a_refused_artifact_removal_as_not_deleted(): void
    {
        $doc = $this->version('v1', 'active', 'index a', "# Doc\n\nartifact a\n");
        // The pointer names a path the store will not touch (not under an artifact root) and that does not exist.
        KnowledgeDocument::withoutGlobalScopes()->whereKey($doc->id)->update(['markdown_path' => 'outside/not-an-artifact.md']);
        Storage::disk('kb')->assertMissing('outside/not-an-artifact.md');

        $result = app(DocumentDeleter::class)->delete($doc->fresh(), force: true);

        $this->assertDatabaseMissing('knowledge_documents', ['id' => $doc->id]);
        $this->assertFalse($result['artifact_deleted'], 'a refused removal is reported, even when the raw path happens not to exist');
    }

    /**
     * SEC-PATH-001 — on an object store the lexical check is the whole
     * check, so the key must be canonical before the root is derived:
     * `.artifacts/../outside.md` is refused without touching the disk.
     */
    public function test_containment_refuses_a_non_canonical_pointer_on_an_object_store_disk(): void
    {
        config(['filesystems.disks.objstore' => ['driver' => 's3', 'key' => 'k', 'secret' => 's', 'region' => 'eu-west-1', 'bucket' => 'b']]);
        $store = app(ConversionArtifactStore::class);
        foreach (['.artifacts/../outside.md', '.artifacts/t/../../outside.md', '.artifacts//t/p/x.md.versions/'.str_repeat('a', 64).'.md', '.artifacts\\t\\p\\x.md'] as $path) {
            $refused = false;
            try {
                $store->assertContainedOnDisk('objstore', $path);
            } catch (\RuntimeException $e) {
                $refused = true;
                $this->assertStringContainsString('not a canonical artifact path', $e->getMessage());
            }
            $this->assertTrue($refused, "[{$path}] must be refused");
        }
        $this->assertNull($store->read('kb', '.artifacts/../outside.md'));
        $this->assertSame(ConversionArtifactStore::FAILED, $store->remove('kb', '.artifacts/../outside.md'));
    }

    /**
     * ADR 0030 §3 — a hard delete whose storage key is held by somebody else
     * right now KEEPS the shared source file. The row is already gone; the
     * bytes are left for the orphan sweep to take once nothing references
     * them. Deleting anyway is precisely the race the key lock exists for:
     * the holder may be an ingest that has read those bytes and not yet
     * committed its row.
     */
    public function test_hard_delete_keeps_the_source_file_while_another_holder_owns_its_storage_key(): void
    {
        config(['kb.conversion_artifacts.source_lock_wait_seconds' => 0]);
        $doc = $this->version('v1', 'active', 'index a');
        Storage::disk('kb')->put('docs/dec.md', "# Doc\n\nthe shared source\n");
        $holder = SourceKeyLock::make('kb', 'docs/dec.md');
        $this->assertTrue($holder->get(), 'the test holds exactly the key the deleter needs');

        try {
            $result = app(DocumentDeleter::class)->delete($doc, force: true);
        } finally {
            $holder->release();
        }

        $this->assertDatabaseMissing('knowledge_documents', ['id' => $doc->id]);
        $this->assertFalse($result['file_deleted'], 'the file is kept while another holder owns the key');
        Storage::disk('kb')->assertExists('docs/dec.md');
    }

    /**
     * R14 / R43 — a cache store that cannot exclude concurrent holders
     * (apc, session, a custom store, the null driver) leaves the source
     * file in place rather than deleting it unguarded. Failing closed here
     * costs one orphan the sweep collects; failing open costs another
     * version its bytes.
     */
    public function test_hard_delete_keeps_the_source_file_when_the_cache_store_cannot_lock(): void
    {
        $doc = $this->version('v1', 'active', 'index a');
        Storage::disk('kb')->put('docs/dec.md', "# Doc\n\nthe shared source\n");
        Cache::extend('nolock', static fn ($app) => Cache::repository(new NoLockStore));
        config(['cache.stores.nolock' => ['driver' => 'nolock'], 'cache.default' => 'nolock']);
        $this->assertFalse(ConversionArtifactStore::cacheStoreCanLock(), 'the store cannot exclude anyone');

        $result = app(DocumentDeleter::class)->delete($doc, force: true);

        $this->assertDatabaseMissing('knowledge_documents', ['id' => $doc->id]);
        $this->assertFalse($result['file_deleted'], 'without an exclusive key the shared source is left to the sweep');
        Storage::disk('kb')->assertExists('docs/dec.md');
    }

    /**
     * R43 — the OFF state of the same knob. This round introduced a
     * cache-store-capability dependency into a delete path that never had
     * one; with artifacts off nothing else takes the storage key lock, so
     * the hard delete must take none either and remove the file even on a
     * store that cannot lock. Without this, reordering the two guards would
     * silently stop every artifacts-off deployment from reaping its sources
     * and no test would turn red.
     */
    public function test_with_artifacts_off_the_hard_delete_removes_the_source_even_on_a_store_that_cannot_lock(): void
    {
        config(['kb.conversion_artifacts.enabled' => false]);
        $doc = $this->version('v1', 'active', 'index a');
        Storage::disk('kb')->put('docs/dec.md', "# Doc\n\nthe shared source\n");
        Cache::extend('nolock', static fn ($app) => Cache::repository(new NoLockStore));
        config(['cache.stores.nolock' => ['driver' => 'nolock'], 'cache.default' => 'nolock']);
        $this->assertFalse(ConversionArtifactStore::cacheStoreCanLock(), 'the store cannot exclude anyone');

        $result = app(DocumentDeleter::class)->delete($doc, force: true);

        $this->assertDatabaseMissing('knowledge_documents', ['id' => $doc->id]);
        $this->assertTrue($result['file_deleted'], 'with artifacts off the lock is not taken, so it cannot refuse the delete');
        Storage::disk('kb')->assertMissing('docs/dec.md');
    }

    public function test_soft_delete_leaves_the_artifact_in_place(): void
    {
        $doc = $this->version('v1', 'active', 'index a', "# Doc\n\nartifact a\n");

        app(DocumentDeleter::class)->delete($doc, force: false);

        Storage::disk('kb')->assertExists((string) $doc->markdown_path);
    }
}
