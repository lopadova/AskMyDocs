<?php

namespace Tests\Feature\Api\Admin\Kb;

use App\Models\KnowledgeDocument;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * PR8 / Phase G1 — admin KB tree explorer.
 *
 * Mirrors the Phase F1/F2 controller tests: routes mounted under the
 * `api` middleware group, RbacSeeder in setUp, Cache::flush() so
 * Spatie's permission cache doesn't survive DB rollback under
 * Testbench (see PR6 LESSONS).
 *
 * Every scenario hits the real Eloquent query path — no mocks on
 * KnowledgeDocument — so the chunkById walker is exercised for
 * real, including on the 150-row memory-safety case.
 */
class KbTreeControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->group(__DIR__.'/../../../../../routes/api.php');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Cache::flush();
    }

    // ------------------------------------------------------------------
    // Empty baseline
    // ------------------------------------------------------------------

    public function test_index_returns_empty_tree_and_zero_counts_when_no_docs(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->getJson('/api/admin/kb/tree')
            ->assertOk()
            ->assertJsonPath('tree', [])
            ->assertJsonPath('counts.docs', 0)
            ->assertJsonPath('counts.canonical', 0)
            ->assertJsonPath('counts.trashed', 0)
            ->assertJsonStructure(['tree', 'counts', 'generated_at']);
    }

    // ------------------------------------------------------------------
    // Mode filter — canonical / raw / all
    // ------------------------------------------------------------------

    public function test_mode_canonical_returns_only_canonical_docs(): void
    {
        $admin = $this->makeAdmin();

        $this->makeDoc('hr-portal', 'policies/remote-work.md', canonical: true, slug: 'remote-work');
        $this->makeDoc('hr-portal', 'policies/draft.md', canonical: false, slug: null);

        $response = $this->actingAs($admin)
            ->getJson('/api/admin/kb/tree?mode=canonical')
            ->assertOk();

        $paths = $this->collectDocPaths($response->json('tree'));
        $this->assertContains('policies/remote-work.md', $paths);
        $this->assertNotContains('policies/draft.md', $paths);

        $this->assertSame(1, $response->json('counts.docs'));
        $this->assertSame(1, $response->json('counts.canonical'));
    }

    public function test_mode_raw_returns_only_non_canonical_docs(): void
    {
        $admin = $this->makeAdmin();

        $this->makeDoc('hr-portal', 'policies/remote-work.md', canonical: true, slug: 'remote-work');
        $this->makeDoc('hr-portal', 'inbox/ingest-me.md', canonical: false, slug: null);

        $response = $this->actingAs($admin)
            ->getJson('/api/admin/kb/tree?mode=raw')
            ->assertOk();

        $paths = $this->collectDocPaths($response->json('tree'));
        $this->assertContains('inbox/ingest-me.md', $paths);
        $this->assertNotContains('policies/remote-work.md', $paths);

        $this->assertSame(1, $response->json('counts.docs'));
        $this->assertSame(0, $response->json('counts.canonical'));
    }

    public function test_mode_all_returns_both_states(): void
    {
        $admin = $this->makeAdmin();

        $this->makeDoc('hr-portal', 'policies/remote-work.md', canonical: true, slug: 'remote-work');
        $this->makeDoc('hr-portal', 'inbox/ingest-me.md', canonical: false, slug: null);

        $response = $this->actingAs($admin)
            ->getJson('/api/admin/kb/tree?mode=all')
            ->assertOk();

        $paths = $this->collectDocPaths($response->json('tree'));
        $this->assertContains('policies/remote-work.md', $paths);
        $this->assertContains('inbox/ingest-me.md', $paths);

        $this->assertSame(2, $response->json('counts.docs'));
        $this->assertSame(1, $response->json('counts.canonical'));
    }

    // ------------------------------------------------------------------
    // Soft-delete
    // ------------------------------------------------------------------

    public function test_with_trashed_default_hides_soft_deleted(): void
    {
        $admin = $this->makeAdmin();
        $live = $this->makeDoc('hr-portal', 'policies/live.md', canonical: true, slug: 'live');
        $gone = $this->makeDoc('hr-portal', 'policies/gone.md', canonical: true, slug: 'gone');
        $gone->delete();

        $response = $this->actingAs($admin)
            ->getJson('/api/admin/kb/tree')
            ->assertOk();

        $paths = $this->collectDocPaths($response->json('tree'));
        $this->assertContains('policies/live.md', $paths);
        $this->assertNotContains('policies/gone.md', $paths);
        $this->assertSame(0, $response->json('counts.trashed'));
        $this->assertSame(1, $response->json('counts.docs'));

        // Silence PHPStan — $live is the surviving row.
        $this->assertNull($live->deleted_at);
    }

    public function test_with_trashed_true_includes_soft_deleted_with_deleted_at_in_meta(): void
    {
        $admin = $this->makeAdmin();
        $gone = $this->makeDoc('hr-portal', 'policies/gone.md', canonical: true, slug: 'gone');
        $gone->delete();

        $response = $this->actingAs($admin)
            ->getJson('/api/admin/kb/tree?with_trashed=1')
            ->assertOk();

        $paths = $this->collectDocPaths($response->json('tree'));
        $this->assertContains('policies/gone.md', $paths);
        $this->assertSame(1, $response->json('counts.trashed'));

        $docNode = $this->findDocNode($response->json('tree'), 'policies/gone.md');
        $this->assertNotNull($docNode);
        $this->assertNotNull($docNode['meta']['deleted_at']);
    }

    // ------------------------------------------------------------------
    // Project scoping
    // ------------------------------------------------------------------

    public function test_project_filter_scopes_to_one_project(): void
    {
        $admin = $this->makeAdmin();

        $this->makeDoc('hr-portal', 'policies/hr-doc.md', canonical: true, slug: 'hr-doc');
        $this->makeDoc('engineering', 'runbooks/eng-doc.md', canonical: true, slug: 'eng-doc');

        $response = $this->actingAs($admin)
            ->getJson('/api/admin/kb/tree?project=hr-portal')
            ->assertOk();

        $paths = $this->collectDocPaths($response->json('tree'));
        $this->assertContains('policies/hr-doc.md', $paths);
        $this->assertNotContains('runbooks/eng-doc.md', $paths);
        $this->assertSame(1, $response->json('counts.docs'));
    }

    // ------------------------------------------------------------------
    // Memory-safe bulk seed — exercises chunkById path
    // ------------------------------------------------------------------

    public function test_large_corpus_walks_without_crashing(): void
    {
        $admin = $this->makeAdmin();

        for ($i = 0; $i < 150; $i++) {
            $folder = 'section-'.str_pad((string) (intdiv($i, 25)), 2, '0', STR_PAD_LEFT);
            $this->makeDoc(
                'bulk',
                "{$folder}/doc-".str_pad((string) $i, 3, '0', STR_PAD_LEFT).'.md',
                canonical: $i % 2 === 0,
                slug: "slug-{$i}",
            );
        }

        $response = $this->actingAs($admin)
            ->getJson('/api/admin/kb/tree?project=bulk')
            ->assertOk();

        $this->assertSame(150, $response->json('counts.docs'));
        $this->assertSame(75, $response->json('counts.canonical'));

        $paths = $this->collectDocPaths($response->json('tree'));
        $this->assertCount(150, $paths);
    }

    // ------------------------------------------------------------------
    // Validation + RBAC
    // ------------------------------------------------------------------

    public function test_invalid_mode_returns_422(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->getJson('/api/admin/kb/tree?mode=bogus')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['mode']);
    }

    public function test_non_admin_gets_403(): void
    {
        $viewer = $this->makeViewer('rbac');

        $this->actingAs($viewer)
            ->getJson('/api/admin/kb/tree')
            ->assertStatus(403);
    }

    public function test_guest_gets_401(): void
    {
        $this->getJson('/api/admin/kb/tree')->assertStatus(401);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function makeAdmin(): User
    {
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $admin->assignRole('admin');

        return $admin;
    }

    private function makeViewer(string $slug, ?string $email = null): User
    {
        $user = User::create([
            'name' => $slug,
            'email' => $email ?? $slug.'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $user->assignRole('viewer');

        return $user;
    }

    private function makeDoc(
        string $projectKey,
        string $sourcePath,
        bool $canonical,
        ?string $slug,
    ): KnowledgeDocument {
        return KnowledgeDocument::create([
            'project_key' => $projectKey,
            'source_type' => 'md',
            'title' => basename($sourcePath, '.md'),
            'source_path' => $sourcePath,
            'mime_type' => 'text/markdown',
            'language' => 'en',
            'access_scope' => 'project',
            'status' => 'indexed',
            'document_hash' => hash('sha256', $projectKey.'/'.$sourcePath),
            'version_hash' => hash('sha256', $projectKey.'/'.$sourcePath.'/v1'),
            'metadata' => [],
            'slug' => $slug,
            'canonical_type' => $canonical ? 'policy' : null,
            'canonical_status' => $canonical ? 'accepted' : null,
            'is_canonical' => $canonical,
            'retrieval_priority' => $canonical ? 80 : 50,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $nodes
     * @return list<string>
     */
    private function collectDocPaths(array $nodes): array
    {
        $paths = [];
        foreach ($nodes as $node) {
            if (($node['type'] ?? null) === 'doc') {
                $paths[] = $node['path'];
                continue;
            }
            if (($node['type'] ?? null) === 'folder') {
                $paths = array_merge($paths, $this->collectDocPaths($node['children'] ?? []));
            }
        }

        return $paths;
    }

    /**
     * @param  array<int, array<string, mixed>>  $nodes
     * @return array<string, mixed>|null
     */
    private function findDocNode(array $nodes, string $path): ?array
    {
        foreach ($nodes as $node) {
            if (($node['type'] ?? null) === 'doc' && ($node['path'] ?? null) === $path) {
                return $node;
            }
            if (($node['type'] ?? null) === 'folder') {
                $hit = $this->findDocNode($node['children'] ?? [], $path);
                if ($hit !== null) {
                    return $hit;
                }
            }
        }

        return null;
    }
}
