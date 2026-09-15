<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Connectors\Imap\MailboxLockKey;
use App\Models\ImapBackfill;
use App\Models\ImapBackfillWindow;
use Illuminate\Cache\RedisStore;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class DiagnoseImapCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reads_both_lock_ttls_using_the_lock_connection_and_preserves_data(): void
    {
        Queue::fake();
        $installation = $this->installation();
        $backfill = $this->backfill($installation);
        $window = ImapBackfillWindow::query()->create([
            'tenant_id' => 'prima-demo',
            'imap_backfill_id' => $backfill->id,
            'connector_installation_id' => $installation->id,
            'mailbox' => 'INBOX',
            'window_start' => '2026-01-01',
            'window_end' => '2026-02-01',
            'status' => ImapBackfillWindow::STATUS_QUEUED,
        ]);
        $before = [
            $installation->fresh()->getRawOriginal(),
            $backfill->fresh()->getRawOriginal(),
            $window->fresh()->getRawOriginal(),
        ];
        $this->expectTtlReads($installation, 623, -2);
        config()->set([
            'queue.default' => 'sqs',
            'queue.connections.sqs.driver' => 'sqs',
            'connectors.imap.backfill.queue' => 'connectors',
        ]);

        $this->assertSame(0, Artisan::call('connectors:imap:diagnose', [
            'tenant' => 'prima-demo',
            'backfill' => $backfill->id,
        ]));

        $output = Artisan::output();
        $this->assertStringContainsString('backfill_status: discovering', $output);
        $this->assertStringContainsString('backfill_windows: 1', $output);
        $this->assertStringContainsString('backfill_heartbeat_at: '.$backfill->heartbeat_at->toAtomString(), $output);
        $this->assertStringContainsString('queue_connection: sqs', $output);
        $this->assertStringContainsString('queue_driver: sqs', $output);
        $this->assertStringContainsString('cache_driver: redis', $output);
        $this->assertStringContainsString('mailbox_lock: present (TTL: 623 seconds)', $output);
        $this->assertStringContainsString('queue_overlap_lock: absent (TTL: -2)', $output);
        $this->assertStringNotContainsString('private@example.test', $output);
        $this->assertStringNotContainsString('secret-do-not-display', $output);
        $this->assertSame($before, [
            $installation->fresh()->getRawOriginal(),
            $backfill->fresh()->getRawOriginal(),
            $window->fresh()->getRawOriginal(),
        ]);
        Queue::assertNothingPushed();
    }

    public function test_it_can_inspect_an_installation_without_a_backfill_and_handles_all_ttl_states(): void
    {
        $installation = $this->installation();
        $this->expectTtlReads($installation, -1, 0);

        $this->artisan('connectors:imap:diagnose', [
            'tenant' => 'prima-demo',
            '--installation' => $installation->id,
        ])
            ->expectsOutputToContain('backfill_id: none')
            ->expectsOutputToContain('mailbox_lock: present without expiry (TTL: -1)')
            ->expectsOutputToContain('queue_overlap_lock: present (TTL: 0 seconds)')
            ->assertSuccessful();
    }

    public function test_installation_selection_reports_only_its_latest_tenant_scoped_backfill(): void
    {
        $installation = $this->installation();
        $this->backfill($installation);
        $latest = $this->backfill($installation);
        $this->backfill($this->installation('other-tenant'));
        $this->expectTtlReads($installation, -2, -2);

        $this->artisan('connectors:imap:diagnose', [
            'tenant' => 'prima-demo',
            '--installation' => $installation->id,
        ])
            ->expectsOutputToContain('backfill_selection: latest for installation')
            ->expectsOutputToContain('backfill_id: '.$latest->id)
            ->assertSuccessful();
    }

    public function test_omitting_the_id_inspects_the_only_imap_installation_in_the_requested_tenant(): void
    {
        $installation = $this->installation();
        $this->installation('other-tenant');
        $notImap = $this->installation('prima-demo', 'not-imap');
        $notImap->update(['connector_name' => 'notion']);
        $this->expectTtlReads($installation, 123, -2);

        $this->artisan('connectors:imap:diagnose', ['tenant' => 'prima-demo'])
            ->expectsOutputToContain("Automatically selected the only IMAP installation in 'prima-demo': {$installation->id}.")
            ->expectsOutputToContain('installation_id: '.$installation->id)
            ->expectsOutputToContain('backfill_id: none')
            ->expectsOutputToContain('mailbox_lock: present (TTL: 123 seconds)')
            ->assertSuccessful();
    }

    public function test_multiple_installations_are_listed_without_choosing_or_reading_any_locks(): void
    {
        $first = $this->installation('prima-demo', 'support');
        $second = $this->installation('prima-demo', 'sales');
        $this->installation('other-tenant', 'other-private-account');
        Cache::shouldReceive('getStore')->never();

        $this->assertSame(1, Artisan::call('connectors:imap:diagnose', ['tenant' => 'prima-demo']));
        $output = Artisan::output();
        $this->assertStringContainsString('support', $output);
        $this->assertStringContainsString('sales', $output);
        $this->assertStringContainsString("php artisan connectors:imap:diagnose prima-demo --installation={$first->id}", $output);
        $this->assertStringContainsString("php artisan connectors:imap:diagnose prima-demo --installation={$second->id}", $output);
        $this->assertStringNotContainsString('other-private-account', $output);
        $this->assertStringNotContainsString('mailbox_lock:', $output);
        $this->assertStringNotContainsString('private@example.test', $output);
        $this->assertStringNotContainsString('secret-do-not-display', $output);
    }

    public function test_a_missing_backfill_lists_available_ids_without_silently_switching_target(): void
    {
        $installation = $this->installation();
        Cache::shouldReceive('getStore')->never();

        $this->artisan('connectors:imap:diagnose', ['tenant' => 'prima-demo', 'backfill' => 999])
            ->expectsOutputToContain("IMAP backfill 999 does not exist in tenant 'prima-demo'.")
            ->expectsOutputToContain("php artisan connectors:imap:diagnose prima-demo --installation={$installation->id}")
            ->assertFailed();
    }

    public function test_no_imap_installations_reports_no_target_without_inspecting_other_tenants(): void
    {
        $this->installation('other-tenant');
        $notImap = $this->installation();
        $notImap->update(['connector_name' => 'notion']);
        Cache::shouldReceive('getStore')->never();

        $this->artisan('connectors:imap:diagnose', ['tenant' => 'prima-demo'])
            ->expectsOutputToContain("No IMAP installations exist in tenant 'prima-demo'; lock state was not inspected.")
            ->assertFailed();
    }

    public function test_cross_tenant_targets_are_rejected_before_reading_locks(): void
    {
        $installation = $this->installation('other-tenant');
        $backfill = $this->backfill($installation);
        Cache::shouldReceive('getStore')->never();

        foreach ([['backfill' => $backfill->id], ['--installation' => $installation->id]] as $target) {
            $this->assertSame(1, Artisan::call('connectors:imap:diagnose', ['tenant' => 'prima-demo'] + $target));
            $this->assertStringContainsString("does not exist in tenant 'prima-demo'", Artisan::output());
            $this->assertStringNotContainsString('mailbox_lock_key:', Artisan::output());
        }
    }

    public function test_an_unknown_backfill_and_a_non_imap_installation_are_rejected(): void
    {
        $installation = $this->installation();
        $installation->update(['connector_name' => 'notion']);
        Cache::shouldReceive('getStore')->never();

        $this->artisan('connectors:imap:diagnose', [
            'tenant' => 'prima-demo',
            'backfill' => 999,
        ])
            ->expectsOutputToContain('If it was reset, use --installation=ID with the IMAP installation id.')
            ->assertFailed();

        $this->artisan('connectors:imap:diagnose', [
            'tenant' => 'prima-demo',
            '--installation' => $installation->id,
        ])->assertFailed();
    }

    #[DataProvider('invalidTargets')]
    public function test_invalid_targets_are_rejected(array $arguments): void
    {
        Cache::shouldReceive('getStore')->never();

        $this->artisan('connectors:imap:diagnose', $arguments)->assertFailed();
    }

    public static function invalidTargets(): array
    {
        return [
            'invalid tenant' => [['tenant' => '../prima-demo', 'backfill' => 6]],
            'ambiguous target' => [['tenant' => 'prima-demo', 'backfill' => 6, '--installation' => 2]],
            'invalid backfill' => [['tenant' => 'prima-demo', 'backfill' => 'abc']],
            'invalid installation' => [['tenant' => 'prima-demo', '--installation' => 0]],
        ];
    }

    public function test_a_non_redis_store_reports_unknown_instead_of_claiming_the_locks_are_free(): void
    {
        $installation = $this->installation();
        config()->set('cache.default', 'array');

        $this->artisan('connectors:imap:diagnose', [
            'tenant' => 'prima-demo',
            '--installation' => $installation->id,
        ])
            ->expectsOutputToContain('Lock TTL inspection requires a Redis cache store; lock state is unknown.')
            ->assertFailed();
    }

    public function test_redis_failures_do_not_claim_absent_locks_or_disclose_connection_secrets(): void
    {
        $installation = $this->installation();
        $redis = $this->redisStore();
        $redis->shouldReceive('ttl')->once()
            ->andThrow(new RuntimeException('redis://user:secret-do-not-display@redis.example.test'));

        $this->assertSame(1, Artisan::call('connectors:imap:diagnose', [
            'tenant' => 'prima-demo',
            '--installation' => $installation->id,
        ]));

        $this->assertStringContainsString('Unable to read Redis lock TTLs (RuntimeException); lock state is unknown.', Artisan::output());
        $this->assertStringNotContainsString('secret-do-not-display', Artisan::output());
        $this->assertStringNotContainsString('absent', Artisan::output());
    }

    public function test_missing_mailbox_identity_does_not_probe_a_different_lock(): void
    {
        $installation = $this->installation();
        $installation->update(['config_json' => []]);
        Cache::shouldReceive('getStore')->never();

        $this->artisan('connectors:imap:diagnose', [
            'tenant' => 'prima-demo',
            '--installation' => $installation->id,
        ])
            ->expectsOutputToContain('No valid host/username mailbox lock identity; lock state is unknown.')
            ->assertFailed();
    }

    public function test_an_unexpected_redis_reply_is_not_mistaken_for_an_expiring_lock(): void
    {
        $installation = $this->installation();
        $redis = $this->redisStore();
        $redis->shouldReceive('ttl')->once()->andReturn(false);

        $this->artisan('connectors:imap:diagnose', [
            'tenant' => 'prima-demo',
            '--installation' => $installation->id,
        ])
            ->expectsOutputToContain('lock state is unknown.')
            ->assertFailed();
    }

    private function expectTtlReads(ConnectorInstallation $installation, int $mailboxTtl, int $queueTtl): void
    {
        $mailboxKey = MailboxLockKey::forInstallation($installation);
        $queueKey = (new WithoutOverlapping($mailboxKey))->shared()->getLockKey($this);
        $redis = $this->redisStore();
        // Strict mocks permit ONLY these TTL reads: SET/DEL/GET/lock acquisition fail.
        $redis->shouldReceive('ttl')->once()->with('diagnostic-cache:'.$mailboxKey)->andReturn($mailboxTtl);
        $redis->shouldReceive('ttl')->once()->with('diagnostic-cache:'.$queueKey)->andReturn($queueTtl);
    }

    private function redisStore(): Connection
    {
        $redis = Mockery::mock(Connection::class);
        $factory = Mockery::mock(Factory::class);
        $factory->shouldReceive('connection')->once()->with('diagnostic-locks')->andReturn($redis);
        $store = new RedisStore($factory, 'diagnostic-cache:', 'diagnostic-data');
        $store->setLockConnection('diagnostic-locks');
        Cache::shouldReceive('getStore')->once()->andReturn($store);
        config()->set([
            'cache.default' => 'diagnostic',
            'cache.stores.diagnostic.driver' => 'redis',
        ]);

        return $redis;
    }

    private function installation(string $tenantId = 'prima-demo', string $label = 'default'): ConnectorInstallation
    {
        return ConnectorInstallation::query()->create([
            'tenant_id' => $tenantId,
            'connector_name' => 'imap',
            'label' => $label,
            'config_json' => [
                'connection' => ['host' => 'imap.example.test', 'port' => 993, 'username' => 'private@example.test'],
                'password' => 'secret-do-not-display',
            ],
            'status' => ConnectorInstallation::STATUS_ACTIVE,
            'created_by' => 1,
        ]);
    }

    private function backfill(ConnectorInstallation $installation): ImapBackfill
    {
        return ImapBackfill::query()->create([
            'tenant_id' => $installation->tenant_id,
            'connector_installation_id' => $installation->id,
            'status' => ImapBackfill::STATUS_DISCOVERING,
            'batch_size' => 10,
            'cutoff_at' => now(),
            'heartbeat_at' => now(),
        ]);
    }
}
