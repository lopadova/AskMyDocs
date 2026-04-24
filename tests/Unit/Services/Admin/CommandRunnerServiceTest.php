<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Admin;

use App\Models\AdminCommandAudit;
use App\Models\AdminCommandNonce;
use App\Models\User;
use App\Services\Admin\CommandRunnerForbidden;
use App\Services\Admin\CommandRunnerService;
use App\Services\Admin\CommandRunnerUnknown;
use App\Services\Admin\CommandRunnerValidation;
use Database\Seeders\RbacSeeder;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Phase H2 — CommandRunnerService unit tests.
 *
 * RefreshDatabase is required because the service writes audit +
 * nonce rows as part of its lifecycle — a pure unit-without-DB would
 * have to mock both models and lose the behaviour we care about.
 *
 * Artisan is swapped to a dummy in-memory command registration so
 * `run()` exercises the full path without launching a real KB prune.
 */
class CommandRunnerServiceTest extends TestCase
{
    use RefreshDatabase;

    private CommandRunnerService $runner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Cache::flush();
        $this->runner = app(CommandRunnerService::class);
    }

    // ------------------------------------------------------------------
    // argument validation
    // ------------------------------------------------------------------

    public function test_unknown_command_throws_unknown_exception(): void
    {
        $user = $this->makeSuperAdmin();

        $this->expectException(CommandRunnerUnknown::class);
        $this->runner->preview('evil:exec', [], $user);
    }

    public function test_shell_metacharacter_command_string_throws_unknown(): void
    {
        $user = $this->makeSuperAdmin();

        $this->expectException(CommandRunnerUnknown::class);
        // Shell-attack-looking string: the array_key_exists lookup can
        // never match because the whole string is the key we're looking
        // for.
        $this->runner->preview('kb:validate-canonical && rm -rf /', [], $user);
    }

    public function test_missing_required_arg_throws_validation(): void
    {
        $user = $this->makeSuperAdmin();

        $this->expectException(CommandRunnerValidation::class);
        // queue:retry requires `id`.
        $this->runner->preview('queue:retry', [], $user);
    }

    public function test_extra_unknown_arg_throws_validation(): void
    {
        $user = $this->makeSuperAdmin();

        $this->expectException(CommandRunnerValidation::class);
        $this->runner->preview('kb:validate-canonical', [
            'project' => 'hr',
            '_danger' => 'rm',
        ], $user);
    }

    public function test_type_drift_throws_validation(): void
    {
        $user = $this->makeSuperAdmin();

        $this->expectException(CommandRunnerValidation::class);
        // kb:prune-deleted.days is int; pass a non-numeric string.
        $this->runner->preview('kb:prune-deleted', ['days' => 'not-a-number'], $user);
    }

    public function test_int_min_max_enforced(): void
    {
        $user = $this->makeSuperAdmin();

        $this->expectException(CommandRunnerValidation::class);
        $this->runner->preview('kb:prune-deleted', ['days' => 500], $user);
    }

    public function test_numeric_string_is_coerced_to_int(): void
    {
        $user = $this->makeSuperAdmin();

        $preview = $this->runner->preview('kb:prune-deleted', ['days' => '30'], $user);

        $this->assertSame(30, $preview['args_validated']['days']);
    }

    public function test_bool_arg_accepted_as_true_literal(): void
    {
        $user = $this->makeSuperAdmin();

        $preview = $this->runner->preview('kb:prune-orphan-files', ['dry_run' => true], $user);

        $this->assertTrue($preview['args_validated']['dry_run']);
    }

    public function test_nullable_arg_can_be_omitted(): void
    {
        $user = $this->makeSuperAdmin();

        $preview = $this->runner->preview('kb:validate-canonical', [], $user);

        $this->assertArrayNotHasKey('project', $preview['args_validated']);
    }

    // ------------------------------------------------------------------
    // args_hash canonicalisation
    // ------------------------------------------------------------------

    public function test_args_hash_is_stable_across_key_reorder(): void
    {
        $h1 = $this->runner->argsHash(['project' => 'hr', 'path' => 'a.md']);
        $h2 = $this->runner->argsHash(['path' => 'a.md', 'project' => 'hr']);

        $this->assertSame($h1, $h2);
    }

    public function test_args_hash_differs_on_value_change(): void
    {
        $h1 = $this->runner->argsHash(['project' => 'hr']);
        $h2 = $this->runner->argsHash(['project' => 'eng']);

        $this->assertNotSame($h1, $h2);
    }

    // ------------------------------------------------------------------
    // permission gate
    // ------------------------------------------------------------------

    public function test_admin_without_destructive_perm_throws_forbidden_on_destructive_command(): void
    {
        $admin = $this->makeAdmin();

        $this->expectException(CommandRunnerForbidden::class);
        $this->runner->preview('kb:prune-deleted', ['days' => 30], $admin);
    }

    public function test_admin_can_preview_non_destructive_command(): void
    {
        $admin = $this->makeAdmin();

        $preview = $this->runner->preview('kb:validate-canonical', ['project' => 'hr'], $admin);

        $this->assertSame('kb:validate-canonical', $preview['command']);
        $this->assertFalse($preview['destructive']);
        $this->assertArrayNotHasKey('confirm_token', $preview);
    }

    public function test_super_admin_preview_destructive_returns_confirm_token(): void
    {
        $super = $this->makeSuperAdmin();

        $preview = $this->runner->preview('kb:prune-deleted', ['days' => 30], $super);

        $this->assertTrue($preview['destructive']);
        $this->assertArrayHasKey('confirm_token', $preview);
        $this->assertIsString($preview['confirm_token']);
        $this->assertNotEmpty($preview['confirm_token']);

        // A row landed in admin_command_nonces with a sha256 of the token.
        $hash = hash('sha256', $preview['confirm_token']);
        $this->assertDatabaseHas('admin_command_nonces', [
            'token_hash' => $hash,
            'command' => 'kb:prune-deleted',
            'user_id' => $super->id,
        ]);
    }

    // ------------------------------------------------------------------
    // audit row lifecycle
    // ------------------------------------------------------------------

    public function test_run_writes_started_then_completed_audit_rows(): void
    {
        $super = $this->makeSuperAdmin();
        $this->registerFakeCommand('test:ok', exit: 0, throw: null);
        $this->allowFakeCommandInConfig('test:ok', destructive: false);

        $result = $this->runner->run(
            'test:ok',
            [],
            null,
            $super,
            '127.0.0.1',
            'phpunit/ua',
        );

        $this->assertIsInt($result['audit_id']);
        $this->assertSame(0, $result['exit_code']);

        $audit = AdminCommandAudit::find($result['audit_id']);
        $this->assertNotNull($audit);
        $this->assertSame(AdminCommandAudit::STATUS_COMPLETED, $audit->status);
        $this->assertSame('test:ok', $audit->command);
        $this->assertSame($super->id, $audit->user_id);
        $this->assertNotNull($audit->completed_at);
    }

    public function test_run_flips_audit_to_failed_on_artisan_throw(): void
    {
        $super = $this->makeSuperAdmin();
        $this->registerFakeCommand('test:boom', exit: 0, throw: new \RuntimeException('artisan boom'));
        $this->allowFakeCommandInConfig('test:boom', destructive: false);

        try {
            $this->runner->run(
                'test:boom',
                [],
                null,
                $super,
                '127.0.0.1',
                'phpunit/ua',
            );
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('artisan boom', $e->getMessage());
        }

        $row = AdminCommandAudit::query()
            ->where('command', 'test:boom')
            ->latest('id')
            ->first();
        $this->assertNotNull($row);
        $this->assertSame(AdminCommandAudit::STATUS_FAILED, $row->status);
        $this->assertSame(-1, $row->exit_code);
        $this->assertStringContainsString('artisan boom', (string) $row->error_message);
    }

    // ------------------------------------------------------------------
    // confirm_token lifecycle
    // ------------------------------------------------------------------

    public function test_destructive_run_without_token_throws_validation(): void
    {
        $super = $this->makeSuperAdmin();

        try {
            $this->runner->run(
                'kb:prune-deleted',
                ['days' => 30],
                null,
                $super,
                '127.0.0.1',
                'phpunit/ua',
            );
            $this->fail('Expected CommandRunnerValidation');
        } catch (CommandRunnerValidation $e) {
            // A rejected audit row was written for forensic purposes.
            $this->assertStringContainsString('confirm_token', $e->getMessage());
            $this->assertDatabaseHas('admin_command_audit', [
                'command' => 'kb:prune-deleted',
                'status' => AdminCommandAudit::STATUS_REJECTED,
            ]);
        }
    }

    public function test_destructive_run_with_valid_token_succeeds_and_marks_used(): void
    {
        $super = $this->makeSuperAdmin();
        $this->registerFakeCommand('test:destroy', exit: 0, throw: null);
        $this->allowFakeCommandInConfig('test:destroy', destructive: true);

        $preview = $this->runner->preview('test:destroy', [], $super);

        $result = $this->runner->run(
            'test:destroy',
            [],
            $preview['confirm_token'],
            $super,
            '127.0.0.1',
            'phpunit/ua',
        );

        $this->assertSame(0, $result['exit_code']);
        $this->assertDatabaseHas('admin_command_audit', [
            'id' => $result['audit_id'],
            'status' => AdminCommandAudit::STATUS_COMPLETED,
        ]);
        $nonce = AdminCommandNonce::query()
            ->where('token_hash', hash('sha256', $preview['confirm_token']))
            ->first();
        $this->assertNotNull($nonce);
        $this->assertNotNull($nonce->used_at);
    }

    public function test_destructive_run_with_reused_token_throws_validation(): void
    {
        $super = $this->makeSuperAdmin();
        $this->registerFakeCommand('test:destroy-reuse', exit: 0, throw: null);
        $this->allowFakeCommandInConfig('test:destroy-reuse', destructive: true);

        $preview = $this->runner->preview('test:destroy-reuse', [], $super);

        $this->runner->run(
            'test:destroy-reuse',
            [],
            $preview['confirm_token'],
            $super,
            '127.0.0.1',
            'phpunit/ua',
        );

        $this->expectException(CommandRunnerValidation::class);
        $this->expectExceptionMessageMatches('/already used/');
        $this->runner->run(
            'test:destroy-reuse',
            [],
            $preview['confirm_token'],
            $super,
            '127.0.0.1',
            'phpunit/ua',
        );
    }

    public function test_destructive_run_with_expired_token_throws_validation(): void
    {
        $super = $this->makeSuperAdmin();

        $preview = $this->runner->preview('kb:prune-deleted', ['days' => 30], $super);

        // Move the clock forward past the TTL.
        Carbon::setTestNow(Carbon::now()->addMinutes(10));

        try {
            $this->runner->run(
                'kb:prune-deleted',
                ['days' => 30],
                $preview['confirm_token'],
                $super,
                '127.0.0.1',
                'phpunit/ua',
            );
            $this->fail('Expected CommandRunnerValidation');
        } catch (CommandRunnerValidation $e) {
            $this->assertStringContainsString('expired', $e->getMessage());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_destructive_run_with_args_mismatch_throws_validation(): void
    {
        $super = $this->makeSuperAdmin();

        $preview = $this->runner->preview('kb:prune-deleted', ['days' => 30], $super);

        $this->expectException(CommandRunnerValidation::class);
        $this->expectExceptionMessageMatches('/fingerprint mismatch/i');
        $this->runner->run(
            'kb:prune-deleted',
            ['days' => 90], // mismatched days
            $preview['confirm_token'],
            $super,
            '127.0.0.1',
            'phpunit/ua',
        );
    }

    // ------------------------------------------------------------------
    // catalogue filtering
    // ------------------------------------------------------------------

    public function test_catalogue_filters_out_commands_user_cannot_run(): void
    {
        $admin = $this->makeAdmin();
        $super = $this->makeSuperAdmin();

        $adminCat = $this->runner->catalogueFor($admin);
        $superCat = $this->runner->catalogueFor($super);

        // admin: has commands.run but NOT commands.destructive.
        $this->assertArrayHasKey('kb:validate-canonical', $adminCat);
        $this->assertArrayHasKey('kb:rebuild-graph', $adminCat);
        $this->assertArrayNotHasKey('kb:prune-deleted', $adminCat);
        $this->assertArrayNotHasKey('kb:ingest-folder', $adminCat);

        // super-admin: has both.
        $this->assertArrayHasKey('kb:validate-canonical', $superCat);
        $this->assertArrayHasKey('kb:prune-deleted', $superCat);
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

    /**
     * Register a throwaway artisan command the runner can invoke in
     * tests without mocking the Kernel facade (Testbench v11 ships a
     * final kernel that Mockery can't mock).
     *
     * If `$throw` is a Throwable the command's handle() rethrows it,
     * giving us an exact hook into the `status=failed` audit path.
     */
    private function registerFakeCommand(string $name, int $exit, ?\Throwable $throw): void
    {
        $cmd = new class extends Command
        {
            public string $cmdName = 'test:placeholder';

            public int $cmdExit = 0;

            public ?\Throwable $cmdThrow = null;

            protected $signature = 'test:placeholder {--days=} {--project=}';

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
        $cmd->cmdName = $name;
        $cmd->cmdExit = $exit;
        $cmd->cmdThrow = $throw;

        app()->make(\Illuminate\Contracts\Console\Kernel::class)
            ->registerCommand($cmd);
    }

    /**
     * @param  array<string, array<string, mixed>>  $argsSchema
     */
    private function allowFakeCommandInConfig(string $name, bool $destructive, array $argsSchema = []): void
    {
        $allowed = config('admin.allowed_commands', []);
        $allowed[$name] = [
            'args_schema' => $argsSchema,
            'requires_permission' => $destructive ? 'commands.destructive' : 'commands.run',
            'destructive' => $destructive,
            'description' => 'test command',
        ];
        config()->set('admin.allowed_commands', $allowed);
    }
}
