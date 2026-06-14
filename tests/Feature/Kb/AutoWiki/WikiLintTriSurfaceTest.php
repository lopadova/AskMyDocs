<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\AutoWiki;

use App\Models\KbNode;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * v8.11/P5 — Auto-Wiki lint across the R44 surfaces: the `kb:wiki-lint` Artisan
 * command (PHP) and the admin HTTP endpoints (report + fix), over the shared
 * {@see \App\Services\Kb\AutoWiki\WikiLinter}. (The MCP tool is registration-
 * tested in KnowledgeBaseServerRegistrationTest.)
 */
final class WikiLintTriSurfaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        app(TenantContext::class)->set('default');
    }

    private function danglingNode(string $uid): KbNode
    {
        return KbNode::create([
            'tenant_id' => 'default', 'project_key' => 'docs-v3', 'node_uid' => $uid,
            'node_type' => 'unknown', 'label' => $uid, 'payload_json' => ['dangling' => true],
        ]);
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

    // ── PHP — Artisan command ──────────────────────────────────────────

    public function test_command_reports_and_fixes(): void
    {
        $this->danglingNode('leftover');

        $this->artisan('kb:wiki-lint', ['--project' => 'docs-v3', '--fix' => true])
            ->expectsOutputToContain('dangling 1')
            ->expectsOutputToContain('pruned 1')
            ->assertSuccessful();

        $this->assertDatabaseMissing('kb_nodes', ['node_uid' => 'leftover']);
    }

    // ── HTTP — admin API ───────────────────────────────────────────────

    public function test_api_report(): void
    {
        $this->danglingNode('missing');

        $this->actingAs($this->admin())
            ->getJson('/api/admin/kb/wiki-lint?project_key=docs-v3')
            ->assertOk()
            ->assertJsonPath('data.counts.dangling', 1)
            ->assertJsonPath('data.findings.dangling.0', 'missing');
    }

    public function test_api_report_validates_project_key(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/api/admin/kb/wiki-lint')
            ->assertStatus(422)
            ->assertJsonValidationErrors('project_key');
    }

    public function test_api_fix(): void
    {
        $this->danglingNode('leftover');

        $this->actingAs($this->admin())
            ->postJson('/api/admin/kb/wiki-lint/fix', ['project_key' => 'docs-v3'])
            ->assertOk()
            ->assertJsonPath('data.pruned_dangling', 1);

        $this->assertDatabaseMissing('kb_nodes', ['node_uid' => 'leftover']);
    }

    public function test_api_viewer_is_forbidden(): void
    {
        $this->actingAs($this->viewer())
            ->getJson('/api/admin/kb/wiki-lint?project_key=docs-v3')
            ->assertForbidden();
    }
}
