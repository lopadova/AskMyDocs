<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\Export;

use App\Models\KnowledgeDocument;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Services\Kb\Export\KbWikiExportService;
use App\Services\Kb\Versioning\ConversionArtifactStore;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Padosoft\PiiRedactor\RedactorEngine;
use Padosoft\PiiRedactor\Strategies\RedactionStrategy;
use Padosoft\PiiRedactor\Strategies\RedactionStrategyFactory;
use Padosoft\PiiRedactor\TokenStore\TokenStore;
use Tests\TestCase;

/**
 * v8.38/W4a (ADR 0032) — the synchronous export core.
 *
 * Mirrors two existing suites rather than inventing new fixtures:
 * `RetrievalScopeAllowlistTest` for the ACL-scoping shape (R33 — the export
 * MUST see exactly what the acting user's `AccessScopeScope` would resolve,
 * never the whole project) and `DocumentIngestorInlinePiiTest` for the PII
 * both-states gate (R43 — mirrors `ChunkRedactor`'s own gating verbatim).
 */
final class KbWikiExportServiceTest extends TestCase
{
    use RefreshDatabase;

    private const IN_SCOPE = 'hr/policies/remote-work.md';

    private const OUT_OF_SCOPE = 'hr/salaries/exec-comp.md';

    private string $tenantId;

    private string $projectKey = 'default';

    private string $outputDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = app(TenantContext::class)->current();
        $this->outputDir = sys_get_temp_dir().'/kb-wiki-export-test-'.uniqid();

        Storage::fake('kb');
        config([
            'kb.sources.disk' => 'kb',
            'kb.sources.path_prefix' => '',
            'kb.conversion_artifacts.enabled' => true,
            'pii-redactor.enabled' => false,
            'kb.pii_redactor.enabled' => false,
        ]);
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

    private function makeUser(): User
    {
        return User::create([
            'name' => 'Exporter',
            'email' => 'exporter-'.uniqid().'@example.test',
            'password' => Hash::make('secret-secret'),
        ])->fresh();
    }

    private function membership(User $user, array $scopeAllowlist = []): void
    {
        ProjectMembership::create([
            'tenant_id' => $this->tenantId,
            'user_id' => $user->id,
            'project_key' => $this->projectKey,
            'role' => 'member',
            'scope_allowlist' => $scopeAllowlist === [] ? null : $scopeAllowlist,
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function documentWithArtifact(string $path, string $content, array $extra = []): KnowledgeDocument
    {
        $hash = hash('sha256', $content);
        $store = app(ConversionArtifactStore::class);
        $final = $store->pathFor($this->tenantId, $this->projectKey, $path, $hash);
        $store->publishUnderOwnLock('kb', $store->writeTemp('kb', $final, $content), $final);

        return KnowledgeDocument::withoutGlobalScopes()->create(array_merge([
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
            'metadata' => ['disk' => 'kb', 'prefix' => ''],
            'markdown_path' => $final,
            'content_hash' => $hash,
        ], $extra));
    }

    /**
     * A version row with no stored artifact — `readArtifact()` returns
     * `ARTIFACT_NONE` since `markdown_path` is unset.
     */
    private function documentWithoutArtifact(string $path): KnowledgeDocument
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

    public function test_export_only_includes_documents_the_acting_user_can_read_via_acl_scope(): void
    {
        $this->documentWithArtifact(self::IN_SCOPE, "# Remote work\n\nAllowed on Fridays.\n");
        $this->documentWithArtifact(self::OUT_OF_SCOPE, "# Exec comp\n\nCFO base salary is 250000 EUR.\n");

        $user = $this->makeUser();
        $this->membership($user, ['folder_globs' => ['hr/policies/**']]);

        $result = app(KbWikiExportService::class)->export($this->tenantId, $this->projectKey, $user, $this->outputDir);

        $this->assertSame(1, $result['document_count'], 'The salary document must not have been readable by a user scoped to hr/policies/**.');
        $this->assertSame('complete', $result['status']);
        $this->assertSame([], $result['raw_missing']);

        $wikiFiles = glob($this->outputDir.'/wiki/*.md');
        $rawFiles = glob($this->outputDir.'/raw/*.md');
        $this->assertCount(1, $wikiFiles);
        $this->assertCount(1, $rawFiles);
        $this->assertStringContainsString('Allowed on Fridays', (string) file_get_contents($rawFiles[0]));
    }

    public function test_artifact_missing_produces_raw_missing_entry_and_partial_status(): void
    {
        $doc = $this->documentWithoutArtifact('runbooks/incident-response.md');
        $user = $this->makeUser();
        $this->membership($user);

        $result = app(KbWikiExportService::class)->export($this->tenantId, $this->projectKey, $user, $this->outputDir);

        $this->assertSame('partial', $result['status']);
        $this->assertCount(1, $result['raw_missing']);
        $this->assertSame($doc->id, $result['raw_missing'][0]['document_id']);
        $this->assertSame('none', $result['raw_missing'][0]['reason']);

        $this->assertFileDoesNotExist($this->outputDir.'/raw/'.$doc->id.'.md');
        $this->assertFileExists($this->outputDir.'/wiki/'.$doc->id.'.md');

        $manifest = json_decode((string) file_get_contents($this->outputDir.'/MANIFEST.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('partial', $manifest['status']);
        $this->assertCount(1, $manifest['raw_missing']);
    }

    public function test_frontmatter_fields_are_derived_from_document_canonical_columns(): void
    {
        $doc = $this->documentWithArtifact('runbooks/deploy.md', "# Deploy\n\nSteps.\n", [
            'generation_source' => 'auto',
            'evidence_tier' => 'official',
            'provenance_tier' => 'verified-employee',
            'canonical_type' => 'runbook',
            'metadata' => ['disk' => 'kb', 'prefix' => '', 'converter' => ['provenance' => 'ocr']],
        ]);
        $user = $this->makeUser();
        $this->membership($user);

        app(KbWikiExportService::class)->export($this->tenantId, $this->projectKey, $user, $this->outputDir);

        $page = (string) file_get_contents($this->outputDir.'/wiki/'.$doc->id.'.md');
        $this->assertStringContainsString('tier: auto', $page);
        $this->assertStringContainsString('evidence_tier: official', $page);
        $this->assertStringContainsString('provenance_tier: verified-employee', $page);
        $this->assertStringContainsString('extraction: ocr', $page);
        $this->assertStringContainsString('canonical_type: runbook', $page);
    }

    public function test_manifest_lists_every_written_file_with_a_matching_sha256_and_a_chain_hash(): void
    {
        $this->documentWithArtifact('runbooks/deploy.md', "# Deploy\n\nSteps.\n");
        $user = $this->makeUser();
        $this->membership($user);

        app(KbWikiExportService::class)->export($this->tenantId, $this->projectKey, $user, $this->outputDir);

        $manifest = json_decode((string) file_get_contents($this->outputDir.'/MANIFEST.json'), true, flags: JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('files', $manifest);
        $this->assertArrayHasKey('README.md', $manifest['files']);
        $this->assertArrayHasKey('AGENTS.md', $manifest['files']);
        $this->assertArrayHasKey('CLAUDE.md', $manifest['files']);

        foreach ($manifest['files'] as $relativePath => $expectedHash) {
            $actual = hash('sha256', (string) file_get_contents($this->outputDir.'/'.$relativePath));
            $this->assertSame($expectedHash, $actual, "Manifest hash for {$relativePath} does not match the file on disk.");
        }

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $manifest['chain_hash']);
    }

    public function test_export_restores_the_previous_tenant_and_authenticated_user_after_running(): void
    {
        $this->documentWithArtifact(self::IN_SCOPE, "# Remote work\n\nAllowed on Fridays.\n");

        $previousUser = $this->makeUser();
        $this->membership($previousUser);
        $this->actingAs($previousUser, 'web');

        $exportingUser = $this->makeUser();
        $this->membership($exportingUser);

        app(KbWikiExportService::class)->export($this->tenantId, $this->projectKey, $exportingUser, $this->outputDir);

        $this->assertSame($previousUser->id, Auth::guard('web')->id());
        $this->assertSame($this->tenantId, app(TenantContext::class)->current());
    }

    /**
     * Mirrors DocumentIngestorInlinePiiTest::enableRedaction() — rebuilds the
     * config-derived singletons so they pick up the test config.
     */
    private function enableRedaction(string $strategy): void
    {
        config([
            'pii-redactor.enabled' => true,
            'pii-redactor.salt' => 'wiki-export-test-salt',
            'pii-redactor.token_store.driver' => 'memory',
            'kb.pii_redactor.enabled' => true,
            // KbPiiPolicyResolver::layer() reads THIS key for the per-project
            // default (the "enabled" key above is only the master kill-switch
            // checked at the call site) — mirrors DocumentIngestorInlinePiiTest.
            'kb.pii_redactor.redact_inline_ingest' => true,
            'kb.pii_redactor.ingest_strategy' => $strategy,
        ]);
        foreach ([RedactorEngine::class, RedactionStrategyFactory::class, RedactionStrategy::class, TokenStore::class] as $abstract) {
            $this->app->forgetInstance($abstract);
        }
    }

    public function test_raw_content_is_left_untouched_when_the_pii_policy_is_inactive(): void
    {
        $this->documentWithArtifact('support/ticket-123.md', "# Ticket\n\nContact mario.rossi@example.com about it.\n");
        $user = $this->makeUser();
        $this->membership($user);

        app(KbWikiExportService::class)->export($this->tenantId, $this->projectKey, $user, $this->outputDir);

        $rawFiles = glob($this->outputDir.'/raw/*.md');
        $this->assertCount(1, $rawFiles);
        $this->assertStringContainsString('mario.rossi@example.com', (string) file_get_contents($rawFiles[0]));
    }

    public function test_raw_content_is_redacted_when_the_pii_policy_is_active(): void
    {
        $this->enableRedaction('mask');
        $this->documentWithArtifact('support/ticket-123.md', "# Ticket\n\nContact mario.rossi@example.com about it.\n");
        $user = $this->makeUser();
        $this->membership($user);

        app(KbWikiExportService::class)->export($this->tenantId, $this->projectKey, $user, $this->outputDir);

        $rawFiles = glob($this->outputDir.'/raw/*.md');
        $this->assertCount(1, $rawFiles);
        $content = (string) file_get_contents($rawFiles[0]);
        $this->assertStringNotContainsString('mario.rossi@example.com', $content);
        $this->assertStringContainsString('[REDACTED]', $content);
    }
}
