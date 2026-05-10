<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Flow\Admin\AskMyDocsFlowAuthorizer;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * v4.2/W4 sub-PR 6 — Gate matrix for the Flow Admin cockpit.
 *
 * Two surfaces are tested:
 *
 *   1. The outer-fence Laravel Gate `viewFlowAdmin` (consumed by the
 *      `can:viewFlowAdmin` middleware in `config/flow-admin.php`).
 *   2. The 8 methods on {@see AskMyDocsFlowAuthorizer} that
 *      back the package's `Padosoft\LaravelFlowAdmin\Contracts\
 *      ActionAuthorizer` contract.
 *
 * Role matrix (see docs/v4-platform/FEATURE-CATALOG-flow-admin.md and
 * the AskMyDocsFlowAuthorizer doc-block):
 *
 *   - canViewKpis / canViewRuns / canViewRunDetail
 *       → super-admin + admin + dpo
 *   - canReplayRun / canRetryWebhook
 *       → super-admin + admin
 *   - canCancelRun
 *       → super-admin only
 *   - canApproveByToken / canRejectByToken
 *       → super-admin + dpo
 *
 * Tenant scoping (R30) is exercised in the dedicated
 * FlowAdminTenantScopingTest sibling.
 */
class FlowAdminGatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        // Land all rows under 'default' tenant so the row-level tenant
        // checks pass — this test focuses on the role matrix only.
        app(TenantContext::class)->set('default');
    }

    public function test_outer_view_gate_allows_super_admin_admin_dpo_only(): void
    {
        $superAdmin = $this->makeUser('super-admin');
        $admin = $this->makeUser('admin');
        $dpo = $this->makeUser('dpo');
        $editor = $this->makeUser('editor');
        $viewer = $this->makeUser('viewer');

        $this->assertTrue(Gate::forUser($superAdmin)->allows('viewFlowAdmin'));
        $this->assertTrue(Gate::forUser($admin)->allows('viewFlowAdmin'));
        $this->assertTrue(Gate::forUser($dpo)->allows('viewFlowAdmin'));

        $this->assertFalse(Gate::forUser($editor)->allows('viewFlowAdmin'));
        $this->assertFalse(Gate::forUser($viewer)->allows('viewFlowAdmin'));
        $this->assertFalse(Gate::allows('viewFlowAdmin')); // anonymous
    }

    public function test_can_view_runs_kpis_allows_super_admin_admin_dpo(): void
    {
        $authorizer = $this->resolveAuthorizer();

        // Anonymous deny.
        $this->assertFalse($authorizer->canViewRuns(null));
        $this->assertFalse($authorizer->canViewKpis(null));

        $this->actingAs($this->makeUser('super-admin'));
        $this->assertTrue($authorizer->canViewRuns(null));
        $this->assertTrue($authorizer->canViewKpis(null));

        $this->actingAs($this->makeUser('admin'));
        $this->assertTrue($authorizer->canViewRuns(null));
        $this->assertTrue($authorizer->canViewKpis(null));

        $this->actingAs($this->makeUser('dpo'));
        $this->assertTrue($authorizer->canViewRuns(null));
        $this->assertTrue($authorizer->canViewKpis(null));

        $this->actingAs($this->makeUser('editor'));
        $this->assertFalse($authorizer->canViewRuns(null));
        $this->assertFalse($authorizer->canViewKpis(null));

        $this->actingAs($this->makeUser('viewer'));
        $this->assertFalse($authorizer->canViewRuns(null));
        $this->assertFalse($authorizer->canViewKpis(null));
    }

    public function test_can_replay_run_allows_super_admin_admin_only(): void
    {
        $runId = $this->seedRun('default');
        $authorizer = $this->resolveAuthorizer();

        $this->assertFalse($authorizer->canReplayRun($runId, null));

        $this->actingAs($this->makeUser('super-admin'));
        $this->assertTrue($authorizer->canReplayRun($runId, null));

        $this->actingAs($this->makeUser('admin'));
        $this->assertTrue($authorizer->canReplayRun($runId, null));

        // dpo can approve but cannot replay — operator vs reviewer
        // boundary mirrors the FEATURE-CATALOG matrix.
        $this->actingAs($this->makeUser('dpo'));
        $this->assertFalse($authorizer->canReplayRun($runId, null));

        $this->actingAs($this->makeUser('editor'));
        $this->assertFalse($authorizer->canReplayRun($runId, null));

        $this->actingAs($this->makeUser('viewer'));
        $this->assertFalse($authorizer->canReplayRun($runId, null));
    }

    public function test_can_cancel_run_allows_super_admin_only(): void
    {
        $runId = $this->seedRun('default');
        $authorizer = $this->resolveAuthorizer();

        $this->assertFalse($authorizer->canCancelRun($runId, null));

        $this->actingAs($this->makeUser('super-admin'));
        $this->assertTrue($authorizer->canCancelRun($runId, null));

        // Even admin cannot cancel — destructive operation pinned to
        // the highest-privilege role.
        $this->actingAs($this->makeUser('admin'));
        $this->assertFalse($authorizer->canCancelRun($runId, null));

        $this->actingAs($this->makeUser('dpo'));
        $this->assertFalse($authorizer->canCancelRun($runId, null));
    }

    public function test_can_approve_reject_by_token_allows_super_admin_dpo_only(): void
    {
        $runId = $this->seedRun('default');
        $tokenHash = $this->seedApproval('default', $runId);
        $authorizer = $this->resolveAuthorizer();

        $this->assertFalse($authorizer->canApproveByToken($tokenHash, null));
        $this->assertFalse($authorizer->canRejectByToken($tokenHash, null));

        $this->actingAs($this->makeUser('super-admin'));
        $this->assertTrue($authorizer->canApproveByToken($tokenHash, null));
        $this->assertTrue($authorizer->canRejectByToken($tokenHash, null));

        $this->actingAs($this->makeUser('dpo'));
        $this->assertTrue($authorizer->canApproveByToken($tokenHash, null));
        $this->assertTrue($authorizer->canRejectByToken($tokenHash, null));

        // admin cannot make approval decisions — that's the dpo
        // privacy/governance boundary the FEATURE-CATALOG defines.
        $this->actingAs($this->makeUser('admin'));
        $this->assertFalse($authorizer->canApproveByToken($tokenHash, null));
        $this->assertFalse($authorizer->canRejectByToken($tokenHash, null));

        $this->actingAs($this->makeUser('editor'));
        $this->assertFalse($authorizer->canApproveByToken($tokenHash, null));
        $this->assertFalse($authorizer->canRejectByToken($tokenHash, null));
    }

    public function test_can_retry_webhook_allows_super_admin_admin_only(): void
    {
        $outboxId = $this->seedWebhook('default');
        $authorizer = $this->resolveAuthorizer();

        $this->assertFalse($authorizer->canRetryWebhook($outboxId, null));

        $this->actingAs($this->makeUser('super-admin'));
        $this->assertTrue($authorizer->canRetryWebhook($outboxId, null));

        $this->actingAs($this->makeUser('admin'));
        $this->assertTrue($authorizer->canRetryWebhook($outboxId, null));

        $this->actingAs($this->makeUser('dpo'));
        $this->assertFalse($authorizer->canRetryWebhook($outboxId, null));

        $this->actingAs($this->makeUser('viewer'));
        $this->assertFalse($authorizer->canRetryWebhook($outboxId, null));
    }

    public function test_missing_run_returns_false_even_for_super_admin(): void
    {
        $authorizer = $this->resolveAuthorizer();
        $this->actingAs($this->makeUser('super-admin'));

        // Non-existent run id — defence-in-depth: the gate refuses
        // rather than green-light a downstream 404.
        $this->assertFalse($authorizer->canViewRunDetail('flow_run_does_not_exist', null));
        $this->assertFalse($authorizer->canReplayRun('flow_run_does_not_exist', null));
        $this->assertFalse($authorizer->canCancelRun('flow_run_does_not_exist', null));
    }

    private function resolveAuthorizer(): AskMyDocsFlowAuthorizer
    {
        return app(AskMyDocsFlowAuthorizer::class);
    }

    private function seedRun(string $tenantId): string
    {
        $id = (string) \Illuminate\Support\Str::uuid();
        DB::table('flow_runs')->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'definition_name' => 'kb.ingest',
            'status' => 'queued',
            'idempotency_key' => $tenantId.':'.$id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function seedApproval(string $tenantId, string $runId): string
    {
        $approvalId = (string) \Illuminate\Support\Str::uuid();
        $tokenHash = hash('sha256', $tenantId.':'.$runId.':'.uniqid('', true));
        DB::table('flow_approvals')->insert([
            'id' => $approvalId,
            'tenant_id' => $tenantId,
            'run_id' => $runId,
            'step_name' => 'await-dpo-decision',
            'token_hash' => $tokenHash,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
            'expires_at' => now()->addDay(),
        ]);

        return $tokenHash;
    }

    private function seedWebhook(string $tenantId, ?string $runId = null): int
    {
        // Either reuse a caller-supplied run id or seed a fresh one so
        // the FK stays valid; nullable column is allowed too but a
        // realistic webhook always belongs to a run.
        $runId = $runId ?? $this->seedRun($tenantId);

        return (int) DB::table('flow_webhook_outbox')->insertGetId([
            'tenant_id' => $tenantId,
            'run_id' => $runId,
            'event' => 'FlowRunStarted',
            'payload' => json_encode(['hello' => 'world']),
            'status' => 'pending',
            'attempts' => 0,
            'max_attempts' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeUser(string $role): User
    {
        $user = User::create([
            'name' => "Test {$role}",
            'email' => $role.'-'.uniqid().'@example.test',
            'password' => Hash::make('secret-password'),
        ]);

        $user->assignRole($role);

        return $user->fresh();
    }
}
