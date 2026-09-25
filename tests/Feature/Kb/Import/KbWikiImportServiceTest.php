<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\Import;

use App\Exceptions\KbWikiImportRateLimitedException;
use App\Jobs\IngestDocumentJob;
use App\Models\KbWikiImportCandidate;
use App\Models\KnowledgeDocument;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Services\Kb\Import\KbWikiImportService;
use App\Services\Kb\Versioning\ConversionArtifactStore;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * v8.38/W4c (ADR 0032 §10/§11) — the shared core behind `kb:import-wiki`,
 * `POST /api/admin/kb/imports`, and `KbImportWikiTool`.
 *
 * Runs the REAL PromotionFlow saga (no mocking `Flow::execute()`), same
 * posture as `KbPromotionControllerTest`: `Queue::fake()` + `Storage::fake('kb')`
 * keep the approval-gated write from actually landing on disk or dispatching
 * ingest — this suite only asserts the PROPOSAL half (never applied
 * content).
 */
final class KbWikiImportServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    private string $projectKey = 'default';

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = app(TenantContext::class)->current();

        Storage::fake('kb');
        Queue::fake();
        config([
            'kb.wiki_export.enabled' => true,
            'kb.wiki_export.import_candidates_per_hour' => 30,
            'kb.sources.disk' => 'kb',
            'kb.sources.path_prefix' => '',
            'kb.conversion_artifacts.enabled' => true,
        ]);
    }

    private function makeUser(): User
    {
        $user = User::create([
            'name' => 'Importer',
            'email' => 'importer-'.uniqid().'@example.test',
            'password' => Hash::make('secret-secret'),
        ])->fresh();

        ProjectMembership::create([
            'tenant_id' => $this->tenantId,
            'user_id' => $user->id,
            'project_key' => $this->projectKey,
            'role' => 'member',
        ]);

        return $user;
    }

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

    private function markdownFor(string $slug, string $type, string $status, string $body): string
    {
        return <<<MD
        ---
        id: {$slug}-id
        slug: {$slug}
        type: {$type}
        status: {$status}
        ---

        {$body}
        MD;
    }

    public function test_returns_disabled_when_the_feature_flag_is_off(): void
    {
        config(['kb.wiki_export.enabled' => false]);
        $user = $this->makeUser();

        $result = app(KbWikiImportService::class)->importDocument(
            $this->tenantId, $this->projectKey,
            $this->markdownFor('deploy-runbook', 'runbook', 'accepted', "# Deploy\n\nBody.\n"),
            $user, KbWikiImportCandidate::SOURCE_CLI,
        );

        $this->assertSame('disabled', $result['status']);
    }

    public function test_returns_invalid_when_frontmatter_is_missing(): void
    {
        $user = $this->makeUser();

        $result = app(KbWikiImportService::class)->importDocument(
            $this->tenantId, $this->projectKey,
            "# Just a heading\n\nNo frontmatter.",
            $user, KbWikiImportCandidate::SOURCE_CLI,
        );

        $this->assertSame('invalid', $result['status']);
        $this->assertArrayHasKey('frontmatter', $result['errors']);
    }

    public function test_returns_invalid_when_slug_is_missing(): void
    {
        $user = $this->makeUser();

        $result = app(KbWikiImportService::class)->importDocument(
            $this->tenantId, $this->projectKey,
            "---\ntype: runbook\nstatus: accepted\n---\n\n# Body",
            $user, KbWikiImportCandidate::SOURCE_CLI,
        );

        $this->assertSame('invalid', $result['status']);
        $this->assertArrayHasKey('slug', $result['errors']);
    }

    public function test_reports_unchanged_when_the_body_matches_the_server(): void
    {
        $doc = $this->documentWithArtifact('runbooks/deploy.md', "Step one. Step two.\n", [
            'slug' => 'deploy-runbook',
            'doc_id' => 'deploy-runbook-id',
            'canonical_type' => 'runbook',
            'canonical_status' => 'accepted',
        ]);
        $user = $this->makeUser();

        $result = app(KbWikiImportService::class)->importDocument(
            $this->tenantId, $this->projectKey,
            $this->markdownFor('deploy-runbook', 'runbook', 'accepted', "Step one. Step two.\n"),
            $user, KbWikiImportCandidate::SOURCE_CLI,
        );

        $this->assertSame('unchanged', $result['status']);
        $this->assertSame($doc->id, $result['document_id']);
        $this->assertSame(0, KbWikiImportCandidate::query()->forTenant($this->tenantId)->count(), 'An unchanged page must never start a promotion flow.');
    }

    /**
     * Independent-review finding (PR #509) — a body-only diff silently
     * dropped a frontmatter-only edit (e.g. demoting canonical_status from
     * accepted to deprecated with the body left untouched). The body is
     * byte-identical here; only `status` differs — this must still be
     * proposed, never reported "unchanged".
     */
    public function test_proposes_a_candidate_for_a_frontmatter_only_edit(): void
    {
        $this->documentWithArtifact('runbooks/deploy.md', "Step one. Step two.\n", [
            'slug' => 'deploy-runbook',
            'doc_id' => 'deploy-runbook-id',
            'canonical_type' => 'runbook',
            'canonical_status' => 'accepted',
        ]);
        $user = $this->makeUser();

        $result = app(KbWikiImportService::class)->importDocument(
            $this->tenantId, $this->projectKey,
            $this->markdownFor('deploy-runbook', 'runbook', 'deprecated', "Step one. Step two.\n"),
            $user, KbWikiImportCandidate::SOURCE_CLI,
        );

        $this->assertSame('created', $result['status'], 'A status-only edit (body unchanged) must still be proposed, never silently dropped.');
    }

    public function test_proposes_a_candidate_for_an_edited_existing_document(): void
    {
        $this->documentWithArtifact('runbooks/deploy.md', "Old body.\n", [
            'slug' => 'deploy-runbook',
            'doc_id' => 'deploy-runbook-id',
            'canonical_type' => 'runbook',
            'canonical_status' => 'accepted',
        ]);
        $user = $this->makeUser();

        $result = app(KbWikiImportService::class)->importDocument(
            $this->tenantId, $this->projectKey,
            $this->markdownFor('deploy-runbook', 'runbook', 'accepted', "New, edited body.\n"),
            $user, KbWikiImportCandidate::SOURCE_CLI,
        );

        $this->assertSame('created', $result['status']);
        $this->assertSame('deploy-runbook', $result['slug']);
        $this->assertArrayHasKey('approval', $result);
        $this->assertNotNull($result['approval']);
        $this->assertArrayHasKey('token', $result['approval']);

        // Never a direct write — the promotion candidate is paused at the
        // approval gate, exactly like KbPromotionController::promote().
        Storage::disk('kb')->assertMissing('runbooks/deploy-runbook.md');
        Queue::assertNotPushed(IngestDocumentJob::class);

        $this->assertDatabaseHas('kb_wiki_import_candidates', [
            'tenant_id' => $this->tenantId,
            'project_key' => $this->projectKey,
            'slug' => 'deploy-runbook',
            'requested_by' => $user->id,
            'source' => KbWikiImportCandidate::SOURCE_CLI,
        ]);
    }

    public function test_proposes_a_candidate_for_a_new_document(): void
    {
        $user = $this->makeUser();

        $result = app(KbWikiImportService::class)->importDocument(
            $this->tenantId, $this->projectKey,
            $this->markdownFor('brand-new-page', 'runbook', 'accepted', "# Brand new\n\nBody.\n"),
            $user, KbWikiImportCandidate::SOURCE_CLI,
        );

        $this->assertSame('created', $result['status']);
        $this->assertNotNull($result['approval']);
    }

    public function test_a_replayed_identical_call_reuses_the_same_flow_run(): void
    {
        $user = $this->makeUser();
        $markdown = $this->markdownFor('brand-new-page', 'runbook', 'accepted', "# Brand new\n\nBody.\n");

        $first = app(KbWikiImportService::class)->importDocument($this->tenantId, $this->projectKey, $markdown, $user, KbWikiImportCandidate::SOURCE_CLI);
        $second = app(KbWikiImportService::class)->importDocument($this->tenantId, $this->projectKey, $markdown, $user, KbWikiImportCandidate::SOURCE_CLI);

        $this->assertSame('created', $first['status']);
        $this->assertNotNull($first['approval']);
        $this->assertSame('replayed', $second['status']);
        $this->assertSame($first['flow_run_id'], $second['flow_run_id']);
        $this->assertNull($second['approval'], 'A replay must not claim to mint a second usable approval token.');
        $this->assertSame(1, KbWikiImportCandidate::query()->forTenant($this->tenantId)->count(), 'A replayed call must not create a second candidate row.');
    }

    public function test_enforces_the_per_actor_rate_limit(): void
    {
        config(['kb.wiki_export.import_candidates_per_hour' => 1]);
        $user = $this->makeUser();
        $service = app(KbWikiImportService::class);

        $service->importDocument($this->tenantId, $this->projectKey, $this->markdownFor('page-one', 'runbook', 'accepted', "Body one.\n"), $user, KbWikiImportCandidate::SOURCE_CLI);

        $this->expectException(KbWikiImportRateLimitedException::class);
        $service->importDocument($this->tenantId, $this->projectKey, $this->markdownFor('page-two', 'runbook', 'accepted', "Body two.\n"), $user, KbWikiImportCandidate::SOURCE_CLI);
    }

    /**
     * ADR 0032 §5's principal-restoration discipline, restated for import:
     * a leaked principal on a reused process is a cross-request ACL bypass,
     * not merely a correctness bug.
     */
    public function test_restores_the_previous_tenant_and_authenticated_user_after_running(): void
    {
        $previousUser = $this->makeUser();
        $this->actingAs($previousUser, 'web');

        $importingUser = $this->makeUser();

        app(KbWikiImportService::class)->importDocument(
            $this->tenantId, $this->projectKey,
            $this->markdownFor('brand-new-page', 'runbook', 'accepted', "# Brand new\n\nBody.\n"),
            $importingUser, KbWikiImportCandidate::SOURCE_CLI,
        );

        $this->assertSame($previousUser->id, Auth::guard('web')->id());
        $this->assertSame($this->tenantId, app(TenantContext::class)->current());
    }

    /**
     * R33 — the diff lookup itself must not leak whether a slug already
     * exists to an importer who cannot see it: a NARROWER-scoped user
     * importing the SAME slug as an existing but ACL-invisible document
     * must be treated as proposing a NEW document (not "unchanged" /
     * silently colliding), because AccessScopeScope hides the existing row
     * from them entirely.
     */
    public function test_diff_lookup_is_acl_scoped_to_the_importing_user(): void
    {
        $this->documentWithArtifact('hr/salaries/exec-comp.md', "Confidential body.\n", [
            'slug' => 'exec-comp',
            'doc_id' => 'exec-comp-id',
            'canonical_type' => 'runbook',
            'canonical_status' => 'accepted',
        ]);

        $scopedUser = $this->makeUser();
        ProjectMembership::query()
            ->where('tenant_id', $this->tenantId)
            ->where('user_id', $scopedUser->id)
            ->update(['scope_allowlist' => ['folder_globs' => ['hr/policies/**']]]);

        $result = app(KbWikiImportService::class)->importDocument(
            $this->tenantId, $this->projectKey,
            $this->markdownFor('exec-comp', 'runbook', 'accepted', "Attacker-supplied body.\n"),
            $scopedUser, KbWikiImportCandidate::SOURCE_CLI,
        );

        $this->assertNotSame('unchanged', $result['status'], 'A scoped-out user must never be told the existing document is unchanged.');
    }

    /**
     * Independent-review finding (PR #509) — a bare `str_starts_with($wikiDir,
     * $root)` accepts a SIBLING directory whose name merely starts with
     * $root's own name (e.g. "$root-evil"), which is exactly what a
     * symlinked `wiki/` resolving outside the intended root looks like.
     * Reproduces the exact shape: `wiki/` is a symlink pointing at a
     * directory named "{root}-evil" — a real containment check must refuse
     * it; the pre-fix check would have accepted it as if it were nested.
     */
    public function test_a_wiki_symlink_escaping_to_a_sibling_directory_is_refused(): void
    {
        $root = sys_get_temp_dir().'/kb-wiki-import-escape-'.uniqid();
        $evilSibling = $root.'-evil';
        mkdir($root, 0755, true);
        mkdir($evilSibling, 0755, true);
        file_put_contents($evilSibling.'/secret.md', "# Secret\n\nShould never be read.\n");
        $this->assertTrue(symlink($evilSibling, $root.'/wiki'), 'Test setup could not create the escaping symlink.');

        try {
            $user = $this->makeUser();

            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessageMatches('/No wiki\/ folder found under/');

            app(KbWikiImportService::class)->importFolder($this->tenantId, $root, $user);
        } finally {
            @unlink($root.'/wiki');
            @unlink($evilSibling.'/secret.md');
            @rmdir($evilSibling);
            @rmdir($root);
        }
    }
}
