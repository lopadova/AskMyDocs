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

    /**
     * Copilot review finding (PR #503) — setUser() cannot accept null, so
     * restoring an unauthenticated "before" state requires dropping the
     * resolved guard entirely, not skipping restoration.
     */
    public function test_export_forgets_the_guard_when_there_was_no_previous_user(): void
    {
        Auth::forgetGuards();
        $this->assertNull(Auth::guard('web')->user());

        $this->documentWithArtifact(self::IN_SCOPE, "# Remote work\n\nAllowed on Fridays.\n");
        $exportingUser = $this->makeUser();
        $this->membership($exportingUser);

        app(KbWikiExportService::class)->export($this->tenantId, $this->projectKey, $exportingUser, $this->outputDir);

        $this->assertNull(
            Auth::guard('web')->user(),
            'The export principal must not leak into a process that had no authenticated user before the export.',
        );
    }

    /**
     * Copilot review finding (PR #503) — a reused destination can carry
     * documents a narrower re-export no longer covers, while MANIFEST.json
     * faithfully describes only the new, smaller set.
     */
    public function test_it_refuses_to_write_into_a_non_empty_destination(): void
    {
        $this->documentWithArtifact(self::IN_SCOPE, "# Remote work\n\nAllowed on Fridays.\n");
        $user = $this->makeUser();
        $this->membership($user);

        mkdir($this->outputDir, 0755, true);
        file_put_contents($this->outputDir.'/leftover-from-a-previous-export.txt', 'stale');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not empty/');

        app(KbWikiExportService::class)->export($this->tenantId, $this->projectKey, $user, $this->outputDir);
    }

    /**
     * Copilot review finding (PR #503) — a non-canonical document's filename
     * falls back to its numeric id; a DIFFERENT document with a canonical
     * slug equal to that same numeric string must not collapse onto it.
     */
    public function test_filenames_never_collide_even_when_a_slug_equals_another_documents_id(): void
    {
        $docWithoutSlug = $this->documentWithArtifact('runbooks/a.md', "# A\n\nBody A.\n");
        $this->documentWithArtifact('runbooks/b.md', "# B\n\nBody B.\n", [
            'slug' => (string) $docWithoutSlug->id,
        ]);
        $user = $this->makeUser();
        $this->membership($user);

        $result = app(KbWikiExportService::class)->export($this->tenantId, $this->projectKey, $user, $this->outputDir);

        $this->assertSame(2, $result['document_count']);
        $wikiFiles = glob($this->outputDir.'/wiki/*.md');
        $rawFiles = glob($this->outputDir.'/raw/*.md');
        $this->assertCount(2, $wikiFiles, 'Two documents must never collapse onto the same wiki filename.');
        $this->assertCount(2, $rawFiles, 'Two documents must never collapse onto the same raw filename.');
    }

    /**
     * Copilot review finding (PR #503) — every wiki/*.md previously shipped
     * frontmatter + a placeholder sentence only, never the document body.
     */
    public function test_wiki_page_body_contains_the_documents_actual_content(): void
    {
        $doc = $this->documentWithArtifact('runbooks/deploy.md', "# Deploy\n\nStep one. Step two.\n");
        $user = $this->makeUser();
        $this->membership($user);

        app(KbWikiExportService::class)->export($this->tenantId, $this->projectKey, $user, $this->outputDir);

        $page = (string) file_get_contents($this->outputDir.'/wiki/'.$doc->id.'.md');
        $this->assertStringContainsString('Step one. Step two.', $page);
        $this->assertStringNotContainsString('this slice writes frontmatter + title only', $page);
    }

    /**
     * Copilot review finding (PR #503, round 2) — ADR 0032 §7: the export
     * inherits the tenant PII policy for the whole export, not only `raw/`.
     * `wiki/` is the primary readable copy and would otherwise carry the
     * exact PII `raw/` was redacted to keep off disk.
     */
    public function test_wiki_page_body_is_redacted_when_the_pii_policy_is_active(): void
    {
        $this->enableRedaction('mask');
        $doc = $this->documentWithArtifact('support/ticket-123.md', "# Ticket\n\nContact mario.rossi@example.com about it.\n");
        $user = $this->makeUser();
        $this->membership($user);

        app(KbWikiExportService::class)->export($this->tenantId, $this->projectKey, $user, $this->outputDir);

        $page = (string) file_get_contents($this->outputDir.'/wiki/'.$doc->id.'.md');
        $this->assertStringNotContainsString('mario.rossi@example.com', $page);
        $this->assertStringContainsString('[REDACTED]', $page);
    }

    /**
     * Copilot review finding (PR #503, round 2) — `AccessScopeScope::apply()`
     * resolves the principal through `auth()->user()`, i.e. the app's
     * DEFAULT guard, not necessarily one named `web`. Hardcoding
     * `Auth::guard('web')` would silently bypass ACL filtering on a
     * deployment where `AUTH_GUARD` names a different guard.
     */
    public function test_acl_scoping_holds_when_the_default_guard_is_not_named_web(): void
    {
        config(['auth.guards.other_default' => ['driver' => 'session', 'provider' => 'users']]);
        config(['auth.defaults.guard' => 'other_default']);
        $this->app->forgetInstance('auth');
        Auth::forgetGuards();

        try {
            $this->documentWithArtifact(self::IN_SCOPE, "# Remote work\n\nAllowed on Fridays.\n");
            $this->documentWithArtifact(self::OUT_OF_SCOPE, "# Exec comp\n\nCFO base salary is 250000 EUR.\n");

            $user = $this->makeUser();
            $this->membership($user, ['folder_globs' => ['hr/policies/**']]);

            $result = app(KbWikiExportService::class)->export($this->tenantId, $this->projectKey, $user, $this->outputDir);

            $this->assertSame(
                1,
                $result['document_count'],
                'ACL scoping must hold even when the app default guard is not "web".',
            );
        } finally {
            config(['auth.defaults.guard' => 'web']);
            $this->app->forgetInstance('auth');
            Auth::forgetGuards();
        }
    }

    /**
     * Copilot review finding (PR #503, round 3) — tenant/guard mutation used
     * to happen BEFORE the try block, so an exception during setup itself
     * would skip the finally entirely. Moved inside the try; this proves
     * the general contract — any failure during export(), wherever it
     * originates, still leaves tenant/guard state restored.
     */
    public function test_tenant_and_guard_state_are_restored_when_the_export_itself_fails(): void
    {
        $this->documentWithArtifact(self::IN_SCOPE, "# Remote work\n\nAllowed on Fridays.\n");

        $previousUser = $this->makeUser();
        $this->membership($previousUser);
        $this->actingAs($previousUser, 'web');
        $previousTenant = app(TenantContext::class)->current();

        $exportingUser = $this->makeUser();
        $this->membership($exportingUser);

        mkdir($this->outputDir, 0755, true);
        file_put_contents($this->outputDir.'/leftover.txt', 'stale');

        try {
            app(KbWikiExportService::class)->export($this->tenantId, $this->projectKey, $exportingUser, $this->outputDir);
            $this->fail('Expected the non-empty destination refusal to propagate.');
        } catch (\RuntimeException) {
            // expected — assertions below are the point of the test.
        }

        $this->assertSame($previousTenant, app(TenantContext::class)->current());
        $this->assertSame($previousUser->id, Auth::id());
    }

    /**
     * Copilot review finding (PR #503, round 4) — the empty-destination
     * check and the writes that follow were not atomic: two exports
     * targeting the same explicit directory could both observe it as
     * empty, then interleave files into a folder matching neither
     * manifest. Proves the fix by holding the reservation lock the exact
     * way a concurrent export would, before this export ever starts.
     */
    public function test_a_concurrent_export_to_the_same_destination_is_refused_before_any_write(): void
    {
        $this->documentWithArtifact(self::IN_SCOPE, "# Remote work\n\nAllowed on Fridays.\n");
        $user = $this->makeUser();
        $this->membership($user);

        mkdir($this->outputDir, 0755, true);
        $externalHandle = fopen($this->outputDir.'/.export.lock', 'c');
        $this->assertNotFalse($externalHandle);
        $this->assertTrue(flock($externalHandle, LOCK_EX | LOCK_NB), 'Test setup could not acquire the lock it needs to simulate a concurrent holder.');

        try {
            app(KbWikiExportService::class)->export($this->tenantId, $this->projectKey, $user, $this->outputDir);
            $this->fail('Expected the concurrent-holder lock to refuse this export.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('already in progress', $e->getMessage());
        } finally {
            flock($externalHandle, LOCK_UN);
            fclose($externalHandle);
        }

        $this->assertSame([], glob($this->outputDir.'/wiki/*.md') ?: [], 'The refused export must not have written anything.');
    }
}
