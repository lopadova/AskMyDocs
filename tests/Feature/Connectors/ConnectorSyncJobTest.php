<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Connectors\BuiltIn\GoogleDriveConnector;
use App\Connectors\ConnectorInterface;
use App\Connectors\ConnectorRegistry;
use App\Connectors\HealthStatus;
use App\Connectors\SyncResult;
use App\Jobs\ConnectorSyncJob;
use App\Models\ConnectorInstallation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * v4.5/W1 — ConnectorSyncJob behaviour: invokes syncIncremental with
 * last_sync_at, updates state on success, records error on exception.
 */
final class ConnectorSyncJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_calls_sync_incremental_with_last_sync_at(): void
    {
        $installation = $this->makeInstallation('tenant-a');
        $installation->forceFill([
            'last_sync_at' => Carbon::parse('2026-05-15T10:00:00Z'),
        ])->save();

        $stub = new RecordingStubConnector(
            result: new SyncResult(
                documentsAdded: 5,
                documentsUpdated: 2,
                documentsRemoved: 0,
                errors: [],
                completedAt: Carbon::parse('2026-05-15T10:30:00Z'),
            ),
        );
        $this->swapConnectorRegistry($stub);

        $job = new ConnectorSyncJob($installation->id, $installation->tenant_id);
        $job->handle(
            $this->app->make(ConnectorRegistry::class),
            $this->app->make(\App\Support\TenantContext::class),
        );

        $this->assertSame(1, $stub->syncIncrementalCalls);
        $this->assertNotNull($stub->lastSince);
        $this->assertSame('2026-05-15T10:00:00+00:00', $stub->lastSince->toIso8601String());
        $this->assertSame($installation->id, $stub->lastInstallationId);
    }

    public function test_job_updates_last_sync_at_on_success(): void
    {
        $installation = $this->makeInstallation('default');

        $stub = new RecordingStubConnector(
            result: new SyncResult(
                documentsAdded: 1,
                documentsUpdated: 0,
                documentsRemoved: 0,
                errors: [],
                completedAt: Carbon::parse('2026-05-15T11:00:00Z'),
            ),
        );
        $this->swapConnectorRegistry($stub);

        $job = new ConnectorSyncJob($installation->id, 'default');
        $job->handle(
            $this->app->make(ConnectorRegistry::class),
            $this->app->make(\App\Support\TenantContext::class),
        );

        $installation->refresh();
        $this->assertNotNull($installation->last_sync_at);
        $this->assertSame(
            '2026-05-15T11:00:00+00:00',
            $installation->last_sync_at->toIso8601String(),
        );
        $this->assertSame(ConnectorInstallation::STATUS_ACTIVE, $installation->status);
        $this->assertNull($installation->error_json);
    }

    public function test_job_records_partial_errors_on_success(): void
    {
        $installation = $this->makeInstallation('default');

        $stub = new RecordingStubConnector(
            result: new SyncResult(
                documentsAdded: 1,
                documentsUpdated: 0,
                documentsRemoved: 0,
                errors: ['skipped one file: permission denied'],
                completedAt: Carbon::parse('2026-05-15T11:00:00Z'),
            ),
        );
        $this->swapConnectorRegistry($stub);

        $job = new ConnectorSyncJob($installation->id, 'default');
        $job->handle(
            $this->app->make(ConnectorRegistry::class),
            $this->app->make(\App\Support\TenantContext::class),
        );

        $installation->refresh();
        // Partial errors do NOT mark the installation `errored` —
        // the connector still completed successfully. error_json
        // carries the partial_errors envelope.
        $this->assertSame(ConnectorInstallation::STATUS_ACTIVE, $installation->status);
        $this->assertNotNull($installation->error_json);
        $this->assertSame(
            ['skipped one file: permission denied'],
            $installation->error_json['partial_errors'],
        );
    }

    public function test_job_records_error_json_on_exception(): void
    {
        $installation = $this->makeInstallation('default');

        $stub = new RecordingStubConnector(
            result: SyncResult::empty(),
            throwOnSync: new \RuntimeException('upstream rate-limited'),
        );
        $this->swapConnectorRegistry($stub);

        $job = new ConnectorSyncJob($installation->id, 'default');

        $threw = false;
        try {
            $job->handle(
                $this->app->make(ConnectorRegistry::class),
                $this->app->make(\App\Support\TenantContext::class),
            );
        } catch (\RuntimeException $e) {
            $threw = true;
            $this->assertSame('upstream rate-limited', $e->getMessage());
        }

        $this->assertTrue($threw, 'Job should re-throw so Laravel queue can retry.');

        $installation->refresh();
        $this->assertSame(ConnectorInstallation::STATUS_ERRORED, $installation->status);
        $this->assertNotNull($installation->error_json);
        $this->assertSame('upstream rate-limited', $installation->error_json['message']);
        $this->assertSame(\RuntimeException::class, $installation->error_json['class']);
    }

    public function test_job_skips_when_installation_disabled(): void
    {
        $installation = $this->makeInstallation('default');
        $installation->forceFill(['status' => ConnectorInstallation::STATUS_DISABLED])->save();

        $stub = new RecordingStubConnector(result: SyncResult::empty());
        $this->swapConnectorRegistry($stub);

        $job = new ConnectorSyncJob($installation->id, 'default');
        $job->handle(
            $this->app->make(ConnectorRegistry::class),
            $this->app->make(\App\Support\TenantContext::class),
        );

        $this->assertSame(0, $stub->syncIncrementalCalls);
    }

    /**
     * iter2 finding #2 — pending installations are mid-OAuth and have
     * no credentials yet. The job must skip them rather than dispatch
     * a doomed sync that flips the row to ERRORED.
     */
    public function test_job_skips_when_installation_is_pending(): void
    {
        $installation = $this->makeInstallation('default');
        $installation->forceFill(['status' => ConnectorInstallation::STATUS_PENDING])->save();

        $stub = new RecordingStubConnector(result: SyncResult::empty());
        $this->swapConnectorRegistry($stub);

        $job = new ConnectorSyncJob($installation->id, 'default');
        $job->handle(
            $this->app->make(ConnectorRegistry::class),
            $this->app->make(\App\Support\TenantContext::class),
        );

        $this->assertSame(0, $stub->syncIncrementalCalls);
        // The row stays PENDING — the job does NOT flip it to ERRORED.
        $installation->refresh();
        $this->assertSame(ConnectorInstallation::STATUS_PENDING, $installation->status);
    }

    /**
     * iter2 finding #2 — errored installations also skipped (operator
     * drives a reinstall to recover; we don't auto-flap the cycle).
     */
    public function test_job_skips_when_installation_is_errored(): void
    {
        $installation = $this->makeInstallation('default');
        $installation->forceFill(['status' => ConnectorInstallation::STATUS_ERRORED])->save();

        $stub = new RecordingStubConnector(result: SyncResult::empty());
        $this->swapConnectorRegistry($stub);

        $job = new ConnectorSyncJob($installation->id, 'default');
        $job->handle(
            $this->app->make(ConnectorRegistry::class),
            $this->app->make(\App\Support\TenantContext::class),
        );

        $this->assertSame(0, $stub->syncIncrementalCalls);
    }

    /**
     * iter2 finding #1 — R30 long-lived-worker contract. The job
     * MUST restore the prior tenant context on success so the next
     * job in the same worker process is not silently scoped to this
     * tenant.
     */
    public function test_job_restores_prior_tenant_context_on_success(): void
    {
        $installation = $this->makeInstallation('tenant-a');

        $tenantContext = $this->app->make(\App\Support\TenantContext::class);
        $tenantContext->set('original-tenant');

        $stub = new RecordingStubConnector(result: SyncResult::empty());
        $this->swapConnectorRegistry($stub);

        $job = new ConnectorSyncJob($installation->id, 'tenant-a');
        $job->handle(
            $this->app->make(ConnectorRegistry::class),
            $tenantContext,
        );

        $this->assertSame('original-tenant', $tenantContext->current());
        // And during the call, the connector saw 'tenant-a'.
        $this->assertSame('tenant-a', $stub->tenantContextSeenDuringSync);

        $tenantContext->reset();
    }

    /**
     * iter2 finding #1 — also restores on exception (try/finally).
     */
    public function test_job_restores_prior_tenant_context_on_exception(): void
    {
        $installation = $this->makeInstallation('tenant-a');

        $tenantContext = $this->app->make(\App\Support\TenantContext::class);
        $tenantContext->set('original-tenant');

        $stub = new RecordingStubConnector(
            result: SyncResult::empty(),
            throwOnSync: new \RuntimeException('boom'),
        );
        $this->swapConnectorRegistry($stub);

        $job = new ConnectorSyncJob($installation->id, 'tenant-a');
        try {
            $job->handle(
                $this->app->make(ConnectorRegistry::class),
                $tenantContext,
            );
        } catch (\RuntimeException) {
            // Expected — job rethrows so Laravel queue can retry.
        }

        $this->assertSame('original-tenant', $tenantContext->current());

        $tenantContext->reset();
    }

    public function test_job_silent_no_op_when_installation_missing(): void
    {
        $stub = new RecordingStubConnector(result: SyncResult::empty());
        $this->swapConnectorRegistry($stub);

        $job = new ConnectorSyncJob(999_999, 'default');
        // Should NOT throw.
        $job->handle(
            $this->app->make(ConnectorRegistry::class),
            $this->app->make(\App\Support\TenantContext::class),
        );

        $this->assertSame(0, $stub->syncIncrementalCalls);
    }

    public function test_job_marks_installation_errored_when_connector_unknown(): void
    {
        $installation = $this->makeInstallation('default');
        // Force a connector name that isn't registered.
        $installation->forceFill(['connector_name' => 'ghost-connector'])->save();

        // Swap in a registry that has no `ghost-connector` registered.
        $emptyRegistry = new ConnectorRegistry(
            $this->app,
            ['built_in' => []],
            composerPackages: [],
        );
        $this->app->instance(ConnectorRegistry::class, $emptyRegistry);

        $job = new ConnectorSyncJob($installation->id, 'default');
        $job->handle(
            $this->app->make(ConnectorRegistry::class),
            $this->app->make(\App\Support\TenantContext::class),
        );

        $installation->refresh();
        $this->assertSame(ConnectorInstallation::STATUS_ERRORED, $installation->status);
        $this->assertStringContainsString('no longer registered', $installation->error_json['message']);
    }

    public function test_job_has_correct_retry_config(): void
    {
        $job = new ConnectorSyncJob(1, 'default');
        $this->assertSame(3, $job->tries);
        $this->assertSame([60, 300, 900], $job->backoff);
    }

    private function makeInstallation(string $tenantId): ConnectorInstallation
    {
        $user = User::create([
            'name' => 'User',
            'email' => 'u-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);

        return ConnectorInstallation::create([
            'tenant_id' => $tenantId,
            'connector_name' => 'google-drive',
            'status' => ConnectorInstallation::STATUS_ACTIVE,
            'created_by' => $user->id,
        ]);
    }

    /**
     * Replace the registered ConnectorRegistry singleton with one that
     * resolves `google-drive` to a deterministic stub. Used by the
     * job tests so we don't have to fake Google API calls.
     */
    private function swapConnectorRegistry(RecordingStubConnector $stub): void
    {
        // Build a fresh registry that registers ONLY the stub under
        // the `google-drive` key, replacing the actual built-in.
        $this->app->instance(ConnectorRegistry::class, new class($stub) extends ConnectorRegistry {
            public function __construct(private readonly RecordingStubConnector $stub)
            {
                // Skip parent constructor — we want a deterministic registry.
            }

            public function get(string $name): ?\App\Connectors\ConnectorInterface
            {
                return $name === 'google-drive' ? $this->stub : null;
            }

            public function has(string $name): bool
            {
                return $name === 'google-drive';
            }
        });
    }
}

/**
 * Test double — records every call into the connector so the test
 * can assert what the job dispatched + when.
 */
final class RecordingStubConnector implements ConnectorInterface
{
    public int $syncIncrementalCalls = 0;

    public ?int $lastInstallationId = null;

    public ?Carbon $lastSince = null;

    public ?string $tenantContextSeenDuringSync = null;

    public function __construct(
        public SyncResult $result,
        public ?\Throwable $throwOnSync = null,
    ) {}

    public function key(): string
    {
        return 'google-drive';
    }

    public function displayName(): string
    {
        return 'Google Drive (stub)';
    }

    public function iconUrl(): string
    {
        return '';
    }

    public function oauthScopes(): array
    {
        return [];
    }

    public function initiateOAuth(int $installationId): string
    {
        return '';
    }

    public function handleOAuthCallback(int $installationId, Request $request): void
    {
        //
    }

    public function syncFull(int $installationId): SyncResult
    {
        return $this->result;
    }

    public function syncIncremental(int $installationId, ?Carbon $since): SyncResult
    {
        $this->syncIncrementalCalls++;
        $this->lastInstallationId = $installationId;
        $this->lastSince = $since;
        $this->tenantContextSeenDuringSync = app(\App\Support\TenantContext::class)->current();

        if ($this->throwOnSync !== null) {
            throw $this->throwOnSync;
        }

        return $this->result;
    }

    public function disconnect(int $installationId): void
    {
        //
    }

    public function health(int $installationId): HealthStatus
    {
        return HealthStatus::healthy();
    }
}
