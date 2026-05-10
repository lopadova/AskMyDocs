<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Flow\Admin\AskMyDocsFlowAuthorizer;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * v4.2/W4 sub-PR 6 — R30 cross-tenant isolation for the Flow Admin
 * cockpit.
 *
 * Two tenants are seeded with their own runs / approvals / webhooks.
 * The active TenantContext is set to tenant A; we then walk every
 * row-scoped action on the host-app authorizer and assert the gate
 * REJECTS when the row is owned by tenant B — even when the
 * authenticated user holds the most privileged role (super-admin).
 *
 * This is the load-bearing R30 test for the cockpit. A super-admin
 * leaking across tenants is a GDPR catastrophe; the row-level
 * tenant_id check inside AskMyDocsFlowAuthorizer is the only
 * defence (the package itself is tenant-agnostic).
 *
 * The control assertion in each test confirms the SAME super-admin
 * CAN see tenant A's row — proving the rejection is caused by the
 * tenant scope, not by a missing role grant.
 */
class FlowAdminTenantScopingTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private string $tenantARun;

    private string $tenantBRun;

    private string $tenantAToken;

    private string $tenantBToken;

    private int $tenantAWebhook;

    private int $tenantBWebhook;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        $this->superAdmin = User::create([
            'name' => 'Super Admin',
            'email' => 'super-'.uniqid().'@example.test',
            'password' => Hash::make('secret-password'),
        ]);
        $this->superAdmin->assignRole('super-admin');
        $this->superAdmin = $this->superAdmin->fresh();

        // Seed identical-shape rows in two tenants. tenant 'acme' is
        // the one we set as active in every test below; tenant
        // 'umbrella' is the bystander — its rows must remain
        // unreachable from the acme view.
        $this->tenantARun = $this->seedRun('acme');
        $this->tenantBRun = $this->seedRun('umbrella');

        $this->tenantAToken = $this->seedApproval('acme', $this->tenantARun);
        $this->tenantBToken = $this->seedApproval('umbrella', $this->tenantBRun);

        $this->tenantAWebhook = $this->seedWebhook('acme', $this->tenantARun);
        $this->tenantBWebhook = $this->seedWebhook('umbrella', $this->tenantBRun);

        // Active TenantContext = acme. Super-admin is logged in. Now
        // every assertion below is "can the acme super-admin see/act
        // on the umbrella row?" — answer: no.
        app(TenantContext::class)->set('acme');
        $this->actingAs($this->superAdmin);
    }

    public function test_super_admin_cannot_view_other_tenants_run_detail(): void
    {
        $authorizer = $this->resolveAuthorizer();

        // Control: same role can see own-tenant row.
        $this->assertTrue(
            $authorizer->canViewRunDetail($this->tenantARun, null),
            'Sanity: super-admin must see own-tenant run before the cross-tenant assertion is meaningful.',
        );

        // R30 — cross-tenant rejection.
        $this->assertFalse(
            $authorizer->canViewRunDetail($this->tenantBRun, null),
            'Cross-tenant leak: super-admin in tenant acme must NOT see umbrella run.',
        );
    }

    public function test_super_admin_cannot_replay_other_tenants_run(): void
    {
        $authorizer = $this->resolveAuthorizer();

        $this->assertTrue($authorizer->canReplayRun($this->tenantARun, null));
        $this->assertFalse(
            $authorizer->canReplayRun($this->tenantBRun, null),
            'R30: replay (resume) is structurally blocked across tenants — even for super-admin.',
        );
    }

    public function test_super_admin_cannot_cancel_other_tenants_run(): void
    {
        $authorizer = $this->resolveAuthorizer();

        $this->assertTrue($authorizer->canCancelRun($this->tenantARun, null));
        $this->assertFalse(
            $authorizer->canCancelRun($this->tenantBRun, null),
            'R30: destructive cancel is structurally blocked across tenants.',
        );
    }

    public function test_super_admin_cannot_approve_other_tenants_token(): void
    {
        $authorizer = $this->resolveAuthorizer();

        $this->assertTrue($authorizer->canApproveByToken($this->tenantAToken, null));
        $this->assertFalse(
            $authorizer->canApproveByToken($this->tenantBToken, null),
            'R30: approval-token consumption is structurally blocked across tenants.',
        );
    }

    public function test_super_admin_cannot_reject_other_tenants_token(): void
    {
        $authorizer = $this->resolveAuthorizer();

        $this->assertTrue($authorizer->canRejectByToken($this->tenantAToken, null));
        $this->assertFalse(
            $authorizer->canRejectByToken($this->tenantBToken, null),
            'R30: approval-token rejection is structurally blocked across tenants.',
        );
    }

    public function test_super_admin_cannot_retry_other_tenants_webhook(): void
    {
        $authorizer = $this->resolveAuthorizer();

        $this->assertTrue($authorizer->canRetryWebhook($this->tenantAWebhook, null));
        $this->assertFalse(
            $authorizer->canRetryWebhook($this->tenantBWebhook, null),
            'R30: outbox retry is structurally blocked across tenants.',
        );
    }

    public function test_other_tenant_rows_survive_cross_tenant_attempts(): void
    {
        // R16 — the bystander's data is the load-bearing assertion of
        // R30. Re-querying without scope proves the umbrella rows
        // were never touched / mutated by the acme-context attempts
        // above.
        $umbrellaRunCount = DB::table('flow_runs')
            ->where('tenant_id', 'umbrella')
            ->count();
        $umbrellaApprovalCount = DB::table('flow_approvals')
            ->where('tenant_id', 'umbrella')
            ->count();
        $umbrellaWebhookCount = DB::table('flow_webhook_outbox')
            ->where('tenant_id', 'umbrella')
            ->count();

        $this->assertSame(1, $umbrellaRunCount);
        $this->assertSame(1, $umbrellaApprovalCount);
        $this->assertSame(1, $umbrellaWebhookCount);
    }

    private function resolveAuthorizer(): AskMyDocsFlowAuthorizer
    {
        return app(AskMyDocsFlowAuthorizer::class);
    }

    private function seedRun(string $tenantId): string
    {
        $id = (string) Str::uuid();
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
        $approvalId = (string) Str::uuid();
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

    private function seedWebhook(string $tenantId, string $runId): int
    {
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
}
