<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Connectors\Imap\MailboxLockKey;
use App\Connectors\SerializedConnectorSyncJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use ReflectionProperty;
use Tests\TestCase;

/**
 * The host sync job that re-queues per mailbox: an IMAP installation gets a
 * WithoutOverlapping middleware keyed by its mailbox; other connectors (or an IMAP
 * row with no resolvable account) get none. retryUntil bounds re-queues by wall-clock.
 */
final class SerializedConnectorSyncJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_imap_job_carries_a_without_overlapping_middleware_keyed_by_mailbox(): void
    {
        $installation = ConnectorInstallation::create([
            'tenant_id' => 'default',
            'connector_name' => 'imap',
            'label' => 'prometeo-1',
            'config_json' => ['connection' => ['host' => 'imap.example.test', 'port' => 993, 'username' => 'u@example.test']],
            'status' => ConnectorInstallation::STATUS_ACTIVE,
            'created_by' => 1,
        ]);

        $middleware = (new SerializedConnectorSyncJob($installation->id, 'default'))->middleware();

        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[0]);

        // The lock is keyed by the (cross-tenant) mailbox identity.
        $keyProp = new ReflectionProperty(WithoutOverlapping::class, 'key');
        $keyProp->setAccessible(true);
        $this->assertSame(
            MailboxLockKey::forInstallation($installation),
            $keyProp->getValue($middleware[0]),
        );
    }

    public function test_two_accounts_on_the_same_mailbox_share_the_overlap_key_across_tenants(): void
    {
        $connection = ['connection' => ['host' => 'imap.gmail.com', 'port' => 993, 'username' => 'shared@acme.test']];
        $a = ConnectorInstallation::create([
            'tenant_id' => 'tenant-a', 'connector_name' => 'imap', 'label' => 'a',
            'config_json' => $connection, 'status' => ConnectorInstallation::STATUS_ACTIVE, 'created_by' => 1,
        ]);
        $b = ConnectorInstallation::create([
            'tenant_id' => 'tenant-b', 'connector_name' => 'imap', 'label' => 'b',
            'config_json' => $connection, 'status' => ConnectorInstallation::STATUS_ACTIVE, 'created_by' => 1,
        ]);

        $keyProp = new ReflectionProperty(WithoutOverlapping::class, 'key');
        $keyProp->setAccessible(true);

        $keyA = $keyProp->getValue((new SerializedConnectorSyncJob($a->id, 'tenant-a'))->middleware()[0]);
        $keyB = $keyProp->getValue((new SerializedConnectorSyncJob($b->id, 'tenant-b'))->middleware()[0]);

        // Different tenants, same physical mailbox → ONE overlap lock → serialized.
        $this->assertSame($keyA, $keyB);
    }

    public function test_non_imap_connector_has_no_overlap_middleware(): void
    {
        $installation = ConnectorInstallation::create([
            'tenant_id' => 'default',
            'connector_name' => 'google-drive',
            'label' => 'default',
            'status' => ConnectorInstallation::STATUS_ACTIVE,
            'created_by' => 1,
        ]);

        $this->assertSame([], (new SerializedConnectorSyncJob($installation->id, 'default'))->middleware());
    }

    public function test_imap_job_without_a_resolvable_account_has_no_overlap_middleware(): void
    {
        // R43 — the OTHER branch: an IMAP row with no host/username can't form a key.
        $installation = ConnectorInstallation::create([
            'tenant_id' => 'default',
            'connector_name' => 'imap',
            'label' => 'broken',
            'config_json' => ['connection' => []],
            'status' => ConnectorInstallation::STATUS_ACTIVE,
            'created_by' => 1,
        ]);

        $this->assertSame([], (new SerializedConnectorSyncJob($installation->id, 'default'))->middleware());
    }

    public function test_retry_until_is_a_future_wall_clock_window(): void
    {
        config()->set('connectors.imap.mailbox_lock.requeue_window_minutes', 30);

        $now = now();
        $until = (new SerializedConnectorSyncJob(1, 'default'))->retryUntil();

        $this->assertGreaterThan($now->copy()->addMinutes(29)->getTimestamp(), $until->getTimestamp());
        $this->assertLessThanOrEqual($now->copy()->addMinutes(31)->getTimestamp(), $until->getTimestamp());
    }
}
