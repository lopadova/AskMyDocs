<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Jobs\IngestDocumentJob;
use App\Models\KnowledgeDocument;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Services\Kb\Export\KbWikiExportService;
use App\Services\Kb\Versioning\ConversionArtifactStore;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * v8.38/W4c (ADR 0032 §10) — the `kb:import-wiki` CLI surface, the ONE
 * surface with local filesystem access to a folder previously produced by
 * `kb:export-wiki` (R44 — HTTP/MCP take a single document's markdown
 * instead, they have no folder to walk).
 */
final class KbImportWikiCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = app(TenantContext::class)->current();
        $this->folder = sys_get_temp_dir().'/kb-wiki-import-cmd-test-'.uniqid();

        Storage::fake('kb');
        Queue::fake();
        config([
            'kb.wiki_export.enabled' => true,
            'kb.sources.disk' => 'kb',
            'kb.sources.path_prefix' => '',
            'kb.conversion_artifacts.enabled' => true,
        ]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->folder)) {
            $this->rrmdir($this->folder);
        }

        parent::tearDown();
    }

    private function rrmdir(string $dir): void
    {
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.'/'.$item;
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function makeMember(string $projectKey = 'default'): User
    {
        $user = User::create([
            'name' => 'Roundtripper',
            'email' => 'roundtripper-'.uniqid().'@example.test',
            'password' => Hash::make('secret-secret'),
        ])->fresh();

        ProjectMembership::create([
            'tenant_id' => $this->tenantId,
            'user_id' => $user->id,
            'project_key' => $projectKey,
            'role' => 'member',
        ]);

        return $user;
    }

    public function test_it_refuses_when_the_flag_is_disabled(): void
    {
        config(['kb.wiki_export.enabled' => false]);

        $this->artisan('kb:import-wiki', [
            'folder' => $this->folder,
            '--tenant' => $this->tenantId,
            '--as-user' => 'nobody@example.test',
        ])->assertFailed();
    }

    public function test_it_refuses_without_a_tenant(): void
    {
        $this->artisan('kb:import-wiki', [
            'folder' => $this->folder,
            '--as-user' => 'nobody@example.test',
        ])->assertFailed();
    }

    public function test_it_refuses_an_unknown_user_email(): void
    {
        $this->artisan('kb:import-wiki', [
            'folder' => $this->folder,
            '--tenant' => $this->tenantId,
            '--as-user' => 'does-not-exist@example.test',
        ])->assertFailed();
    }

    public function test_it_refuses_a_nonexistent_folder(): void
    {
        $user = $this->makeMember();

        $this->artisan('kb:import-wiki', [
            'folder' => '/does/not/exist/'.uniqid(),
            '--tenant' => $this->tenantId,
            '--as-user' => $user->email,
        ])->assertFailed();
    }

    /**
     * Full round-trip: export a canonical document, then re-import the
     * SAME unmodified folder. Every page must come back `unchanged` — a
     * real export from `kb:export-wiki` must never look "edited" to
     * `kb:import-wiki` the moment it lands on disk.
     */
    public function test_unmodified_round_trip_reports_every_page_unchanged(): void
    {
        $user = $this->makeMember();
        $content = "Step one. Step two.\n";
        $hash = hash('sha256', $content);
        $store = app(ConversionArtifactStore::class);
        $final = $store->pathFor($this->tenantId, 'default', 'runbooks/deploy.md', $hash);
        $store->publishUnderOwnLock('kb', $store->writeTemp('kb', $final, $content), $final);

        KnowledgeDocument::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenantId,
            'project_key' => 'default',
            'source_type' => 'markdown',
            'title' => 'Deploy runbook',
            'source_path' => 'runbooks/deploy.md',
            'mime_type' => 'text/markdown',
            'language' => 'en',
            'status' => 'active',
            'document_hash' => $hash,
            'version_hash' => $hash,
            'metadata' => ['disk' => 'kb', 'prefix' => ''],
            'markdown_path' => $final,
            'content_hash' => $hash,
            'slug' => 'deploy-runbook',
            'doc_id' => 'deploy-runbook-id',
            'canonical_type' => 'runbook',
            'canonical_status' => 'accepted',
        ]);

        app(KbWikiExportService::class)->export($this->tenantId, 'default', $user, $this->folder);

        $this->artisan('kb:import-wiki', [
            'folder' => $this->folder,
            '--tenant' => $this->tenantId,
            '--as-user' => $user->email,
        ])
            ->expectsOutputToContain('1 unchanged, 0 proposed')
            ->assertSuccessful();

        Queue::assertNotPushed(IngestDocumentJob::class);
    }

    /**
     * The round-trip's whole point: editing the exported page and
     * re-importing proposes a candidate, never a direct write.
     */
    public function test_an_edited_page_is_proposed_as_a_candidate(): void
    {
        $user = $this->makeMember();
        $content = "Step one. Step two.\n";
        $hash = hash('sha256', $content);
        $store = app(ConversionArtifactStore::class);
        $final = $store->pathFor($this->tenantId, 'default', 'runbooks/deploy.md', $hash);
        $store->publishUnderOwnLock('kb', $store->writeTemp('kb', $final, $content), $final);

        $doc = KnowledgeDocument::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenantId,
            'project_key' => 'default',
            'source_type' => 'markdown',
            'title' => 'Deploy runbook',
            'source_path' => 'runbooks/deploy.md',
            'mime_type' => 'text/markdown',
            'language' => 'en',
            'status' => 'active',
            'document_hash' => $hash,
            'version_hash' => $hash,
            'metadata' => ['disk' => 'kb', 'prefix' => ''],
            'markdown_path' => $final,
            'content_hash' => $hash,
            'slug' => 'deploy-runbook',
            'doc_id' => 'deploy-runbook-id',
            'canonical_type' => 'runbook',
            'canonical_status' => 'accepted',
        ]);

        app(KbWikiExportService::class)->export($this->tenantId, 'default', $user, $this->folder);

        $wikiFile = $this->folder.'/wiki/'.$doc->id.'-deploy-runbook.md';
        $this->assertFileExists($wikiFile);
        $edited = str_replace('Step one. Step two.', 'Step one. Step two. Step three, added by hand.', (string) file_get_contents($wikiFile));
        file_put_contents($wikiFile, $edited);

        $this->artisan('kb:import-wiki', [
            'folder' => $this->folder,
            '--tenant' => $this->tenantId,
            '--as-user' => $user->email,
        ])
            ->expectsOutputToContain('0 unchanged, 1 proposed')
            ->assertSuccessful();

        // Never a direct write — approval-gated promotion candidate only.
        Storage::disk('kb')->assertMissing('runbooks/deploy-runbook.md');
        Queue::assertNotPushed(IngestDocumentJob::class);
    }
}
