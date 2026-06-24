<?php

declare(strict_types=1);

namespace Tests\Feature\Rbac;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\CaseStudyUsersSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Per-USER documentation isolation for the three case-study companies.
 *
 * Companion to {@see \Tests\Live\Rag\LiveRagIsolationTest} (which proves the
 * per-SELECTION axis — the chat `filters.project_keys` scope — against the real
 * pgvector retrieval). This suite proves the per-USER axis the spec objective
 * asks for ("the user of company X must only ever see X's documents"): with
 * `kb.project_isolation.enabled` ON, an account that is a member of ONLY its
 * own project reads exclusively that project's documents, chunks and canaries.
 *
 * It runs in CI on SQLite because it exercises the `AccessScopeScope` global
 * scope (pure SQL via `KnowledgeChunk::whereHas('document')`) — the same gate
 * every content read path (chat retrieval, search, the admin KB surface) runs
 * through — without needing pgvector or an embeddings provider.
 *
 * R43 — the flag is exercised in BOTH states: OFF (every content role sees all
 * projects, memberships dormant) AND ON (the per-company user is confined to
 * its membership set). The accounts + memberships come from the real
 * {@see CaseStudyUsersSeeder} so this suite also covers that seeder.
 *
 * ONE TENANT PER COMPANY (R30/R31): the seeder now pins each company to its own
 * tenant (tenant_id = project_key), so documents are seeded in the company tenant
 * and the ON assertions run under that company's tenant context —
 * {@see User::allowedProjects()} resolves memberships `forTenant(current)`, so a
 * mismatched context would (correctly) yield zero access.
 */
final class CaseStudyProjectIsolationTest extends TestCase
{
    use RefreshDatabase;

    /** project_key => one of that company's unique canaries (no cross-project leak — gated by CaseStudyDatasetTest). */
    private const CANARY = [
        'rotta-logistics' => 'HUB-MI-07',
        'prometeo-antincendio' => 'Protocollo Fenice-7',
        'passolibero-calzature' => 'ClubPasso',
    ];

    /** project_key => the seeder account that is a member of ONLY that project. */
    private const ACCOUNT = [
        'rotta-logistics' => 'rotta@case-study.local',
        'prometeo-antincendio' => 'prometeo@case-study.local',
        'passolibero-calzature' => 'passolibero@case-study.local',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // Roles/permissions first, then the per-company users + projects +
        // single-project memberships, then the documents they read.
        $this->seed(RbacSeeder::class);
        $this->seed(CaseStudyUsersSeeder::class);
        Cache::flush();

        // Known baseline: isolation OFF, pinned so test_off_* never depends on
        // execution order (the flag is process-global config state).
        config()->set('kb.project_isolation.enabled', false);

        foreach (self::CANARY as $project => $canary) {
            $this->seedDoc($project, $canary);
        }
    }

    private function enableIsolation(): void
    {
        config()->set('kb.project_isolation.enabled', true);
    }

    // ------------------------------------------------------------------
    // Seeder shape — the per-user axis is set up correctly
    // ------------------------------------------------------------------

    public function test_seeder_gives_each_company_user_membership_to_only_its_own_project(): void
    {
        foreach (self::ACCOUNT as $project => $email) {
            $user = $this->userFor($project);

            $memberProjects = ProjectMembership::query()
                ->where('user_id', $user->id)
                ->pluck('project_key')
                ->all();

            $this->assertSame(
                [$project],
                $memberProjects,
                "{$email} must be a member of ONLY {$project} — the per-user isolation axis.",
            );
            $this->assertTrue($user->hasRole('viewer'));
        }
    }

    // ------------------------------------------------------------------
    // OFF (default) — unchanged behaviour, every content role sees all
    // ------------------------------------------------------------------

    public function test_off_company_user_sees_all_three_projects(): void
    {
        $rotta = $this->userFor('rotta-logistics');
        $this->actingAs($rotta);

        $this->assertTrue($rotta->canReadAllProjects());

        $projects = KnowledgeDocument::query()->pluck('project_key')->unique()->sort()->values()->all();
        $this->assertSame(
            ['passolibero-calzature', 'prometeo-antincendio', 'rotta-logistics'],
            $projects,
        );
    }

    // ------------------------------------------------------------------
    // ON — each company user is confined to its own project + canary
    // ------------------------------------------------------------------

    public function test_on_rotta_user_reads_only_rotta_documents_and_canary(): void
    {
        $this->assertCompanyUserIsolated('rotta-logistics');
    }

    public function test_on_prometeo_user_reads_only_prometeo_documents_and_canary(): void
    {
        $this->assertCompanyUserIsolated('prometeo-antincendio');
    }

    public function test_on_passolibero_user_reads_only_passolibero_documents_and_canary(): void
    {
        $this->assertCompanyUserIsolated('passolibero-calzature');
    }

    public function test_on_naming_a_foreign_project_yields_nothing(): void
    {
        // Even when a company user explicitly targets a non-member project (a
        // chat `filters.project_keys` escalation attempt), the document scope
        // strips those chunks — the filter can only narrow, never widen.
        $this->enableIsolation();
        // Contesto = tenant dell'azienda del rotta-user: allowedProjects() risolve
        // le membership forTenant(corrente).
        app(TenantContext::class)->set('rotta-logistics');
        $rotta = $this->userFor('rotta-logistics');
        $this->actingAs($rotta);

        $foreign = KnowledgeChunk::query()
            ->whereHas('document')
            ->where('knowledge_chunks.project_key', 'passolibero-calzature')
            ->get();

        $this->assertCount(0, $foreign);
    }

    /**
     * With isolation ON, the company user reads exactly its own project's
     * documents and chunk text — its own canary is visible, the other two
     * companies' canaries are not.
     */
    private function assertCompanyUserIsolated(string $project): void
    {
        $this->enableIsolation();
        // Un tenant per azienda: opera nel tenant dell'azienda così
        // allowedProjects() (membership forTenant(corrente)) risolve l'accesso.
        app(TenantContext::class)->set($project);
        $user = $this->userFor($project);
        $this->actingAs($user);

        $this->assertFalse($user->canReadAllProjects());

        $projects = KnowledgeDocument::query()->pluck('project_key')->unique()->values()->all();
        $this->assertSame([$project], $projects, "{$project} user must read only {$project} documents.");

        // The chunk read path (KbSearchService::semanticCandidates uses
        // whereHas('document'), so the document scope applies inside the
        // subquery) is confined too: own canary present, foreign canaries gone.
        $readable = KnowledgeChunk::query()
            ->whereHas('document')
            ->pluck('chunk_text')
            ->implode("\n");

        $this->assertStringContainsString(self::CANARY[$project], $readable);

        foreach (self::CANARY as $otherProject => $canary) {
            if ($otherProject === $project) {
                continue;
            }
            $this->assertStringNotContainsString(
                $canary,
                $readable,
                "{$project} user must NOT see {$otherProject}'s canary '{$canary}'.",
            );
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function seedDoc(string $projectKey, string $canary): void
    {
        // Un tenant per azienda: il documento vive nel tenant dell'azienda
        // (tenant_id = project_key), come lo ingerirebbe il connettore reale.
        $doc = KnowledgeDocument::create([
            'tenant_id' => $projectKey,
            'project_key' => $projectKey,
            'source_type' => 'markdown',
            'title' => "Doc {$projectKey}",
            'source_path' => "case-studies/{$projectKey}/canary.md",
            'language' => 'it',
            'access_scope' => 'internal',
            'status' => 'active',
            'document_hash' => hash('sha256', $projectKey),
            'version_hash' => hash('sha256', $projectKey),
        ]);

        KnowledgeChunk::create([
            'tenant_id' => $projectKey,
            'knowledge_document_id' => $doc->id,
            'project_key' => $projectKey,
            'chunk_order' => 0,
            'chunk_hash' => hash('sha256', 'chunk-' . $projectKey),
            'heading_path' => null,
            'chunk_text' => "Documento di {$projectKey}. Esca riservata: {$canary}.",
            'metadata' => [],
        ]);
    }

    private function userFor(string $project): User
    {
        return User::where('email', self::ACCOUNT[$project])->sole();
    }
}
