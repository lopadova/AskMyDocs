<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\AutoWiki;

use App\Models\KbEdge;
use App\Models\KbNode;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * v8.11/P6 — agentic graph-navigation across the R44 surfaces: the
 * `kb:wiki-navigate` Artisan command (PHP) and the admin HTTP endpoint, over the
 * shared {@see \App\Services\Kb\AutoWiki\WikiNavigator}. (The MCP tool — the
 * primary agentic surface — is registration-tested in
 * KnowledgeBaseServerRegistrationTest.)
 */
final class WikiNavigateTriSurfaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        app(TenantContext::class)->set('default');
        $this->graph();
    }

    private function graph(): void
    {
        foreach (['a', 'b', 'c'] as $uid) {
            KbNode::create([
                'tenant_id' => 'default', 'project_key' => 'docs-v3', 'node_uid' => $uid,
                'node_type' => 'domain-concept', 'label' => $uid, 'payload_json' => ['dangling' => false],
            ]);
        }
        foreach ([['a', 'b'], ['b', 'c']] as [$from, $to]) {
            KbEdge::create([
                'tenant_id' => 'default', 'project_key' => 'docs-v3',
                'edge_uid' => "{$from}->{$to}:related_to", 'from_node_uid' => $from, 'to_node_uid' => $to,
                'edge_type' => 'related_to', 'weight' => 0.5, 'provenance' => 'inferred',
            ]);
        }
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

    public function test_command_navigates_from_seeds(): void
    {
        $this->artisan('kb:wiki-navigate', ['project' => 'docs-v3', '--seeds' => 'a', '--depth' => '2'])
            ->expectsOutputToContain('reached 2 node(s)')
            ->assertSuccessful();
    }

    public function test_api_navigate_from_seeds(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/admin/kb/wiki-navigate', ['project_key' => 'docs-v3', 'seeds' => ['a'], 'depth' => 2])
            ->assertOk()
            ->assertJsonPath('data.reached.0.slug', 'b')
            ->assertJsonPath('data.reached.1.slug', 'c');
    }

    public function test_api_validates_project_key(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/admin/kb/wiki-navigate', ['seeds' => ['a']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('project_key');
    }

    public function test_api_viewer_is_forbidden(): void
    {
        $this->actingAs($this->viewer())
            ->postJson('/api/admin/kb/wiki-navigate', ['project_key' => 'docs-v3', 'seeds' => ['a']])
            ->assertForbidden();
    }
}
