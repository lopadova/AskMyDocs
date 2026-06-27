<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Connectors\Imap\MailboxLockKey;
use App\Connectors\SerializedConnectorSyncJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Queue;
use Padosoft\AskMyDocsConnectorBase\ConnectorSyncJob;
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

    protected function setUp(): void
    {
        parent::setUp();

        // middleware() + serializes() now share dispatchFor's gating: an IMAP account
        // with the flag on, NOT fake-ping, AND a lock-capable cache store. phpunit.xml
        // disables serialization by default (R43); these tests exercise the serialized
        // path so enable it. The array test store IS lock-capable; fake_imap_ping is
        // off by default. Individual no-op tests below flip one condition each.
        config()->set('connectors.imap.serialize_connections', true);
    }

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

    public function test_imap_job_has_no_overlap_middleware_when_serialization_is_disabled(): void
    {
        // R43 OFF path — the flag off makes the middleware a no-op even for IMAP, so
        // a SerializedConnectorSyncJob enqueued under a now-disabled flag can't crash.
        config()->set('connectors.imap.serialize_connections', false);

        $installation = ConnectorInstallation::create([
            'tenant_id' => 'default', 'connector_name' => 'imap', 'label' => 'imap',
            'config_json' => ['connection' => ['host' => 'imap.example.test', 'username' => 'u@example.test']],
            'status' => ConnectorInstallation::STATUS_ACTIVE, 'created_by' => 1,
        ]);

        $this->assertSame([], (new SerializedConnectorSyncJob($installation->id, 'default'))->middleware());
    }

    public function test_imap_job_has_no_overlap_middleware_when_fake_imap_ping_is_on(): void
    {
        // fake_imap_ping seam (offline/E2E): no real server to protect and the seam's
        // cache may not host locks — Layer 1 skips, so Layer 2 must too.
        config()->set('connectors.fake_imap_ping', true);

        $installation = ConnectorInstallation::create([
            'tenant_id' => 'default', 'connector_name' => 'imap', 'label' => 'imap',
            'config_json' => ['connection' => ['host' => 'imap.example.test', 'username' => 'u@example.test']],
            'status' => ConnectorInstallation::STATUS_ACTIVE, 'created_by' => 1,
        ]);

        $this->assertSame([], (new SerializedConnectorSyncJob($installation->id, 'default'))->middleware());
    }

    public function test_imap_job_has_no_overlap_middleware_when_the_cache_store_cannot_lock(): void
    {
        // No lock-capable store (here: an unresolvable default that makes Cache::store()
        // throw → the graceful-degrade catch). WithoutOverlapping would throw on
        // Cache::lock(); the gate degrades to a no-op instead of crashing the worker.
        config()->set('cache.default', 'no-such-store-'.uniqid());

        $installation = ConnectorInstallation::create([
            'tenant_id' => 'default', 'connector_name' => 'imap', 'label' => 'imap',
            'config_json' => ['connection' => ['host' => 'imap.example.test', 'username' => 'u@example.test']],
            'status' => ConnectorInstallation::STATUS_ACTIVE, 'created_by' => 1,
        ]);

        $this->assertSame([], (new SerializedConnectorSyncJob($installation->id, 'default'))->middleware());
    }

    public function test_dispatch_for_routes_to_the_vendor_job_when_serialization_cannot_run(): void
    {
        // dispatchFor must degrade to the vendor ConnectorSyncJob (never enqueue a
        // serialized job that would later crash on Cache::lock()) when the IMAP is
        // faked — mirroring the middleware no-op above at dispatch time.
        Queue::fake();
        config()->set('connectors.fake_imap_ping', true);

        $installation = ConnectorInstallation::create([
            'tenant_id' => 'default', 'connector_name' => 'imap', 'label' => 'imap',
            'config_json' => ['connection' => ['host' => 'imap.example.test', 'username' => 'u@example.test']],
            'status' => ConnectorInstallation::STATUS_ACTIVE, 'created_by' => 1,
        ]);

        SerializedConnectorSyncJob::dispatchFor($installation);

        Queue::assertPushed(ConnectorSyncJob::class, 1);
        Queue::assertNotPushed(SerializedConnectorSyncJob::class);
    }

    public function test_dispatch_for_routes_an_unkeyable_imap_install_to_the_vendor_job(): void
    {
        // An IMAP row with no resolvable mailbox key gets no WithoutOverlapping either
        // way, so the serialized envelope (tries=0 + retryUntil) buys nothing — route
        // it to the vendor job so it keeps the standard retry semantics.
        Queue::fake();

        $installation = ConnectorInstallation::create([
            'tenant_id' => 'default', 'connector_name' => 'imap', 'label' => 'broken',
            'config_json' => ['connection' => []],
            'status' => ConnectorInstallation::STATUS_ACTIVE, 'created_by' => 1,
        ]);

        SerializedConnectorSyncJob::dispatchFor($installation);

        Queue::assertPushed(ConnectorSyncJob::class, 1);
        Queue::assertNotPushed(SerializedConnectorSyncJob::class);
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
