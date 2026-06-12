<?php

declare(strict_types=1);

namespace Tests\Unit\Widget;

use App\Console\Commands\PruneWidgetSessionsCommand;
use App\Models\WidgetKey;
use App\Models\WidgetSession;
use App\Models\WidgetSessionStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * M5.10 — widget:prune-sessions command.
 *
 * Verifies:
 *   (a) sessions older than the retention period are hard-deleted
 *   (b) recent sessions are kept
 *   (c) memory-safe chunked execution (chunkById, not all-at-once)
 */
final class PruneWidgetSessionsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Ensure a predictable config value for tests
        config(['widget.session_retention_days' => 90]);
    }

    private function makeKey(array $overrides = []): WidgetKey
    {
        static $n = 0;
        $n++;

        return WidgetKey::create(array_merge([
            'tenant_id' => 'default',
            'project_key' => 'docs-v3',
            'public_key' => 'pk_prune_'.$n,
            'allowed_origins' => ['https://allowed.test'],
            'rate_limit' => 1000,
            'skill' => 'askmydocs-assistant@1',
            'is_active' => true,
            'label' => 'prune-'.$n,
        ], $overrides));
    }

    private function createSession(WidgetKey $key, string $createdAt, string $tenantId = 'default'): WidgetSession
    {
        $session = WidgetSession::factory()->create([
            'tenant_id' => $tenantId,
            'widget_key_id' => $key->id,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        // Create a step so cascade-delete is exercised
        WidgetSessionStep::create([
            'tenant_id' => $tenantId,
            'widget_session_id' => $session->id,
            'step_index' => 0,
            'kind' => WidgetSessionStep::KIND_USER_MESSAGE,
            'args_json' => ['text' => 'hello'],
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        return $session;
    }

    public function test_old_sessions_are_deleted(): void
    {
        $key = $this->makeKey();
        $oldSession = $this->createSession($key, now()->subDays(120)->toDateTimeString());

        $this->artisan('widget:prune-sessions')
            ->assertSuccessful();

        $this->assertDatabaseMissing('widget_sessions', ['id' => $oldSession->id]);
        $this->assertDatabaseMissing('widget_session_steps', ['widget_session_id' => $oldSession->id]);
    }

    /** #29 — i session token SCADUTI sono prunati; i validi restano (anche senza sessioni vecchie). */
    public function test_expired_session_tokens_are_pruned(): void
    {
        $key = $this->makeKey();

        $expired = \App\Models\WidgetSessionToken::create([
            'tenant_id' => 'default',
            'token' => hash('sha256', 'wt_expired'),
            'widget_key_id' => $key->id,
            'origin' => null,
            'expires_at' => now()->subHour(),
        ]);
        $valid = \App\Models\WidgetSessionToken::create([
            'tenant_id' => 'default',
            'token' => hash('sha256', 'wt_valid'),
            'widget_key_id' => $key->id,
            'origin' => null,
            'expires_at' => now()->addHour(),
        ]);

        $this->artisan('widget:prune-sessions')->assertExitCode(0);

        $this->assertDatabaseMissing('widget_session_tokens', ['id' => $expired->id]);
        $this->assertDatabaseHas('widget_session_tokens', ['id' => $valid->id]);
    }

    public function test_recent_sessions_are_kept(): void
    {
        $key = $this->makeKey();
        $recentSession = $this->createSession($key, now()->subDays(10)->toDateTimeString());

        $this->artisan('widget:prune-sessions')
            ->assertSuccessful();

        $this->assertDatabaseHas('widget_sessions', ['id' => $recentSession->id]);
        $this->assertDatabaseHas('widget_session_steps', ['widget_session_id' => $recentSession->id]);
    }

    public function test_boundary_session_at_exact_cutoff_is_kept(): void
    {
        $key = $this->makeKey();
        // A session exactly 90 days old should NOT be deleted (< cutoff, not <=)
        $boundarySession = $this->createSession($key, now()->subDays(90)->toDateTimeString());

        $this->artisan('widget:prune-sessions')
            ->assertSuccessful();

        $this->assertDatabaseHas('widget_sessions', ['id' => $boundarySession->id]);
    }

    public function test_days_option_overrides_config(): void
    {
        $key = $this->makeKey();
        // 30 days old — within default 90-day window, but outside --days=7
        $session = $this->createSession($key, now()->subDays(30)->toDateTimeString());

        $this->artisan('widget:prune-sessions', ['--days' => 7])
            ->assertSuccessful();

        $this->assertDatabaseMissing('widget_sessions', ['id' => $session->id]);
    }

    public function test_zero_retention_skips_rotation(): void
    {
        $key = $this->makeKey();
        $oldSession = $this->createSession($key, now()->subDays(200)->toDateTimeString());

        $this->artisan('widget:prune-sessions', ['--days' => 0])
            ->assertSuccessful();

        // Nothing should be deleted when retention is 0
        $this->assertDatabaseHas('widget_sessions', ['id' => $oldSession->id]);
    }

    public function test_tenant_option_restricts_scope(): void
    {
        $keyDefault = $this->makeKey(['tenant_id' => 'default']);
        $keyOther = $this->makeKey(['tenant_id' => 'other', 'public_key' => 'pk_other_t']);

        $oldDefault = $this->createSession($keyDefault, now()->subDays(120)->toDateTimeString(), 'default');
        $oldOther = $this->createSession($keyOther, now()->subDays(120)->toDateTimeString(), 'other');

        $this->artisan('widget:prune-sessions', ['--tenant' => 'other'])
            ->assertSuccessful();

        // 'other' tenant session deleted
        $this->assertDatabaseMissing('widget_sessions', ['id' => $oldOther->id]);
        // 'default' tenant session untouched
        $this->assertDatabaseHas('widget_sessions', ['id' => $oldDefault->id]);
    }

    public function test_uses_chunkById_for_memory_safety(): void
    {
        // Verify the command class references chunkById by inspecting
        // the source — this is a static guarantee (R3).
        $source = file_get_contents(
            (new \ReflectionClass(PruneWidgetSessionsCommand::class))->getFileName()
        );

        $this->assertStringContainsString('chunkById', $source,
            'PruneWidgetSessionsCommand must use chunkById (R3: memory-safe).');

        // Ensure no bare ->get() without chunking
        $this->assertStringNotContainsString("->get()\n", $source,
            'PruneWidgetSessionsCommand should not call ->get() without chunking (R3).');
    }

    public function test_handles_large_dataset_with_chunking(): void
    {
        // Create enough sessions to span multiple chunks (chunk size is 100)
        $key = $this->makeKey();
        $oldIds = [];
        for ($i = 0; $i < 150; $i++) {
            $session = $this->createSession($key, now()->subDays(120)->toDateTimeString());
            $oldIds[] = $session->id;
        }

        // Create a recent session to prove it survives
        $recentSession = $this->createSession($key, now()->subDays(5)->toDateTimeString());

        $this->artisan('widget:prune-sessions')
            ->assertSuccessful();

        // All 150 old sessions deleted
        foreach ($oldIds as $id) {
            $this->assertDatabaseMissing('widget_sessions', ['id' => $id]);
        }

        // Recent session preserved
        $this->assertDatabaseHas('widget_sessions', ['id' => $recentSession->id]);
    }
}