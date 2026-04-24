<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Admin;

use App\Models\AdminCommandAudit;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Phase H2 — admin maintenance command runner feature tests.
 *
 * Covers every unhappy path in the H2 contract:
 *   - whitelist miss (404 for unknown OR shell-metacharacter strings)
 *   - permission denied (403, rejected-audit row)
 *   - destructive without confirm_token (422)
 *   - expired confirm_token (422)
 *   - reused confirm_token (422)
 *   - args fingerprint mismatch preview vs run (422)
 *   - args type drift (422)
 *   - extra unknown arg (422)
 *   - rate limit 11th call → 429
 *   - guest → 401
 *
 * Plus the two happy paths (non-destructive admin + destructive
 * super-admin) and the read-only endpoints (catalogue / history /
 * scheduler-status).
 */
class MaintenanceCommandControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->group(__DIR__.'/../../../../routes/api.php');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Cache::flush();
        // Reset the throttle counter between tests so a 429 scenario
        // does not leak into the next test's happy path.
        RateLimiter::clear('throttle:10,1');
    }

    // ------------------------------------------------------------------
    // catalogue
    // ------------------------------------------------------------------

    public function test_catalogue_lists_only_commands_user_can_run(): void
    {
        $admin = $this->makeAdmin();
        $super = $this->makeSuperAdmin();

        $adminRes = $this->actingAs($admin)->getJson('/api/admin/commands/catalogue')->assertOk()->json('data');
        $superRes = $this->actingAs($super)->getJson('/api/admin/commands/catalogue')->assertOk()->json('data');

        $this->assertArrayHasKey('kb:validate-canonical', $adminRes);
        $this->assertArrayNotHasKey('kb:prune-deleted', $adminRes);

        $this->assertArrayHasKey('kb:validate-canonical', $superRes);
        $this->assertArrayHasKey('kb:prune-deleted', $superRes);
    }

    // ------------------------------------------------------------------
    // preview
    // ------------------------------------------------------------------

    public function test_preview_non_destructive_admin_succeeds_no_token(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->postJson('/api/admin/commands/preview', [
                'command' => 'kb:validate-canonical',
                'args' => ['project' => 'hr-portal'],
            ])
            ->assertOk()
            ->assertJsonPath('destructive', false)
            ->assertJsonMissingPath('confirm_token');
    }

    public function test_preview_destructive_super_admin_returns_confirm_token(): void
    {
        $super = $this->makeSuperAdmin();

        $this->actingAs($super)
            ->postJson('/api/admin/commands/preview', [
                'command' => 'kb:prune-deleted',
                'args' => ['days' => 30],
            ])
            ->assertOk()
            ->assertJsonPath('destructive', true)
            ->assertJsonStructure(['confirm_token', 'confirm_token_expires_at']);
    }

    public function test_preview_unknown_command_returns_404(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->postJson('/api/admin/commands/preview', [
                'command' => 'evil:exec',
                'args' => [],
            ])
            ->assertNotFound();
    }

    public function test_preview_shell_metacharacter_command_returns_404(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->postJson('/api/admin/commands/preview', [
                'command' => 'kb:validate-canonical && rm -rf /',
                'args' => [],
            ])
            ->assertNotFound();
    }

    public function test_preview_missing_permission_returns_403(): void
    {
        $viewer = $this->makeViewer();

        $this->actingAs($viewer)
            ->postJson('/api/admin/commands/preview', [
                'command' => 'kb:validate-canonical',
                'args' => [],
            ])
            ->assertStatus(403);
    }

    public function test_preview_destructive_as_admin_returns_403(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->postJson('/api/admin/commands/preview', [
                'command' => 'kb:prune-deleted',
                'args' => ['days' => 30],
            ])
            ->assertStatus(403);
    }

    public function test_preview_type_drift_returns_422(): void
    {
        $super = $this->makeSuperAdmin();

        $this->actingAs($super)
            ->postJson('/api/admin/commands/preview', [
                'command' => 'kb:prune-deleted',
                'args' => ['days' => 'not-a-number'],
            ])
            ->assertStatus(422);
    }

    public function test_preview_extra_arg_returns_422(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->postJson('/api/admin/commands/preview', [
                'command' => 'kb:validate-canonical',
                'args' => ['project' => 'hr', '_danger' => '…'],
            ])
            ->assertStatus(422);
    }

    // ------------------------------------------------------------------
    // run
    // ------------------------------------------------------------------

    public function test_run_non_destructive_admin_happy_path(): void
    {
        $admin = $this->makeAdmin();
        $this->registerFakeCommand('test:ok', exit: 0, throw: null);
        $this->allowFakeCommandInConfig('test:ok', destructive: false);

        $res = $this->actingAs($admin)
            ->postJson('/api/admin/commands/run', [
                'command' => 'test:ok',
                'args' => [],
            ])
            ->assertOk()
            ->json();

        $this->assertSame(0, $res['exit_code']);
        $this->assertIsInt($res['audit_id']);

        $this->assertDatabaseHas('admin_command_audit', [
            'id' => $res['audit_id'],
            'command' => 'test:ok',
            'status' => AdminCommandAudit::STATUS_COMPLETED,
        ]);
    }

    public function test_run_destructive_with_confirm_token_happy_path(): void
    {
        $super = $this->makeSuperAdmin();
        $this->registerFakeCommand('test:destroy', exit: 0, throw: null);
        $this->allowFakeCommandInConfig('test:destroy', destructive: true);

        $preview = $this->actingAs($super)
            ->postJson('/api/admin/commands/preview', [
                'command' => 'test:destroy',
                'args' => [],
            ])
            ->assertOk()
            ->json();

        $this->actingAs($super)
            ->postJson('/api/admin/commands/run', [
                'command' => 'test:destroy',
                'args' => [],
                'confirm_token' => $preview['confirm_token'],
            ])
            ->assertOk();

        $this->assertDatabaseHas('admin_command_audit', [
            'command' => 'test:destroy',
            'status' => AdminCommandAudit::STATUS_COMPLETED,
        ]);
    }

    public function test_run_unknown_command_returns_404_and_writes_rejected_audit(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->postJson('/api/admin/commands/run', [
                'command' => 'evil:exec',
                'args' => [],
            ])
            ->assertNotFound();

        $this->assertDatabaseHas('admin_command_audit', [
            'command' => 'evil:exec',
            'status' => AdminCommandAudit::STATUS_REJECTED,
        ]);
    }

    public function test_run_destructive_without_confirm_token_returns_422(): void
    {
        $super = $this->makeSuperAdmin();

        $this->actingAs($super)
            ->postJson('/api/admin/commands/run', [
                'command' => 'kb:prune-deleted',
                'args' => ['days' => 30],
            ])
            ->assertStatus(422);

        $this->assertDatabaseMissing('admin_command_audit', [
            'command' => 'kb:prune-deleted',
            'status' => AdminCommandAudit::STATUS_COMPLETED,
        ]);
    }

    public function test_run_destructive_expired_confirm_token_returns_422(): void
    {
        $super = $this->makeSuperAdmin();
        $this->registerFakeCommand('test:expire', exit: 0, throw: null);
        $this->allowFakeCommandInConfig('test:expire', destructive: true);

        $preview = $this->actingAs($super)
            ->postJson('/api/admin/commands/preview', [
                'command' => 'test:expire',
                'args' => [],
            ])
            ->assertOk()
            ->json();

        // Jump past the 5-minute TTL.
        Carbon::setTestNow(Carbon::now()->addMinutes(10));

        try {
            $this->actingAs($super)
                ->postJson('/api/admin/commands/run', [
                    'command' => 'test:expire',
                    'args' => [],
                    'confirm_token' => $preview['confirm_token'],
                ])
                ->assertStatus(422);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_run_destructive_reused_confirm_token_returns_422(): void
    {
        $super = $this->makeSuperAdmin();
        $this->registerFakeCommand('test:reuse', exit: 0, throw: null);
        $this->allowFakeCommandInConfig('test:reuse', destructive: true);

        $preview = $this->actingAs($super)
            ->postJson('/api/admin/commands/preview', [
                'command' => 'test:reuse',
                'args' => [],
            ])
            ->assertOk()
            ->json();

        $this->actingAs($super)
            ->postJson('/api/admin/commands/run', [
                'command' => 'test:reuse',
                'args' => [],
                'confirm_token' => $preview['confirm_token'],
            ])
            ->assertOk();

        $this->actingAs($super)
            ->postJson('/api/admin/commands/run', [
                'command' => 'test:reuse',
                'args' => [],
                'confirm_token' => $preview['confirm_token'],
            ])
            ->assertStatus(422);
    }

    public function test_run_destructive_args_mismatch_returns_422(): void
    {
        $super = $this->makeSuperAdmin();

        $preview = $this->actingAs($super)
            ->postJson('/api/admin/commands/preview', [
                'command' => 'kb:prune-deleted',
                'args' => ['days' => 30],
            ])
            ->assertOk()
            ->json();

        $this->actingAs($super)
            ->postJson('/api/admin/commands/run', [
                'command' => 'kb:prune-deleted',
                'args' => ['days' => 90], // mismatched
                'confirm_token' => $preview['confirm_token'],
            ])
            ->assertStatus(422);
    }

    public function test_run_viewer_returns_403(): void
    {
        $viewer = $this->makeViewer();

        $this->actingAs($viewer)
            ->postJson('/api/admin/commands/run', [
                'command' => 'kb:validate-canonical',
                'args' => [],
            ])
            ->assertStatus(403);
    }

    public function test_run_type_drift_returns_422(): void
    {
        $super = $this->makeSuperAdmin();

        $this->actingAs($super)
            ->postJson('/api/admin/commands/run', [
                'command' => 'kb:prune-deleted',
                'args' => ['days' => 'not-a-number'],
            ])
            ->assertStatus(422);
    }

    public function test_run_extra_arg_returns_422(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->postJson('/api/admin/commands/run', [
                'command' => 'kb:validate-canonical',
                'args' => ['days' => 30, '_danger' => 'rm'],
            ])
            ->assertStatus(422);
    }

    public function test_run_rate_limit_11th_call_returns_429(): void
    {
        $admin = $this->makeAdmin();
        $this->registerFakeCommand('test:rate', exit: 0, throw: null);
        $this->allowFakeCommandInConfig('test:rate', destructive: false);

        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($admin)
                ->postJson('/api/admin/commands/run', [
                    'command' => 'test:rate',
                    'args' => [],
                ])
                ->assertOk();
        }

        $this->actingAs($admin)
            ->postJson('/api/admin/commands/run', [
                'command' => 'test:rate',
                'args' => [],
            ])
            ->assertStatus(429);
    }

    // ------------------------------------------------------------------
    // history + scheduler-status
    // ------------------------------------------------------------------

    public function test_history_paginated_and_filterable(): void
    {
        $admin = $this->makeAdmin();

        AdminCommandAudit::create([
            'user_id' => $admin->id,
            'command' => 'kb:validate-canonical',
            'args_json' => [],
            'status' => AdminCommandAudit::STATUS_COMPLETED,
            'started_at' => now(),
            'completed_at' => now(),
            'exit_code' => 0,
        ]);
        AdminCommandAudit::create([
            'user_id' => $admin->id,
            'command' => 'kb:rebuild-graph',
            'args_json' => [],
            'status' => AdminCommandAudit::STATUS_COMPLETED,
            'started_at' => now(),
            'completed_at' => now(),
            'exit_code' => 0,
        ]);

        $this->actingAs($admin)
            ->getJson('/api/admin/commands/history?command=kb:validate-canonical')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->actingAs($admin)
            ->getJson('/api/admin/commands/history')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
    }

    public function test_scheduler_status_returns_static_schedule(): void
    {
        $admin = $this->makeAdmin();

        $res = $this->actingAs($admin)
            ->getJson('/api/admin/commands/scheduler-status')
            ->assertOk()
            ->json('data');

        $this->assertIsArray($res);
        $this->assertGreaterThan(0, count($res));
    }

    public function test_history_guest_401(): void
    {
        $this->getJson('/api/admin/commands/history')->assertStatus(401);
    }

    public function test_all_endpoints_401_for_guest(): void
    {
        $this->getJson('/api/admin/commands/catalogue')->assertStatus(401);
        $this->postJson('/api/admin/commands/preview', [
            'command' => 'kb:validate-canonical',
            'args' => [],
        ])->assertStatus(401);
        $this->postJson('/api/admin/commands/run', [
            'command' => 'kb:validate-canonical',
            'args' => [],
        ])->assertStatus(401);
        $this->getJson('/api/admin/commands/history')->assertStatus(401);
        $this->getJson('/api/admin/commands/scheduler-status')->assertStatus(401);
    }

    public function test_history_viewer_403(): void
    {
        // viewer has no logs.* or commands.* access on this route because
        // the whole /api/admin group demands role:admin|super-admin.
        $viewer = $this->makeViewer();
        $this->actingAs($viewer)->getJson('/api/admin/commands/history')->assertStatus(403);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function makeAdmin(): User
    {
        $u = User::create([
            'name' => 'A',
            'email' => 'admin-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $u->assignRole('admin');

        return $u;
    }

    private function makeSuperAdmin(): User
    {
        $u = User::create([
            'name' => 'S',
            'email' => 'super-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $u->assignRole('super-admin');

        return $u;
    }

    private function makeViewer(): User
    {
        $u = User::create([
            'name' => 'V',
            'email' => 'viewer-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $u->assignRole('viewer');

        return $u;
    }

    private function registerFakeCommand(string $name, int $exit, ?\Throwable $throw): void
    {
        $cmd = new class extends Command
        {
            public int $cmdExit = 0;

            public ?\Throwable $cmdThrow = null;

            protected $signature = 'test:placeholder {--days=} {--project=} {--dry-run}';

            protected $description = 'placeholder';

            public function handle(): int
            {
                if ($this->cmdThrow !== null) {
                    throw $this->cmdThrow;
                }

                return $this->cmdExit;
            }
        };
        $cmd->setName($name);
        $cmd->cmdExit = $exit;
        $cmd->cmdThrow = $throw;

        app()->make(\Illuminate\Contracts\Console\Kernel::class)
            ->registerCommand($cmd);
    }

    private function allowFakeCommandInConfig(string $name, bool $destructive): void
    {
        $allowed = config('admin.allowed_commands', []);
        $allowed[$name] = [
            'args_schema' => [],
            'requires_permission' => $destructive ? 'commands.destructive' : 'commands.run',
            'destructive' => $destructive,
            'description' => 'test command',
        ];
        config()->set('admin.allowed_commands', $allowed);
    }
}
