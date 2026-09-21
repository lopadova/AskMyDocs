<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\KnowledgeDocument;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * v8.38/W4a (ADR 0032) — the `kb:export-wiki` CLI surface (R44 — one of
 * three; HTTP/MCP land in W4b/W4c over the same {@see \App\Services\Kb\Export\KbWikiExportService}).
 */
final class KbExportWikiCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    private string $outputDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = app(TenantContext::class)->current();
        $this->outputDir = sys_get_temp_dir().'/kb-wiki-export-cmd-test-'.uniqid();

        config(['kb.wiki_export.enabled' => true]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->outputDir)) {
            $this->rrmdir($this->outputDir);
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
            'name' => 'Exporter',
            'email' => 'exporter-'.uniqid().'@example.test',
            'password' => Hash::make('secret-secret'),
        ])->fresh();

        ProjectMembership::create([
            'tenant_id' => $this->tenantId,
            'user_id' => $user->id,
            'project_key' => $projectKey,
            'role' => 'member',
            'scope_allowlist' => null,
        ]);

        return $user;
    }

    public function test_it_refuses_when_the_flag_is_disabled(): void
    {
        config(['kb.wiki_export.enabled' => false]);

        $this->artisan('kb:export-wiki', [
            '--tenant' => $this->tenantId,
            '--project' => 'default',
            '--as-user' => 'nobody@example.test',
        ])->assertFailed();
    }

    public function test_it_refuses_without_a_project(): void
    {
        $this->artisan('kb:export-wiki', [
            '--tenant' => $this->tenantId,
            '--as-user' => 'nobody@example.test',
        ])->assertFailed();
    }

    public function test_it_refuses_without_an_as_user(): void
    {
        $this->artisan('kb:export-wiki', [
            '--tenant' => $this->tenantId,
            '--project' => 'default',
        ])->assertFailed();
    }

    public function test_it_refuses_an_unknown_user_email(): void
    {
        $this->artisan('kb:export-wiki', [
            '--tenant' => $this->tenantId,
            '--project' => 'default',
            '--as-user' => 'does-not-exist@example.test',
        ])->assertFailed();
    }

    public function test_it_refuses_a_user_with_no_membership_in_the_tenant(): void
    {
        $user = User::create([
            'name' => 'Outsider',
            'email' => 'outsider-'.uniqid().'@example.test',
            'password' => Hash::make('secret-secret'),
        ]);

        $this->artisan('kb:export-wiki', [
            '--tenant' => $this->tenantId,
            '--project' => 'default',
            '--as-user' => $user->email,
        ])->assertFailed();
    }

    public function test_happy_path_writes_the_export_and_reports_success(): void
    {
        $user = $this->makeMember('default');

        KnowledgeDocument::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenantId,
            'project_key' => 'default',
            'source_type' => 'markdown',
            'title' => 'Deploy runbook',
            'source_path' => 'runbooks/deploy.md',
            'mime_type' => 'text/markdown',
            'language' => 'en',
            'status' => 'active',
            'document_hash' => hash('sha256', 'deploy'),
            'version_hash' => hash('sha256', 'deploy'),
        ]);

        $this->artisan('kb:export-wiki', [
            '--tenant' => $this->tenantId,
            '--project' => 'default',
            '--as-user' => $user->email,
            '--output' => $this->outputDir,
        ])
            ->expectsOutputToContain('Export written to')
            ->assertSuccessful();

        $this->assertFileExists($this->outputDir.'/MANIFEST.json');
        $this->assertFileExists($this->outputDir.'/README.md');
    }
}
