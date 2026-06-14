<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\AutoWiki;

use App\Models\User;
use App\Services\Kb\AutoWiki\WikiMaintainer;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Mockery;
use Tests\TestCase;

/**
 * v8.11/P9 — scheduled maintenance across the R44 surfaces: the
 * `kb:wiki-maintain` command (PHP) and the admin HTTP endpoint, over the shared
 * {@see WikiMaintainer} (mocked here so the thin adapters are tested in
 * isolation; the service logic is covered by WikiMaintainerTest, the MCP tool by
 * KnowledgeBaseServerRegistrationTest). The command is also the Tier-1
 * `kb_wiki_maintain` scheduler slot.
 */
final class WikiMaintainTriSurfaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        app(TenantContext::class)->set('default');
    }

    private function bindMaintainer(): \Mockery\MockInterface
    {
        $mock = Mockery::mock(WikiMaintainer::class);
        $this->app->instance(WikiMaintainer::class, $mock);

        return $mock;
    }

    private function admin(): User
    {
        $u = User::create(['name' => 'A', 'email' => 'a-'.uniqid().'@t.local', 'password' => Hash::make('x')]);
        $u->assignRole('admin');

        return $u;
    }

    private function viewer(): User
    {
        $u = User::create(['name' => 'V', 'email' => 'v-'.uniqid().'@t.local', 'password' => Hash::make('x')]);
        $u->assignRole('viewer');

        return $u;
    }

    public function test_command_maintains(): void
    {
        $mock = $this->bindMaintainer();
        $mock->shouldReceive('maintain')->once()
            ->andReturn(['projects' => ['docs-v3'], 'lint_issues' => 0, 'backfilled' => 3, 'fixed' => 0]);

        $this->artisan('kb:wiki-maintain')
            ->expectsOutputToContain('3 doc(s) backfilled')
            ->assertSuccessful();
    }

    public function test_api_maintain(): void
    {
        $mock = $this->bindMaintainer();
        $mock->shouldReceive('maintain')->once()
            ->andReturn(['projects' => ['docs-v3'], 'lint_issues' => 1, 'backfilled' => 0, 'fixed' => 0]);

        $this->actingAs($this->admin())
            ->postJson('/api/admin/kb/wiki-maintain', ['project_key' => 'docs-v3'])
            ->assertOk()
            ->assertJsonPath('data.lint_issues', 1)
            ->assertJsonPath('data.projects.0', 'docs-v3');
    }

    public function test_api_viewer_is_forbidden(): void
    {
        $this->bindMaintainer();
        $this->actingAs($this->viewer())
            ->postJson('/api/admin/kb/wiki-maintain', [])
            ->assertForbidden();
    }

    public function test_scheduler_slot_is_registered(): void
    {
        // P9 — the maintenance command is wired as the kb_wiki_maintain Tier-1 slot.
        $slots = \App\Scheduling\TierOneSchedulerRegistrar::slots();
        $keys = array_column($slots, 0);
        $this->assertContains('kb_wiki_maintain', $keys);
    }
}
