<?php

declare(strict_types=1);

namespace Tests\Feature\Rbac;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\ProjectMembership;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Per-project isolation within a tenant (config `kb.project_isolation`).
 *
 * R43 — the flag is exercised in BOTH states:
 *  - OFF (default): every content role (`kb.read.any`) reads ALL projects;
 *    project_memberships are dormant. No behaviour change.
 *  - ON: the "see all projects" capability moves to `kb.read.all_projects`
 *    (admin/super-admin only); every other user is constrained to their
 *    project_memberships set (1..N projects).
 *
 * These tests assert the gate that ALL content-surfacing read paths rely on:
 * the `AccessScopeScope` global scope on KnowledgeDocument (chat retrieval,
 * search, autocomplete and the admin KB surface all run their document reads
 * through it — chat/search via `KnowledgeChunk::whereHas('document')`, which
 * applies the scope to the related document). Tenant isolation is a separate,
 * unconditional guarantee covered elsewhere.
 */
final class ProjectIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Cache::flush();

        // Explicit known baseline — the flag is process-global config state;
        // pin it OFF so test_off_* never depends on execution order even if a
        // future runner reuses the application instance across tests.
        config()->set('kb.project_isolation.enabled', false);

        $this->seedDoc('proj-1', 'Project One secret');
        $this->seedDoc('proj-2', 'Project Two doc');
        $this->seedDoc('proj-3', 'Project Three doc');
    }

    private function enableIsolation(): void
    {
        config()->set('kb.project_isolation.enabled', true);
    }

    // ------------------------------------------------------------------
    // OFF (default) — unchanged behaviour
    // ------------------------------------------------------------------

    public function test_off_viewer_sees_all_projects(): void
    {
        $viewer = $this->makeViewerMemberOf('proj-2');

        $this->actingAs($viewer);

        $projects = KnowledgeDocument::query()->pluck('project_key')->unique()->sort()->values()->all();
        $this->assertSame(['proj-1', 'proj-2', 'proj-3'], $projects);
        $this->assertTrue($viewer->canReadAllProjects());
    }

    // ------------------------------------------------------------------
    // ON — restricted to membership set
    // ------------------------------------------------------------------

    public function test_on_viewer_restricted_to_single_member_project(): void
    {
        $this->enableIsolation();
        $viewer = $this->makeViewerMemberOf('proj-2');

        $this->actingAs($viewer);

        $this->assertFalse($viewer->canReadAllProjects());
        $projects = KnowledgeDocument::query()->pluck('project_key')->unique()->values()->all();
        $this->assertSame(['proj-2'], $projects);
    }

    public function test_on_viewer_restricted_to_N_member_projects(): void
    {
        $this->enableIsolation();
        $viewer = $this->makeViewerMemberOf('proj-2', 'proj-3');

        $this->actingAs($viewer);

        $projects = KnowledgeDocument::query()->pluck('project_key')->unique()->sort()->values()->all();
        $this->assertSame(['proj-2', 'proj-3'], $projects);
        $this->assertNotContains('proj-1', $projects);
    }

    public function test_on_viewer_with_no_membership_sees_nothing(): void
    {
        $this->enableIsolation();
        $viewer = $this->makeViewer();

        $this->actingAs($viewer);

        $this->assertSame(0, KnowledgeDocument::query()->count());
    }

    public function test_on_admin_still_sees_all_projects(): void
    {
        $this->enableIsolation();
        $admin = $this->makeUserWithRole('admin'); // holds kb.read.all_projects

        $this->actingAs($admin);

        $this->assertTrue($admin->canReadAllProjects());
        $this->assertSame(3, KnowledgeDocument::query()->distinct()->count('project_key'));
    }

    public function test_on_super_admin_still_sees_all_projects(): void
    {
        $this->enableIsolation();
        $super = $this->makeUserWithRole('super-admin');

        $this->actingAs($super);

        $this->assertTrue($super->canReadAllProjects());
        $this->assertSame(3, KnowledgeDocument::query()->distinct()->count('project_key'));
    }

    // ------------------------------------------------------------------
    // ON — the retrieval mechanism (chunk read via document scope) is isolated
    // ------------------------------------------------------------------

    public function test_on_chunk_retrieval_via_document_scope_is_project_isolated(): void
    {
        $this->enableIsolation();
        $viewer = $this->makeViewerMemberOf('proj-2');

        $this->actingAs($viewer);

        // This mirrors KbSearchService::semanticCandidates(), which fetches
        // chunks via `whereHas('document')` — the document's AccessScopeScope
        // applies inside the subquery, so a restricted viewer's chunk set is
        // confined to their member projects (no cross-project citations).
        $projects = KnowledgeChunk::query()
            ->whereHas('document')
            ->with('document')
            ->get()
            ->map(fn ($c) => $c->document->project_key)
            ->unique()->values()->all();

        $this->assertSame(['proj-2'], $projects);
    }

    public function test_on_naming_a_foreign_project_in_filters_yields_nothing(): void
    {
        $this->enableIsolation();
        $viewer = $this->makeViewerMemberOf('proj-2');

        $this->actingAs($viewer);

        // Even when the caller explicitly names a non-member project (the
        // chat `filters.project_keys` escalation attempt), the document scope
        // removes those chunks — the filter can only narrow, never widen.
        $chunks = KnowledgeChunk::query()
            ->whereHas('document')
            ->where('knowledge_chunks.project_key', 'proj-1')
            ->get();

        $this->assertCount(0, $chunks);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function seedDoc(string $projectKey, string $title): KnowledgeDocument
    {
        $doc = KnowledgeDocument::create([
            'project_key' => $projectKey,
            'source_type' => 'markdown',
            'title' => $title,
            'source_path' => "docs/{$projectKey}.md",
            'language' => 'en',
            'access_scope' => 'internal',
            'status' => 'active',
            'document_hash' => hash('sha256', $projectKey),
            'version_hash' => hash('sha256', $projectKey),
        ]);

        KnowledgeChunk::create([
            'knowledge_document_id' => $doc->id,
            'project_key' => $projectKey,
            'chunk_order' => 0,
            'chunk_hash' => hash('sha256', 'chunk-'.$projectKey),
            'heading_path' => null,
            'chunk_text' => $title.' body',
            'metadata' => [],
        ]);

        return $doc;
    }

    private function makeViewer(): User
    {
        return $this->makeUserWithRole('viewer');
    }

    private function makeViewerMemberOf(string ...$projectKeys): User
    {
        $viewer = $this->makeViewer();
        foreach ($projectKeys as $key) {
            ProjectMembership::create([
                'user_id' => $viewer->id,
                'project_key' => $key,
                'role' => 'member',
            ]);
        }

        return $viewer;
    }

    private function makeUserWithRole(string $role): User
    {
        $user = User::create([
            'name' => $role.'-'.uniqid(),
            'email' => $role.'-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $user->assignRole($role);

        return $user;
    }
}
