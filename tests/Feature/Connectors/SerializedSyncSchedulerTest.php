<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Connectors\Scheduling\SerializedSyncScheduler;
use App\Connectors\SerializedConnectorSyncJob;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Padosoft\AskMyDocsConnectorBase\ConnectorSyncJob;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Tests\TestCase;

/**
 * The host scheduler sweep dispatches the SERIALIZED job (per-mailbox re-queue),
 * not the bare vendor job, for every due ACTIVE installation — preserving the
 * vendor cadence/isDue semantics.
 */
final class SerializedSyncSchedulerTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatches_the_serialized_job_for_due_active_imap_installations_only(): void
    {
        Queue::fake();
        config()->set('connectors.default_sync_cadence_minutes', 15);
        // Off by default in phpunit.xml (R43) — enable so the IMAP routing engages.
        config()->set('connectors.imap.serialize_connections', true);

        // Due: never synced.
        $due = ConnectorInstallation::create([
            'tenant_id' => 'default', 'connector_name' => 'imap', 'label' => 'due',
            'config_json' => ['connection' => ['host' => 'imap.x.test', 'username' => 'u@x.test']],
            'status' => ConnectorInstallation::STATUS_ACTIVE, 'last_sync_at' => null, 'created_by' => 1,
        ]);
        // Not due: synced 1 minute ago (< 15 min cadence).
        ConnectorInstallation::create([
            'tenant_id' => 'default', 'connector_name' => 'imap', 'label' => 'fresh',
            'config_json' => ['connection' => ['host' => 'imap.x.test', 'username' => 'v@x.test']],
            'status' => ConnectorInstallation::STATUS_ACTIVE, 'last_sync_at' => Carbon::now()->subMinute(), 'created_by' => 1,
        ]);
        // Excluded: DISABLED (not ACTIVE).
        ConnectorInstallation::create([
            'tenant_id' => 'default', 'connector_name' => 'imap', 'label' => 'off',
            'status' => ConnectorInstallation::STATUS_DISABLED, 'last_sync_at' => null, 'created_by' => 1,
        ]);

        $dispatched = (new SerializedSyncScheduler)->dispatchDueSyncs();

        $this->assertSame(1, $dispatched);
        Queue::assertPushed(SerializedConnectorSyncJob::class, 1);
        Queue::assertPushed(
            SerializedConnectorSyncJob::class,
            fn (SerializedConnectorSyncJob $job) => $job->installationId === $due->id && $job->tenantId === 'default',
        );
        // Never the bare vendor job (QueueFake keys by exact class).
        Queue::assertNotPushed(ConnectorSyncJob::class);
    }

    public function test_dispatches_the_vendor_job_for_a_non_imap_connector_even_with_serialization_on(): void
    {
        Queue::fake();
        config()->set('connectors.default_sync_cadence_minutes', 15);
        // Serialization ON, yet a non-IMAP connector must still keep the vendor job —
        // it shares no per-account connection limit. Proves the routing is IMAP-only,
        // not a blanket "wrap everything once enabled".
        config()->set('connectors.imap.serialize_connections', true);

        $drive = ConnectorInstallation::create([
            'tenant_id' => 'default', 'connector_name' => 'google-drive', 'label' => 'drive',
            'status' => ConnectorInstallation::STATUS_ACTIVE, 'last_sync_at' => null, 'created_by' => 1,
        ]);

        $dispatched = (new SerializedSyncScheduler)->dispatchDueSyncs();

        $this->assertSame(1, $dispatched);
        Queue::assertNotPushed(SerializedConnectorSyncJob::class);
        Queue::assertPushed(
            ConnectorSyncJob::class,
            fn (ConnectorSyncJob $job) => $job->installationId === $drive->id && $job->tenantId === 'default',
        );
    }
}
