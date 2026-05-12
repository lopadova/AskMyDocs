<?php

declare(strict_types=1);

namespace Tests\Feature\Workflow;

use App\Models\HiddenWorkflow;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowShare;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * v4.7/W2 — Workflow controller integration.
 *
 * Covers CRUD + share + hide flows + RBAC + tenant 404. Suggester
 * happy-path is exercised in {@see WorkflowSuggesterTest} at the
 * service layer (stubs out Http::fake there to avoid coupling the
 * route layer to the LLM contract).
 */
final class WorkflowControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->group(__DIR__.'/../../../routes/api.php');
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Copilot iter 9: flush the cache before seeding so Spatie's
        // permission cache from a previous RefreshDatabase rollback
        // does not survive into this suite under Testbench.
        Cache::flush();
        $this->seed(RbacSeeder::class);
    }

    public function test_index_lists_workflows(): void
    {
        $admin = $this->makeUser('admin');
        Workflow::create([
            'tenant_id' => 'default',
            'user_id' => $admin->id,
            'title' => 'A',
            'type' => 'assistant',
            'prompt_md' => 'do',
            'practice' => 'generic',
        ]);

        $resp = $this->actingAs($admin)->getJson('/api/admin/workflows');
        $resp->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_store_creates_assistant_workflow(): void
    {
        $admin = $this->makeUser('admin');

        $resp = $this->actingAs($admin)->postJson('/api/admin/workflows', [
            'title' => 'Meeting Notes',
            'type' => 'assistant',
            'prompt_md' => 'Summarise meetings.',
            'practice' => 'generic',
        ]);

        $resp->assertStatus(201)
            ->assertJsonPath('data.title', 'Meeting Notes')
            ->assertJsonPath('data.type', 'assistant');
        $this->assertDatabaseHas('workflows', ['title' => 'Meeting Notes']);
    }

    public function test_store_creates_tabular_workflow(): void
    {
        $admin = $this->makeUser('admin');

        $resp = $this->actingAs($admin)->postJson('/api/admin/workflows', [
            'title' => 'OKRs',
            'type' => 'tabular',
            'prompt_md' => 'Extract OKRs.',
            'practice' => 'generic',
            'columns_config' => [
                ['name' => 'Objective', 'prompt' => 'Parent objective.', 'format' => 'text'],
                ['name' => 'Status', 'format' => 'enum_status', 'enum_values' => ['green', 'yellow', 'red']],
            ],
        ]);

        $resp->assertStatus(201)
            ->assertJsonPath('data.title', 'OKRs');
    }

    public function test_store_rejects_tabular_without_columns(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)->postJson('/api/admin/workflows', [
            'title' => 'Bad',
            'type' => 'tabular',
            'prompt_md' => 'X',
        ])->assertStatus(422)->assertJsonValidationErrors(['columns_config']);
    }

    public function test_store_rejects_invalid_type(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)->postJson('/api/admin/workflows', [
            'title' => 'X',
            'type' => 'banana',
            'prompt_md' => 'Y',
        ])->assertStatus(422)->assertJsonValidationErrors(['type']);
    }

    public function test_show_returns_workflow_with_shares(): void
    {
        $admin = $this->makeUser('admin');
        $wf = Workflow::create([
            'tenant_id' => 'default',
            'user_id' => $admin->id,
            'title' => 'WF',
            'type' => 'assistant',
            'prompt_md' => 'p',
            'practice' => 'generic',
        ]);
        WorkflowShare::create([
            'workflow_id' => $wf->id,
            'shared_by_user_id' => $admin->id,
            'shared_with_email' => 'r@example.com',
            'allow_edit' => true,
        ]);

        $resp = $this->actingAs($admin)->getJson("/api/admin/workflows/{$wf->id}");
        $resp->assertOk()
            ->assertJsonPath('data.id', $wf->id)
            ->assertJsonPath('data.shares.0.shared_with_email', 'r@example.com');
    }

    public function test_show_returns_404_for_missing(): void
    {
        $admin = $this->makeUser('admin');
        $this->actingAs($admin)->getJson('/api/admin/workflows/999999')->assertStatus(404);
    }

    public function test_show_returns_404_for_cross_tenant_workflow(): void
    {
        $admin = $this->makeUser('admin');
        $wf = Workflow::create([
            'tenant_id' => 'other-tenant',
            'user_id' => $admin->id,
            'title' => 'X',
            'type' => 'assistant',
            'prompt_md' => 'p',
            'practice' => 'generic',
        ]);

        $this->actingAs($admin)->getJson("/api/admin/workflows/{$wf->id}")->assertStatus(404);
    }

    public function test_update_modifies_title(): void
    {
        $admin = $this->makeUser('admin');
        $wf = Workflow::create([
            'tenant_id' => 'default',
            'user_id' => $admin->id,
            'title' => 'Old',
            'type' => 'assistant',
            'prompt_md' => 'p',
            'practice' => 'generic',
        ]);

        $this->actingAs($admin)->patchJson("/api/admin/workflows/{$wf->id}", ['title' => 'New'])
            ->assertOk()
            ->assertJsonPath('data.title', 'New');
    }

    public function test_destroy_removes_workflow(): void
    {
        $admin = $this->makeUser('admin');
        $wf = Workflow::create([
            'tenant_id' => 'default',
            'user_id' => $admin->id,
            'title' => 'X',
            'type' => 'assistant',
            'prompt_md' => 'p',
            'practice' => 'generic',
        ]);

        $this->actingAs($admin)->deleteJson("/api/admin/workflows/{$wf->id}")->assertStatus(204);
        $this->assertDatabaseMissing('workflows', ['id' => $wf->id]);
    }

    public function test_destroy_system_workflow_is_forbidden(): void
    {
        $admin = $this->makeUser('admin');
        $wf = Workflow::create([
            'tenant_id' => 'default',
            'user_id' => null,
            'title' => 'Sys',
            'type' => 'assistant',
            'prompt_md' => 'p',
            'practice' => 'generic',
            'is_system' => true,
        ]);

        $this->actingAs($admin)->deleteJson("/api/admin/workflows/{$wf->id}")->assertStatus(403);
        $this->assertDatabaseHas('workflows', ['id' => $wf->id]);
    }

    public function test_share_creates_share_row(): void
    {
        $admin = $this->makeUser('admin');
        $wf = Workflow::create([
            'tenant_id' => 'default',
            'user_id' => $admin->id,
            'title' => 'X',
            'type' => 'assistant',
            'prompt_md' => 'p',
            'practice' => 'generic',
        ]);

        $resp = $this->actingAs($admin)->postJson("/api/admin/workflows/{$wf->id}/share", [
            'email' => 'recipient@example.com',
            'allow_edit' => true,
        ]);
        $resp->assertStatus(201);
        $this->assertDatabaseHas('workflow_shares', [
            'workflow_id' => $wf->id,
            'shared_with_email' => 'recipient@example.com',
            'allow_edit' => 1,
        ]);
    }

    public function test_unshare_removes_share_row(): void
    {
        $admin = $this->makeUser('admin');
        $wf = Workflow::create([
            'tenant_id' => 'default',
            'user_id' => $admin->id,
            'title' => 'X',
            'type' => 'assistant',
            'prompt_md' => 'p',
            'practice' => 'generic',
        ]);
        WorkflowShare::create([
            'workflow_id' => $wf->id,
            'shared_by_user_id' => $admin->id,
            'shared_with_email' => 'r@example.com',
            'allow_edit' => false,
        ]);

        $this->actingAs($admin)->deleteJson("/api/admin/workflows/{$wf->id}/share", [
            'email' => 'r@example.com',
        ])->assertOk()->assertJsonPath('data.unshared', true);

        $this->assertDatabaseMissing('workflow_shares', [
            'workflow_id' => $wf->id,
            'shared_with_email' => 'r@example.com',
        ]);
    }

    public function test_hide_and_unhide(): void
    {
        $admin = $this->makeUser('admin');
        $admin->email = 'admin-h@example.com';
        $admin->save();

        $other = $this->makeUser('admin');
        $wf = Workflow::create([
            'tenant_id' => 'default',
            'user_id' => $other->id,
            'title' => 'Shared',
            'type' => 'assistant',
            'prompt_md' => 'p',
            'practice' => 'generic',
        ]);
        WorkflowShare::create([
            'workflow_id' => $wf->id,
            'shared_by_user_id' => $other->id,
            'shared_with_email' => 'admin-h@example.com',
            'allow_edit' => false,
        ]);

        $this->actingAs($admin)->postJson("/api/admin/workflows/{$wf->id}/hide")->assertStatus(201);
        $this->assertDatabaseHas('hidden_workflows', [
            'tenant_id' => 'default',
            'user_id' => $admin->id,
            'workflow_id' => $wf->id,
        ]);

        $this->actingAs($admin)->deleteJson("/api/admin/workflows/{$wf->id}/hide")->assertOk();
        $this->assertDatabaseMissing('hidden_workflows', [
            'user_id' => $admin->id,
            'workflow_id' => $wf->id,
        ]);
    }

    public function test_viewer_can_read_but_not_mutate(): void
    {
        $viewer = $this->makeUser('viewer');

        $this->actingAs($viewer)->getJson('/api/admin/workflows')->assertOk();

        $this->actingAs($viewer)->postJson('/api/admin/workflows', [
            'title' => 'X',
            'type' => 'assistant',
            'prompt_md' => 'p',
        ])->assertStatus(403);
    }

    public function test_viewer_cannot_call_suggest(): void
    {
        $viewer = $this->makeUser('viewer');

        $this->actingAs($viewer)->postJson('/api/admin/workflows/suggest', [
            'limit' => 5,
        ])->assertStatus(403);
    }

    public function test_guest_gets_401(): void
    {
        $this->getJson('/api/admin/workflows')->assertStatus(401);
    }

    public function test_user_without_role_gets_403(): void
    {
        $user = User::create([
            'name' => 'U',
            'email' => 'u-'.uniqid().'@demo.local',
            'password' => Hash::make('secret'),
        ]);
        $this->actingAs($user)->getJson('/api/admin/workflows')->assertStatus(403);
    }

    private function makeUser(string $role): User
    {
        $u = User::create([
            'name' => 'U',
            'email' => 'u-'.uniqid().'@demo.local',
            'password' => Hash::make('secret'),
        ]);
        $u->assignRole($role);
        return $u;
    }
}
