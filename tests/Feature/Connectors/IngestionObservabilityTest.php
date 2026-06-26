<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Connectors\SerializedConnectorSyncJob;
use App\Models\ConnectorSyncRun;
use App\Models\User;
use App\Services\Admin\IngestionObservabilityService;
use App\Support\TenantContext;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Padosoft\AskMyDocsConnectorBase\ConnectorRegistry;
use Padosoft\AskMyDocsConnectorBase\ConnectorSyncJob;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientFactoryInterface;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientInterface;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapMessage;
use Padosoft\AskMyDocsConnectorImap\Imap\MailboxState;
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

    public function test_recorder_records_a_run_for_the_serialized_subclass_job(): void
    {
        // Layer 2 dispatches SerializedConnectorSyncJob (extends ConnectorSyncJob).
        // The recorder's resolveSyncJob must accept the subclass — else /sync-runs
        // would silently stop logging the moment the host scheduler takes over.
        config(['cache.default' => 'array']); // WithoutOverlapping lock store (in-process)
        $installation = ConnectorInstallation::create([
            'tenant_id' => 'default',
            'connector_name' => 'imap',
            'label' => 'prometeo-1',
            'config_json' => ['connection' => ['host' => 'imap.x.test', 'username' => 'u@x.test']],
            // PENDING → handle() no-ops (no network); the queue events still fire.
            'status' => ConnectorInstallation::STATUS_PENDING,
            'created_by' => 1,
        ]);

        config(['queue.default' => 'sync']);
        SerializedConnectorSyncJob::dispatch($installation->id, 'default');

        $run = ConnectorSyncRun::query()
            ->where('connector_installation_id', $installation->id)
            ->firstOrFail();
        $this->assertSame(ConnectorSyncRun::STATUS_SUCCESS, $run->status);
        $this->assertSame('imap', $run->connector_name);
        $this->assertSame('prometeo-1', $run->label);
    }

    public function test_recorder_records_a_partial_run_when_install_has_partial_errors(): void
    {
        // A PENDING install pre-seeded with partial_errors: handle() no-ops, but
        // onProcessed reads error_json.partial_errors → status=partial.
        $installation = ConnectorInstallation::create([
            'tenant_id' => 'default',
            'connector_name' => 'google-drive',
            'label' => 'support',
            'status' => ConnectorInstallation::STATUS_PENDING,
            'error_json' => ['partial_errors' => ['doc-1 failed', 'doc-2 failed']],
            'created_by' => 1,
        ]);

        config(['queue.default' => 'sync']);
        ConnectorSyncJob::dispatch($installation->id, 'default');

        $run = ConnectorSyncRun::query()
            ->where('connector_installation_id', $installation->id)->firstOrFail();
        $this->assertSame(ConnectorSyncRun::STATUS_PARTIAL, $run->status);
        $this->assertSame(2, $run->items_failed);
        $this->assertSame(['doc-1 failed', 'doc-2 failed'], $run->error_json['partial_errors']);
    }

    public function test_recorder_records_a_failed_run_when_the_job_throws(): void
    {
        // Fake IMAP client throws on every sync op (no network) → syncIncremental
        // throws → JobFailed → the recorder closes the run as failed.
        $this->bindThrowingImapFactory();
        $installation = ConnectorInstallation::create([
            'tenant_id' => 'default',
            'connector_name' => 'imap',
            'label' => 'support',
            'config_json' => ['auth_mode' => 'basic', 'connection' => ['host' => 'imap.example.com', 'username' => 'a@b.c']],
            'status' => ConnectorInstallation::STATUS_ACTIVE,
            'created_by' => 1,
        ]);

        config(['queue.default' => 'sync']);
        try {
            ConnectorSyncJob::dispatch($installation->id, 'default');
        } catch (\Throwable) {
            // Sync queue rethrows the job exception — expected.
        }

        $run = ConnectorSyncRun::query()
            ->where('connector_installation_id', $installation->id)->firstOrFail();
        $this->assertSame(ConnectorSyncRun::STATUS_FAILED, $run->status);
        $this->assertNotNull($run->error_json);
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

    public function test_ingestion_endpoints_reject_unauthorized_and_guest(): void
    {
        // manageConnectors = admin + super-admin → un viewer è fuori dall'allow-set.
        $this->getJson('/api/admin/ingestion/queue')->assertStatus(401);
        $this->actingAs($this->viewer())
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

    /**
     * Bind a fake IMAP client factory whose client throws on every sync op, so
     * a sync attempt fails deterministically without any network IO.
     */
    private function bindThrowingImapFactory(): void
    {
        $client = new class implements ImapClientInterface
        {
            public function ping(): bool
            {
                return true;
            }

            public function close(): void {}

            public function listMailboxes(): array
            {
                throw new \RuntimeException('fake IMAP failure');
            }

            public function selectMailbox(string $name): MailboxState
            {
                throw new \RuntimeException('fake IMAP failure');
            }

            public function searchUids(string $mailbox, ?Carbon $since, ?int $sinceUid): array
            {
                throw new \RuntimeException('fake IMAP failure');
            }

            public function fetchMessage(string $mailbox, int $uid): ImapMessage
            {
                throw new \RuntimeException('fake IMAP failure');
            }
        };

        $factory = new class($client) implements ImapClientFactoryInterface
        {
            public function __construct(private readonly ImapClientInterface $client) {}

            public function make(array $connection, string $secret, string $authMode): ImapClientInterface
            {
                return $this->client;
            }
        };

        $this->app->instance(ImapClientFactoryInterface::class, $factory);
        $this->app->forgetInstance(ConnectorRegistry::class);
    }

    private function superAdmin(): User
    {
        $user = User::create(['name' => 'Super', 'email' => 'super-'.uniqid().'@demo.local', 'password' => Hash::make('secret123')]);
        $user->assignRole('super-admin');

        return $user;
    }

    private function viewer(): User
    {
        $user = User::create(['name' => 'Viewer', 'email' => 'viewer-'.uniqid().'@demo.local', 'password' => Hash::make('secret123')]);
        $user->assignRole('viewer');

        return $user;
    }
}
