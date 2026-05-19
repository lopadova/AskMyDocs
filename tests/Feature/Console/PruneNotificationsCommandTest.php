<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\NotificationEvent;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * v8.0/W1.5 — coverage for `notifications:prune` Artisan command.
 *
 * The schedule slot (`bootstrap/app.php` line ~168) wires this at
 * 04:10 daily. The command MUST:
 *   - hard-delete rows whose `created_at < now() - {days}`
 *   - leave rows newer than the cutoff untouched
 *   - be idempotent — re-run on an already-pruned table is a no-op
 *   - respect `--days=0` short-circuit (warning, exit 0, no deletes)
 *   - respect the `--days` CLI override over the config default
 *   - apply to ALL rows regardless of read/dismissed state
 */
final class PruneNotificationsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set('default');
    }

    public function test_deletes_rows_older_than_retention_window(): void
    {
        $user = $this->makeUser('retain');
        $stale = $this->makeEvent($user, now()->subDays(100));
        $fresh = $this->makeEvent($user, now()->subDays(10));

        $this->artisan('notifications:prune')
            ->assertSuccessful();

        $this->assertNull(NotificationEvent::find($stale->id));
        $this->assertNotNull(NotificationEvent::find($fresh->id));
    }

    public function test_idempotent_replay_is_no_op(): void
    {
        $user = $this->makeUser('idem');
        $this->makeEvent($user, now()->subDays(100));

        $this->artisan('notifications:prune')->assertSuccessful();
        $this->assertSame(0, NotificationEvent::query()->count());

        // Replay should not throw or alter anything.
        $this->artisan('notifications:prune')->assertSuccessful();
        $this->assertSame(0, NotificationEvent::query()->count());
    }

    public function test_days_option_overrides_config_default(): void
    {
        // Config default is 90; --days=5 should sweep rows older than 5d
        // and spare anything newer.
        $user = $this->makeUser('override');
        $sixDaysOld = $this->makeEvent($user, now()->subDays(6));
        $fourDaysOld = $this->makeEvent($user, now()->subDays(4));

        $this->artisan('notifications:prune', ['--days' => 5])
            ->assertSuccessful();

        $this->assertNull(NotificationEvent::find($sixDaysOld->id));
        $this->assertNotNull(NotificationEvent::find($fourDaysOld->id));
    }

    public function test_zero_days_short_circuits_with_warning(): void
    {
        $user = $this->makeUser('disabled');
        $row = $this->makeEvent($user, now()->subDays(365));

        $this->artisan('notifications:prune', ['--days' => 0])
            ->expectsOutputToContain('Retention is 0 or negative')
            ->assertSuccessful();

        // Row must survive.
        $this->assertNotNull(NotificationEvent::find($row->id));
    }

    public function test_prunes_irrespective_of_read_or_dismissed_state(): void
    {
        // The retention contract treats read / dismissed / unread rows
        // identically — once a row is older than the cutoff, the rotation
        // sweeps it regardless of whether the user ever interacted.
        $user = $this->makeUser('states');
        $unread = $this->makeEvent($user, now()->subDays(100));
        $read = $this->makeEvent($user, now()->subDays(100), ['read_at' => now()->subDays(95)]);
        $dismissed = $this->makeEvent($user, now()->subDays(100), [
            'read_at' => now()->subDays(95),
            'dismissed_at' => now()->subDays(94),
        ]);

        $this->artisan('notifications:prune')->assertSuccessful();

        $this->assertNull(NotificationEvent::find($unread->id));
        $this->assertNull(NotificationEvent::find($read->id));
        $this->assertNull(NotificationEvent::find($dismissed->id));
    }

    public function test_handles_100_rows_in_chunks_without_memory_issues(): void
    {
        // R3 — chunkById(100). Seeding 250 stale rows + asserting all are
        // deleted exercises the chunk loop ≥ 2× plus the final partial.
        $user = $this->makeUser('bulk');
        foreach (range(1, 250) as $_) {
            $this->makeEvent($user, now()->subDays(120));
        }

        $this->artisan('notifications:prune')->assertSuccessful();

        $this->assertSame(0, NotificationEvent::query()->count());
    }

    public function test_prunes_each_tenant_independently_with_explicit_tenant_id_predicate(): void
    {
        // R30 — `notification_events` is tenant-aware; the command must
        // iterate DISTINCT tenant_ids and carry an explicit tenant_id
        // predicate on every DELETE. A single tenant's stale rows must
        // not be touched by another tenant's prune iteration.
        $aliceUser = $this->makeUser('alice');
        $bobUser = $this->makeUser('bob');

        $aliceStale = $this->makeEventForTenant('tenant-alice', $aliceUser, now()->subDays(120));
        $aliceFresh = $this->makeEventForTenant('tenant-alice', $aliceUser, now()->subDays(10));
        $bobStale = $this->makeEventForTenant('tenant-bob', $bobUser, now()->subDays(120));
        $bobFresh = $this->makeEventForTenant('tenant-bob', $bobUser, now()->subDays(10));

        $this->artisan('notifications:prune')->assertSuccessful();

        $this->assertNull(NotificationEvent::find($aliceStale->id), 'alice stale must be pruned');
        $this->assertNotNull(NotificationEvent::find($aliceFresh->id), 'alice fresh must survive');
        $this->assertNull(NotificationEvent::find($bobStale->id), 'bob stale must be pruned');
        $this->assertNotNull(NotificationEvent::find($bobFresh->id), 'bob fresh must survive');
    }

    public function test_tenant_option_restricts_prune_to_a_single_tenant(): void
    {
        // `--tenant=X` lets an operator prune a specific tenant ad hoc.
        // Other tenants' stale rows must NOT be touched by this call.
        $aliceUser = $this->makeUser('explicit-alice');
        $bobUser = $this->makeUser('explicit-bob');

        $aliceStale = $this->makeEventForTenant('tenant-alice-x', $aliceUser, now()->subDays(120));
        $bobStale = $this->makeEventForTenant('tenant-bob-x', $bobUser, now()->subDays(120));

        $this->artisan('notifications:prune', ['--tenant' => 'tenant-alice-x'])
            ->assertSuccessful();

        $this->assertNull(NotificationEvent::find($aliceStale->id));
        $this->assertNotNull(NotificationEvent::find($bobStale->id), 'tenant-bob-x must survive when only tenant-alice-x is targeted');
    }

    public function test_bootstrap_app_php_registers_the_scheduler_slot(): void
    {
        // R9 — bootstrap/app.php registration must match the documented
        // slot. Testbench doesn't execute the host's
        // `withSchedule(...)` closure, so we can't introspect
        // `app(Schedule::class)->events()` — assert against the
        // bootstrap source file directly. Without this gate, a future
        // refactor that drops the schedule line would ship silently.
        // Testbench's `base_path()` resolves to its own skeleton
        // bootstrap, not the host project's. Walk up to the real file
        // via __DIR__.
        $bootstrap = (string) file_get_contents(__DIR__.'/../../../bootstrap/app.php');

        $this->assertStringContainsString("\$schedule->command('notifications:prune')", $bootstrap);
        $this->assertStringContainsString("->dailyAt('04:10')", $bootstrap);
        $this->assertStringContainsString('->onOneServer()', $bootstrap);
        $this->assertStringContainsString('->withoutOverlapping()', $bootstrap);
    }

    private function makeUser(string $slug): User
    {
        return User::create([
            'name' => "prune-{$slug}",
            'email' => "{$slug}-".uniqid('', true).'@test.local',
            'password' => Hash::make('secret'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeEvent(User $user, \DateTimeInterface $createdAt, array $overrides = []): NotificationEvent
    {
        $row = new NotificationEvent();
        $row->forceFill(array_merge([
            'user_id' => $user->id,
            'event_type' => 'kb_doc_created',
            'payload' => ['marker' => uniqid('', true)],
            'channel_dispatch_log' => [],
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ], $overrides));
        $row->timestamps = false;
        $row->save();
        $row->timestamps = true;
        return $row;
    }

    /**
     * Same shape as `makeEvent`, but with an explicit `tenant_id` so the
     * cross-tenant tests can seed rows in tenants other than `default`
     * (the value `BelongsToTenant::creating()` stamps automatically).
     */
    private function makeEventForTenant(string $tenantId, User $user, \DateTimeInterface $createdAt): NotificationEvent
    {
        $row = new NotificationEvent();
        $row->forceFill([
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'event_type' => 'kb_doc_created',
            'payload' => ['marker' => uniqid('', true)],
            'channel_dispatch_log' => [],
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
        $row->timestamps = false;
        $row->save();
        $row->timestamps = true;
        return $row;
    }
}
