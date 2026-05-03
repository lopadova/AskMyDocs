<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Admin;

use App\Models\AdminCommandAudit;
use App\Models\ChatLog;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Padosoft\PiiRedactor\Facades\Pii;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * v4.1/W4.1.D — feature tests for the operator-driven detokenisation
 * action on the Log Viewer.
 *
 * Three observable contracts:
 *   1. Strategy mismatch (mask / hash / drop configured) → 422 with no
 *      audit row, no detokenise attempt.
 *   2. Authenticated WITHOUT `pii.detokenize` permission → 403,
 *      `admin_command_audit` row written with status `rejected`.
 *   3. Authenticated WITH the permission → 200 returning the
 *      plaintext originals, `admin_command_audit` row written with
 *      status `completed`.
 *
 * The TokeniseStrategy round-trip is exercised against a real
 * `pii_token_maps` row so the assertion proves the controller wires
 * the package correctly — not just its own happy path.
 */
final class LogViewerDetokenizeTest extends TestCase
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
    }

    public function test_returns_422_when_strategy_is_not_tokenise(): void
    {
        // Default test config uses `mask` (set by TestCase /
        // sibling test classes); explicitly assert it here so the
        // contract is visible in the test body.
        config()->set('pii-redactor.strategy', 'mask');

        $admin = $this->makeAdmin(withDetokenize: true);
        $log = $this->makeChatLog();

        $res = $this->actingAs($admin)
            ->postJson("/api/admin/logs/chat/{$log->id}/detokenize")
            ->assertStatus(422)
            ->assertJsonPath('message', 'PII detokenisation requires the `tokenise` strategy.');

        // No audit row written — the 422 is a config preflight, not
        // an operator action against the data.
        $this->assertDatabaseMissing('admin_command_audit', [
            'command' => 'pii.detokenize',
        ]);
    }

    public function test_returns_403_and_audits_rejection_when_missing_permission(): void
    {
        config()->set('pii-redactor.strategy', 'tokenise');
        config()->set('pii-redactor.salt', 'detokenize-test-salt-do-not-use-in-prod');

        // Admin role exists but the dedicated permission is NOT
        // granted to this user — exercises the permission gate
        // independently of the role hierarchy.
        $user = $this->makeAdmin(withDetokenize: false);

        $log = $this->makeChatLog([
            'question' => Pii::redact('Email mario@example.com please'),
            'answer' => 'a',
        ]);

        $this->actingAs($user)
            ->postJson("/api/admin/logs/chat/{$log->id}/detokenize")
            ->assertStatus(403)
            ->assertJsonPath('message', 'Forbidden: missing pii.detokenize permission.');

        // Audit trail captures the rejection so abuse attempts are
        // visible alongside successful unmasks.
        $audit = AdminCommandAudit::query()
            ->where('command', 'pii.detokenize')
            ->latest('id')
            ->first();
        $this->assertNotNull($audit);
        $this->assertSame(AdminCommandAudit::STATUS_REJECTED, $audit->status);
        $this->assertSame((int) $user->id, (int) $audit->user_id);
        $this->assertSame(['chat_log_id' => $log->id], $audit->args_json);
    }

    public function test_returns_originals_and_audits_when_permission_granted(): void
    {
        config()->set('pii-redactor.strategy', 'tokenise');
        config()->set('pii-redactor.salt', 'detokenize-test-salt-do-not-use-in-prod');

        $admin = $this->makeAdmin(withDetokenize: true);

        // Tokenise the question and persist the redacted form into
        // chat_logs — what the W4.1.B + v4.1.C pipeline would have
        // recorded for a chat turn after the middleware fired.
        $original = 'Email me at mario@example.com please';
        $tokenised = Pii::redact($original);
        $this->assertNotSame(
            $original,
            $tokenised,
            'Sanity: the tokenise strategy must replace the email; '
            .'otherwise the round-trip below is meaningless.',
        );

        $log = $this->makeChatLog([
            'question' => $tokenised,
            'answer' => 'plain answer with no pii',
        ]);

        $payload = $this->actingAs($admin)
            ->postJson("/api/admin/logs/chat/{$log->id}/detokenize")
            ->assertOk()
            ->json();

        $this->assertSame($log->id, $payload['id']);
        $this->assertSame(
            $original,
            $payload['question'],
            'The detokenise action must round-trip back to the original input.',
        );
        $this->assertSame('plain answer with no pii', $payload['answer']);

        $audit = AdminCommandAudit::query()
            ->where('command', 'pii.detokenize')
            ->where('status', AdminCommandAudit::STATUS_COMPLETED)
            ->latest('id')
            ->first();
        $this->assertNotNull($audit);
        $this->assertSame((int) $admin->id, (int) $audit->user_id);
        $this->assertSame(['chat_log_id' => $log->id], $audit->args_json);
    }

    /**
     * Make an admin and optionally grant the `pii.detokenize`
     * permission. Spatie's permission cache is cleared in setUp so
     * the new permission is immediately visible to `$user->can()`.
     */
    private function makeAdmin(bool $withDetokenize): User
    {
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);
        $admin->assignRole('admin');

        if ($withDetokenize) {
            $perm = Permission::firstOrCreate(['name' => 'pii.detokenize', 'guard_name' => 'web']);
            $admin->givePermissionTo($perm);
        }

        return $admin;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeChatLog(array $overrides = []): ChatLog
    {
        return ChatLog::create(array_merge([
            'session_id' => (string) Str::uuid(),
            'user_id' => null,
            'question' => 'q',
            'answer' => 'a',
            'project_key' => 'hr-portal',
            'ai_provider' => 'openai',
            'ai_model' => 'gpt-4o',
            'chunks_count' => 0,
            'sources' => [],
            'prompt_tokens' => 10,
            'completion_tokens' => 20,
            'total_tokens' => 30,
            'latency_ms' => 100,
            'created_at' => Carbon::now()->toDateTimeString(),
        ], $overrides));
    }
}
