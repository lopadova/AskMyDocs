<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\KnowledgeDocument;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Reader-side KB tree.
 *
 * The most important assertions here are the two `project_isolation`
 * states (R43). This endpoint adds a VISIBLE surface over a retrieval
 * posture that already exists, so what it shows has to be pinned in
 * both configurations — someone will otherwise read the default as a
 * leak, or a future flag flip will silently change the page.
 */
final class KbTreeReaderTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 'acme';

    protected function defineRoutes($router): void
    {
        // routes/api.php is not auto-loaded under Testbench.
        $router->middleware('api')->prefix('api')->group(__DIR__.'/../../../routes/api.php');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->withHeader('X-Tenant-Id', self::TENANT);
        app(TenantContext::class)->set(self::TENANT);
    }

    private function viewer(): User
    {
        $user = User::create([
            'name' => 'KB Reader',
            'email' => 'reader-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $user->assignRole('viewer');

        return $user;
    }

    private function document(string $project, string $path, string $tenantId = self::TENANT, bool $trashed = false): KnowledgeDocument
    {
        $doc = KnowledgeDocument::query()->create([
            'tenant_id' => $tenantId,
            'project_key' => $project,
            'source_type' => 'markdown',
            'title' => basename($path, '.md'),
            'source_path' => $path,
            'mime_type' => 'text/markdown',
            'language' => 'en',
            'access_scope' => 'internal',
            'status' => 'active',
            'document_hash' => hash('sha256', $path.$tenantId),
            'version_hash' => hash('sha256', $path.$tenantId.'v1'),
        ]);

        if ($trashed) {
            $doc->delete();
        }

        return $doc;
    }

    /**
     * Every doc-leaf path in the returned tree, sorted.
     *
     * Nodes are `{type: 'folder'|'doc', name, path, children|meta}` —
     * see KbTreeService::buildNode.
     *
     * @param  array<int, array<string, mixed>>  $tree
     * @return list<string>
     */
    private function paths(array $tree): array
    {
        $paths = [];
        $walk = function (array $nodes) use (&$walk, &$paths): void {
            foreach ($nodes as $node) {
                if (($node['type'] ?? null) === 'doc') {
                    $paths[] = (string) $node['path'];

                    continue;
                }
                if (is_array($node['children'] ?? null)) {
                    $walk($node['children']);
                }
            }
        };
        $walk($tree);
        sort($paths);

        return array_values($paths);
    }

    public function test_a_viewer_can_browse_the_tree(): void
    {
        $viewer = $this->viewer();
        $this->document('engineering', 'engineering/adr/0001-cache.md');

        $response = $this->actingAs($viewer)->getJson('/api/kb/tree')->assertOk();

        $this->assertSame(['tree', 'counts', 'generated_at'], array_keys($response->json()));
        $this->assertContains(
            'engineering/adr/0001-cache.md',
            $this->paths($response->json('tree')),
        );
    }

    public function test_soft_deleted_documents_stay_hidden_even_when_with_trashed_is_requested(): void
    {
        // R2 + R16: the paired assertion is the point. The admin tree DOES
        // surface the trashed doc, so this proves the reader endpoint
        // withholds it rather than the fixture simply being empty.
        $viewer = $this->viewer();
        $this->document('engineering', 'engineering/live.md');
        $this->document('engineering', 'engineering/deleted.md', trashed: true);

        $readerPaths = $this->paths(
            $this->actingAs($viewer)
                ->getJson('/api/kb/tree?with_trashed=1')
                ->assertOk()
                ->json('tree'),
        );

        $this->assertSame(['engineering/live.md'], $readerPaths);

        $admin = User::create([
            'name' => 'KB Admin',
            'email' => 'admin-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $admin->assignRole('admin');

        $adminPaths = $this->paths(
            $this->actingAs($admin)
                ->getJson('/api/admin/kb/tree?with_trashed=1')
                ->assertOk()
                ->json('tree'),
        );

        $this->assertContains('engineering/deleted.md', $adminPaths);
    }

    public function test_documents_from_another_tenant_are_absent(): void
    {
        $viewer = $this->viewer();
        $this->document('engineering', 'engineering/mine.md');
        $this->document('engineering', 'engineering/theirs.md', tenantId: 'globex');

        $paths = $this->paths(
            $this->actingAs($viewer)->getJson('/api/kb/tree')->assertOk()->json('tree'),
        );

        $this->assertSame(['engineering/mine.md'], $paths);
    }

    public function test_with_project_isolation_off_a_viewer_sees_every_project_in_the_tenant(): void
    {
        // R43, state 1 (the DEFAULT every fresh deploy ships): `viewer`
        // holds kb.read.any, so AccessScopeScope short-circuits on
        // canReadAllProjects() and project membership does not narrow the
        // tree. This is the pre-existing retrieval posture, identical to
        // what chat citations already expose — not a regression, but it
        // must be pinned so a flag flip is never silent.
        config()->set('kb.project_isolation.enabled', false);

        $viewer = $this->viewer();
        ProjectMembership::query()->create([
            'tenant_id' => self::TENANT,
            'user_id' => $viewer->id,
            'project_key' => 'engineering',
            'role' => 'member',
        ]);

        $this->document('engineering', 'engineering/mine.md');
        $this->document('hr-portal', 'hr-portal/salaries.md');

        $paths = $this->paths(
            $this->actingAs($viewer)->getJson('/api/kb/tree')->assertOk()->json('tree'),
        );

        $this->assertSame(['engineering/mine.md', 'hr-portal/salaries.md'], $paths);
    }

    public function test_with_project_isolation_on_a_viewer_sees_only_their_membership_projects(): void
    {
        // R43, state 2: the bypass is gone, so the SQL scope narrows the
        // tree to the viewer's memberships.
        config()->set('kb.project_isolation.enabled', true);

        $viewer = $this->viewer();
        ProjectMembership::query()->create([
            'tenant_id' => self::TENANT,
            'user_id' => $viewer->id,
            'project_key' => 'engineering',
            'role' => 'member',
        ]);

        $this->document('engineering', 'engineering/mine.md');
        $this->document('hr-portal', 'hr-portal/salaries.md');

        $paths = $this->paths(
            $this->actingAs($viewer)->getJson('/api/kb/tree')->assertOk()->json('tree'),
        );

        $this->assertSame(['engineering/mine.md'], $paths);
    }

    public function test_it_scopes_to_a_requested_project(): void
    {
        $viewer = $this->viewer();
        $this->document('engineering', 'engineering/mine.md');
        $this->document('hr-portal', 'hr-portal/handbook.md');

        $paths = $this->paths(
            $this->actingAs($viewer)
                ->getJson('/api/kb/tree?project=engineering')
                ->assertOk()
                ->json('tree'),
        );

        $this->assertSame(['engineering/mine.md'], $paths);
    }

    public function test_an_unknown_mode_is_rejected(): void
    {
        $this->actingAs($this->viewer())
            ->getJson('/api/kb/tree?mode=everything')
            ->assertStatus(422)
            ->assertJsonValidationErrors('mode');
    }

    public function test_a_guest_is_unauthenticated(): void
    {
        $this->getJson('/api/kb/tree')->assertStatus(401);
    }
}
