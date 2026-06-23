<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Models\ConnectorSyncRun;
use App\Models\User;
use App\Services\Admin\IngestionObservabilityService;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Padosoft\AskMyDocsConnectorBase\ConnectorSyncJob;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Tests\TestCase;

/**
 * v8.21 (Ciclo 2) — connector sync-run observability: the host-side recorder
 * (queue-lifecycle driven), the read service (tenant-scoped), and the HTTP +
 * CLI surfaces.
 */
final class IngestionObservabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        // Spatie's permission cache can survive the DB rollback under Testbench,
        // making role checks order-dependent — flush it after seeding.
        Cache::flush();
    }

    public function test_recorder_records_a_sync_run_off_the_queue_lifecycle(): void
    {
        // A PENDING installation makes ConnectorSyncJob::handle no-op (status !=
        // ACTIVE) → no network — but the queue JobProcessing/JobProcessed events
        // still fire, so the recorder must open + close a run row.
        $installation = ConnectorInstallation::create([
            'tenant_id' => 'default',
            'connector_name' => 'google-drive',
            'label' => 'support',
            'status' => ConnectorInstallation::STATUS_PENDING,
            'created_by' => 1,
        ]);

        // Sync queue connection → the job runs inline and fires the events.
        config(['queue.default' => 'sync']);
        ConnectorSyncJob::dispatch($installation->id, 'default');

        $runs = ConnectorSyncRun::query()
            ->where('connector_installation_id', $installation->id)
            ->get();

        // Exactly one — guards against the recorder being subscribed twice
        // (which would create duplicate rows and leave one stuck in `running`).
        $this->assertCount(1, $runs, 'Exactly one connector_sync_runs row must be recorded.');
        $run = $runs->first();
        $this->assertSame(ConnectorSyncRun::STATUS_SUCCESS, $run->status);
        $this->assertSame('google-drive', $run->connector_name);
        $this->assertSame('support', $run->label);
        $this->assertSame(0, $run->items_discovered);
        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->finished_at);
    }

    public function test_service_recent_runs_is_tenant_scoped(): void
    {
        $this->makeRun('default', 1, 'support');
        $this->makeRun('tenant-foreign', 2, 'secret');

        $tenants = app(TenantContext::class);
        $tenants->set('default');
        $service = app(IngestionObservabilityService::class);

        $runs = $service->recentRuns();
        $this->assertCount(1, $runs);
        $this->assertSame('support', $runs[0]['label']);
    }

    public function test_queue_endpoint_lists_the_three_logical_queues(): void
    {
        $admin = $this->superAdmin();

        $resp = $this->actingAs($admin)->getJson('/api/admin/ingestion/queue');
        $resp->assertOk();
        $roles = collect($resp->json('data'))->pluck('role')->all();
        // All THREE logical roles are always present (even if some share a
        // physical queue), per the documented contract.
        $this->assertContains('connector-sync', $roles);
        $this->assertContains('kb-ingest', $roles);
        $this->assertContains('default', $roles);
        $this->assertCount(3, $resp->json('data'));
    }

    public function test_sync_runs_endpoint_returns_runs_for_an_installation(): void
    {
        $admin = $this->superAdmin();
        $installation = ConnectorInstallation::create([
            'tenant_id' => 'default', 'connector_name' => 'google-drive',
            'label' => 'support', 'status' => ConnectorInstallation::STATUS_ACTIVE,
            'created_by' => $admin->id,
        ]);
        $this->makeRun('default', $installation->id, 'support');

        $resp = $this->actingAs($admin)
            ->getJson("/api/admin/connectors/{$installation->id}/sync-runs");
        $resp->assertOk();
        $this->assertCount(1, $resp->json('data'));
        $this->assertSame('success', $resp->json('data.0.status'));
    }

    public function test_sync_runs_endpoint_404s_for_a_cross_tenant_installation(): void
    {
        $admin = $this->superAdmin();
        $foreign = ConnectorInstallation::create([
            'tenant_id' => 'tenant-foreign', 'connector_name' => 'google-drive',
            'label' => 'support', 'status' => ConnectorInstallation::STATUS_ACTIVE,
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->getJson("/api/admin/connectors/{$foreign->id}/sync-runs")
            ->assertStatus(404);
    }

    public function test_ingestion_endpoints_reject_non_super_admin_and_guest(): void
    {
        $this->getJson('/api/admin/ingestion/queue')->assertStatus(401);
        $this->actingAs($this->regularAdmin())
            ->getJson('/api/admin/ingestion/queue')->assertStatus(403);
    }

    public function test_ingestion_status_command_renders_runs(): void
    {
        $this->makeRun('default', 1, 'support');

        $this->artisan('ingestion:status', ['--tenant' => 'default'])
            ->expectsOutputToContain('support')
            ->assertExitCode(0);
    }

    private function makeRun(string $tenant, int $installationId, string $label): void
    {
        ConnectorSyncRun::create([
            'tenant_id' => $tenant,
            'connector_installation_id' => $installationId,
            'connector_name' => 'google-drive',
            'label' => $label,
            'queue' => 'connectors',
            'status' => ConnectorSyncRun::STATUS_SUCCESS,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'duration_ms' => 1200,
            'items_discovered' => 3,
            'items_failed' => 0,
        ]);
    }

    private function superAdmin(): User
    {
        $user = User::create(['name' => 'Super', 'email' => 'super-'.uniqid().'@demo.local', 'password' => Hash::make('secret123')]);
        $user->assignRole('super-admin');

        return $user;
    }

    private function regularAdmin(): User
    {
        $user = User::create(['name' => 'Admin', 'email' => 'admin-'.uniqid().'@demo.local', 'password' => Hash::make('secret123')]);
        $user->assignRole('admin');

        return $user;
    }
}
