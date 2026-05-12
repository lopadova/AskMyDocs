<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Connectors\Scheduling\SyncScheduler;
use App\Jobs\ConnectorSyncJob;
use App\Models\ConnectorInstallation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * v4.5/W1 — SyncScheduler cadence + skip behaviour.
 */
final class SyncSchedulerTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatches_job_for_installation_never_synced(): void
    {
        Queue::fake();

        $installation = $this->makeInstallation(['last_sync_at' => null]);

        $dispatched = (new SyncScheduler)->dispatchDueSyncs();

        $this->assertSame(1, $dispatched);
        Queue::assertPushed(ConnectorSyncJob::class, function (ConnectorSyncJob $job) use ($installation) {
            return $job->installationId === $installation->id;
        });
    }

    public function test_skips_installation_within_cadence_window(): void
    {
        Queue::fake();
        config()->set('connectors.default_sync_cadence_minutes', 15);

        $this->makeInstallation(['last_sync_at' => Carbon::now()->subMinutes(5)]);

        $dispatched = (new SyncScheduler)->dispatchDueSyncs();

        $this->assertSame(0, $dispatched);
        Queue::assertNotPushed(ConnectorSyncJob::class);
    }

    public function test_dispatches_installation_past_cadence_window(): void
    {
        Queue::fake();
        config()->set('connectors.default_sync_cadence_minutes', 15);

        $this->makeInstallation(['last_sync_at' => Carbon::now()->subMinutes(20)]);

        $dispatched = (new SyncScheduler)->dispatchDueSyncs();

        $this->assertSame(1, $dispatched);
        Queue::assertPushed(ConnectorSyncJob::class);
    }

    public function test_per_connector_cadence_overrides_default(): void
    {
        Queue::fake();
        config()->set('connectors.default_sync_cadence_minutes', 60);
        config()->set('connectors.per_connector_cadence', ['google-drive' => 5]);

        $this->makeInstallation(['last_sync_at' => Carbon::now()->subMinutes(7)]);

        $dispatched = (new SyncScheduler)->dispatchDueSyncs();
        $this->assertSame(1, $dispatched, 'Installation should be due under 5-min override even though default is 60.');
    }

    public function test_skips_disabled_installations(): void
    {
        Queue::fake();

        $this->makeInstallation([
            'last_sync_at' => Carbon::now()->subHour(),
            'status' => ConnectorInstallation::STATUS_DISABLED,
        ]);

        $dispatched = (new SyncScheduler)->dispatchDueSyncs();
        $this->assertSame(0, $dispatched);
    }

    public function test_skips_errored_installations(): void
    {
        Queue::fake();

        $this->makeInstallation([
            'last_sync_at' => Carbon::now()->subHour(),
            'status' => ConnectorInstallation::STATUS_ERRORED,
        ]);

        $dispatched = (new SyncScheduler)->dispatchDueSyncs();
        $this->assertSame(0, $dispatched);
    }

    /**
     * iter2 finding #3 — pending installations are mid-OAuth-flow
     * (no credentials yet). Dispatching a sync against them would
     * race the OAuth callback and flip the row to ERRORED via the
     * job's missing-credentials failure path.
     */
    public function test_skips_pending_installations(): void
    {
        Queue::fake();

        $this->makeInstallation([
            'last_sync_at' => null,
            'status' => ConnectorInstallation::STATUS_PENDING,
        ]);

        $dispatched = (new SyncScheduler)->dispatchDueSyncs();
        $this->assertSame(0, $dispatched);
        Queue::assertNotPushed(ConnectorSyncJob::class);
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function makeInstallation(array $overrides = []): ConnectorInstallation
    {
        $user = User::create([
            'name' => 'User',
            'email' => 'u-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);

        return ConnectorInstallation::create(array_merge([
            'tenant_id' => 'default',
            'connector_name' => 'google-drive',
            'status' => ConnectorInstallation::STATUS_ACTIVE,
            'created_by' => $user->id,
        ], $overrides));
    }
}
