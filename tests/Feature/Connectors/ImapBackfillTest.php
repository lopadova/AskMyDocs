<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Connectors\Imap\Backfill\ImapBackfillManager;
use App\Connectors\Scheduling\ImapBackfillScheduler;
use App\Connectors\Scheduling\SerializedSyncScheduler;
use App\Jobs\Imap\DiscoverImapBackfillJob;
use App\Jobs\Imap\ImportImapBackfillWindowJob;
use App\Jobs\Imap\PumpImapBackfillJob;
use App\Models\ImapBackfill;
use App\Models\ImapBackfillWindow;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Queue;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext as PackageTenantContext;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Tests\TestCase;

final class ImapBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_start_creates_one_durable_full_history_campaign(): void
    {
        Queue::fake();
        config()->set('connectors.imap.backfill.batch_size', 125);
        $installation = $this->installation();

        $manager = app(ImapBackfillManager::class);
        $first = $manager->start($installation->id);
        $second = $manager->start($installation->id);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(ImapBackfill::STATUS_DISCOVERING, $first->status);
        $this->assertSame(125, $first->batch_size);
        $this->assertSame(0, $first->settings_json['date_window_days']);
        $this->assertFalse($first->settings_json['skip_auto_generated']);
        $this->assertFalse($first->settings_json['only_unseen']);
        $this->assertSame([], $first->settings_json['senders']['include']);
        Queue::assertPushed(DiscoverImapBackfillJob::class, 1);
    }

    public function test_start_resumes_the_latest_failed_campaign_from_its_saved_window_checkpoints(): void
    {
        Queue::fake();
        $installation = $this->installation();
        $cutoff = now()->subHour();
        $backfill = ImapBackfill::create([
            'tenant_id' => $this->tenantId(),
            'connector_installation_id' => $installation->id,
            'status' => ImapBackfill::STATUS_FAILED,
            'batch_size' => 100,
            'total_messages' => 128_199,
            // Deliberately stale aggregates: resume must rebuild them from the
            // durable per-window checkpoints instead of trusting a torn write.
            'processed_messages' => 99_999,
            'dispatched_documents' => 99_999,
            'total_windows' => 3,
            'completed_windows' => 0,
            'cutoff_at' => $cutoff,
            'completed_at' => now(),
            'error_json' => ['message' => 'Exchange closed the connection'],
        ]);
        $completed = ImapBackfillWindow::create([
            'tenant_id' => $this->tenantId(),
            'imap_backfill_id' => $backfill->id,
            'connector_installation_id' => $installation->id,
            'mailbox' => 'INBOX',
            'window_start' => '2025-01-01',
            'window_end' => '2025-02-01',
            'status' => ImapBackfillWindow::STATUS_COMPLETED,
            'last_uid' => 5_000,
            'processed_messages' => 5_000,
            'dispatched_documents' => 5_200,
            'finished_at' => now()->subMinutes(10),
        ]);
        $failed = ImapBackfillWindow::create([
            'tenant_id' => $this->tenantId(),
            'imap_backfill_id' => $backfill->id,
            'connector_installation_id' => $installation->id,
            'mailbox' => 'INBOX',
            'window_start' => '2025-02-01',
            'window_end' => '2025-03-01',
            'status' => ImapBackfillWindow::STATUS_FAILED,
            'last_uid' => 9_000,
            'processed_messages' => 4_000,
            'dispatched_documents' => 4_100,
            'attempts' => 5,
            'finished_at' => now(),
            'error_json' => ['message' => 'Exchange closed the connection'],
        ]);
        $pending = ImapBackfillWindow::create([
            'tenant_id' => $this->tenantId(),
            'imap_backfill_id' => $backfill->id,
            'connector_installation_id' => $installation->id,
            'mailbox' => 'INBOX',
            'window_start' => '2025-03-01',
            'window_end' => '2025-04-01',
            'status' => ImapBackfillWindow::STATUS_PENDING,
            'next_attempt_at' => now()->addHour(),
        ]);
        $persistedCutoff = $backfill->fresh()->cutoff_at;

        $manager = app(ImapBackfillManager::class);
        $resumed = $manager->start($installation->id);
        $sameActive = $manager->start($installation->id);

        $this->assertSame($backfill->id, $resumed->id);
        $this->assertSame($backfill->id, $sameActive->id);
        $this->assertDatabaseCount('imap_backfills', 1);
        $this->assertSame(ImapBackfill::STATUS_RUNNING, $resumed->status);
        $this->assertSame(9_000, $resumed->processed_messages);
        $this->assertSame(9_300, $resumed->dispatched_documents);
        $this->assertSame(3, $resumed->total_windows);
        $this->assertSame(1, $resumed->completed_windows);
        $this->assertNull($resumed->completed_at);
        $this->assertNull($resumed->error_json);
        $this->assertTrue($resumed->cutoff_at->equalTo($persistedCutoff));

        $this->assertSame(ImapBackfillWindow::STATUS_COMPLETED, $completed->fresh()->status);
        $this->assertSame(5_000, $completed->fresh()->last_uid);
        $this->assertSame(ImapBackfillWindow::STATUS_PENDING, $failed->fresh()->status);
        $this->assertSame(9_000, $failed->fresh()->last_uid);
        $this->assertSame(4_000, $failed->fresh()->processed_messages);
        $this->assertSame(5, $failed->fresh()->attempts);
        $this->assertNull($failed->fresh()->finished_at);
        $this->assertNull($failed->fresh()->error_json);
        $this->assertSame(ImapBackfillWindow::STATUS_PENDING, $pending->fresh()->status);
        $this->assertTrue($pending->fresh()->next_attempt_at->lte(now()));

        Queue::assertPushed(PumpImapBackfillJob::class, 1);
        Queue::assertPushed(PumpImapBackfillJob::class, fn ($job): bool =>
            $job->backfillId === $backfill->id && $job->tenantId === $this->tenantId()
        );
        Queue::assertNotPushed(DiscoverImapBackfillJob::class);
    }

    public function test_start_rearms_an_errored_installation_when_resuming_a_failed_campaign(): void
    {
        Queue::fake();
        $installation = $this->installation();
        $installation->forceFill([
            'status' => ConnectorInstallation::STATUS_ERRORED,
            'error_json' => ['message' => 'fwrite(): SSL: Broken pipe'],
        ])->save();
        $backfill = ImapBackfill::create([
            'tenant_id' => $this->tenantId(),
            'connector_installation_id' => $installation->id,
            'status' => ImapBackfill::STATUS_FAILED,
            'batch_size' => 100,
            'total_windows' => 1,
            'cutoff_at' => now()->subHour(),
            'error_json' => ['message' => 'Mailbox busy'],
        ]);
        ImapBackfillWindow::create([
            'tenant_id' => $this->tenantId(),
            'imap_backfill_id' => $backfill->id,
            'connector_installation_id' => $installation->id,
            'mailbox' => 'INBOX',
            'window_start' => '2025-01-01',
            'window_end' => '2025-02-01',
            'status' => ImapBackfillWindow::STATUS_FAILED,
            'last_uid' => 17_517,
            'processed_messages' => 17_517,
            'error_json' => ['message' => 'Mailbox busy'],
        ]);

        $resumed = app(ImapBackfillManager::class)->start($installation->id);

        $this->assertSame($backfill->id, $resumed->id);
        $this->assertSame(ImapBackfill::STATUS_RUNNING, $resumed->status);
        $this->assertSame(17_517, $resumed->processed_messages);
        $this->assertSame(ConnectorInstallation::STATUS_ACTIVE, $installation->fresh()->status);
        $this->assertNull($installation->fresh()->error_json);
        Queue::assertPushed(PumpImapBackfillJob::class, 1);
    }

    public function test_start_retries_discovery_on_the_same_failed_campaign_when_no_windows_exist(): void
    {
        Queue::fake();
        $installation = $this->installation();
        $backfill = ImapBackfill::create([
            'tenant_id' => $this->tenantId(),
            'connector_installation_id' => $installation->id,
            'status' => ImapBackfill::STATUS_FAILED,
            'batch_size' => 100,
            'cutoff_at' => now()->subHour(),
            'error_json' => ['message' => 'Discovery connection failed'],
        ]);

        $resumed = app(ImapBackfillManager::class)->start($installation->id);

        $this->assertSame($backfill->id, $resumed->id);
        $this->assertDatabaseCount('imap_backfills', 1);
        $this->assertSame(ImapBackfill::STATUS_DISCOVERING, $resumed->status);
        $this->assertNull($resumed->error_json);
        Queue::assertPushed(DiscoverImapBackfillJob::class, fn ($job): bool =>
            $job->backfillId === $backfill->id && $job->tenantId === $this->tenantId()
        );
        Queue::assertNotPushed(PumpImapBackfillJob::class);
    }

    public function test_start_creates_a_fresh_snapshot_only_when_uidvalidity_invalidates_the_failed_campaign(): void
    {
        Queue::fake();
        $installation = $this->installation();
        $failed = ImapBackfill::create([
            'tenant_id' => $this->tenantId(),
            'connector_installation_id' => $installation->id,
            'status' => ImapBackfill::STATUS_FAILED,
            'batch_size' => 100,
            'total_messages' => 10_000,
            'processed_messages' => 9_000,
            'total_windows' => 1,
            'cutoff_at' => now()->subHour(),
            'error_json' => ['message' => 'UIDVALIDITY changed for INBOX'],
        ]);
        ImapBackfillWindow::create([
            'tenant_id' => $this->tenantId(),
            'imap_backfill_id' => $failed->id,
            'connector_installation_id' => $installation->id,
            'mailbox' => 'INBOX',
            'window_start' => '2025-01-01',
            'window_end' => '2025-02-01',
            'status' => ImapBackfillWindow::STATUS_FAILED,
            'last_uid' => 9_000,
            'processed_messages' => 9_000,
            'error_json' => ['message' => 'UIDVALIDITY changed for INBOX'],
        ]);

        $manager = app(ImapBackfillManager::class);
        $this->assertSame('restart', $manager->status($installation->id)['retry_mode']);

        $replacement = $manager->start($installation->id);

        $this->assertNotSame($failed->id, $replacement->id);
        $this->assertDatabaseCount('imap_backfills', 2);
        $this->assertSame(ImapBackfill::STATUS_FAILED, $failed->fresh()->status);
        $this->assertSame(ImapBackfill::STATUS_DISCOVERING, $replacement->status);
        $this->assertSame(0, $replacement->processed_messages);
        Queue::assertPushed(DiscoverImapBackfillJob::class, fn ($job): bool =>
            $job->backfillId === $replacement->id && $job->tenantId === $this->tenantId()
        );
        Queue::assertNotPushed(PumpImapBackfillJob::class);
    }

    public function test_pump_claims_only_one_window_at_a_time(): void
    {
        Queue::fake();
        $tenantId = $this->tenantId();
        $installation = $this->installation();
        $backfill = ImapBackfill::create([
            'tenant_id' => $tenantId,
            'connector_installation_id' => $installation->id,
            'status' => ImapBackfill::STATUS_RUNNING,
            'batch_size' => 100,
            'total_messages' => 20,
            'total_windows' => 2,
            'cutoff_at' => now(),
        ]);
        foreach ([['2025-01-01', '2025-02-01'], ['2025-02-01', '2025-03-01']] as [$start, $end]) {
            ImapBackfillWindow::create([
                'tenant_id' => $tenantId,
                'imap_backfill_id' => $backfill->id,
                'connector_installation_id' => $installation->id,
                'mailbox' => 'INBOX',
                'window_start' => $start,
                'window_end' => $end,
                'status' => ImapBackfillWindow::STATUS_PENDING,
                'next_attempt_at' => now(),
            ]);
        }

        (new PumpImapBackfillJob($backfill->id, $tenantId))->handle();
        (new PumpImapBackfillJob($backfill->id, $tenantId))->handle();

        $this->assertSame(1, ImapBackfillWindow::query()->where('status', ImapBackfillWindow::STATUS_QUEUED)->count());
        $this->assertSame(1, ImapBackfillWindow::query()->where('status', ImapBackfillWindow::STATUS_PENDING)->count());
        Queue::assertPushed(ImportImapBackfillWindowJob::class, 1);
    }

    public function test_sync_discovery_and_import_jobs_share_one_queue_overlap_lock(): void
    {
        config()->set('connectors.imap.serialize_connections', true);
        config()->set('cache.default', 'array');
        $tenantId = $this->tenantId();
        $installation = $this->installation();
        $backfill = ImapBackfill::create([
            'tenant_id' => $tenantId,
            'connector_installation_id' => $installation->id,
            'status' => ImapBackfill::STATUS_RUNNING,
            'batch_size' => 100,
            'cutoff_at' => now(),
        ]);
        $window = ImapBackfillWindow::create([
            'tenant_id' => $tenantId,
            'imap_backfill_id' => $backfill->id,
            'connector_installation_id' => $installation->id,
            'mailbox' => 'INBOX',
            'window_start' => '2026-01-01',
            'window_end' => '2026-02-01',
            'status' => ImapBackfillWindow::STATUS_PENDING,
        ]);

        $syncJob = new \App\Connectors\SerializedConnectorSyncJob($installation->id, $tenantId);
        $discoveryJob = new DiscoverImapBackfillJob($backfill->id, $tenantId);
        $importJob = new ImportImapBackfillWindowJob($window->id, $tenantId);

        $isOverlapLock = static fn (object $middleware): bool => $middleware instanceof WithoutOverlapping;
        $syncLock = collect($syncJob->middleware())->first($isOverlapLock);
        $discoveryLock = collect($discoveryJob->middleware())->first($isOverlapLock);
        $importLock = collect($importJob->middleware())->first($isOverlapLock);

        $this->assertInstanceOf(WithoutOverlapping::class, $syncLock);
        $this->assertInstanceOf(WithoutOverlapping::class, $discoveryLock);
        $this->assertInstanceOf(WithoutOverlapping::class, $importLock);
        $this->assertTrue($syncLock->shareKey);
        $this->assertTrue($discoveryLock->shareKey);
        $this->assertTrue($importLock->shareKey);
        $this->assertSame($syncLock->getLockKey($syncJob), $discoveryLock->getLockKey($discoveryJob));
        $this->assertSame($syncLock->getLockKey($syncJob), $importLock->getLockKey($importJob));
    }

    public function test_pump_restores_both_worker_tenant_contexts_after_an_early_return(): void
    {
        $hostTenant = app(TenantContext::class);
        $packageTenant = app(PackageTenantContext::class);
        $hostTenant->set('host-worker');
        $packageTenant->set('package-worker');

        (new PumpImapBackfillJob(999999, 'job-tenant'))->handle();

        $this->assertSame('host-worker', $hostTenant->current());
        $this->assertSame('package-worker', $packageTenant->current());
    }

    public function test_normal_scheduler_skips_imap_while_backfill_is_active(): void
    {
        Queue::fake();
        $tenantId = $this->tenantId();
        $installation = $this->installation();
        ImapBackfill::create([
            'tenant_id' => $tenantId,
            'connector_installation_id' => $installation->id,
            'status' => ImapBackfill::STATUS_RUNNING,
            'batch_size' => 100,
            'cutoff_at' => now(),
        ]);

        $this->assertSame(0, (new SerializedSyncScheduler)->dispatchDueSyncs());
        Queue::assertNothingPushed();
    }

    public function test_backfill_scheduler_recovers_stale_discovery_dispatch(): void
    {
        Queue::fake();
        config()->set('connectors.imap.backfill.stale_after_minutes', 20);
        $installation = $this->installation();
        $backfill = ImapBackfill::create([
            'tenant_id' => $this->tenantId(),
            'connector_installation_id' => $installation->id,
            'status' => ImapBackfill::STATUS_DISCOVERING,
            'batch_size' => 100,
            'cutoff_at' => now(),
            'heartbeat_at' => now()->subMinutes(21),
        ]);

        $this->assertSame(1, (new ImapBackfillScheduler)->pumpActive());

        Queue::assertPushed(DiscoverImapBackfillJob::class, function ($job) use ($backfill): bool {
            return $job->backfillId === $backfill->id && $job->tenantId === $this->tenantId();
        });
    }

    public function test_disabled_backfill_rejects_start_without_dispatching(): void
    {
        Queue::fake();
        config()->set('connectors.imap.backfill.enabled', false);
        $installation = $this->installation();

        $this->expectException(ConflictHttpException::class);
        try {
            app(ImapBackfillManager::class)->start($installation->id);
        } finally {
            Queue::assertNothingPushed();
        }
    }

    public function test_start_requires_an_active_imap_installation(): void
    {
        Queue::fake();
        $installation = $this->installation();
        $installation->forceFill(['status' => ConnectorInstallation::STATUS_DISABLED])->save();

        $this->expectException(UnprocessableEntityHttpException::class);
        app(ImapBackfillManager::class)->start($installation->id);
    }

    public function test_status_404s_for_an_unknown_or_cross_tenant_installation(): void
    {
        $foreign = $this->installation();
        $foreign->forceFill(['tenant_id' => 'tenant-b'])->save();

        $this->expectException(NotFoundHttpException::class);
        app(ImapBackfillManager::class)->status($foreign->id);
    }

    public function test_status_treats_completed_as_100_percent_and_clamps_running_progress(): void
    {
        $installation = $this->installation();
        $backfill = ImapBackfill::create([
            'tenant_id' => $this->tenantId(),
            'connector_installation_id' => $installation->id,
            'status' => ImapBackfill::STATUS_COMPLETED,
            'batch_size' => 100,
            'total_messages' => 100,
            'processed_messages' => 90,
            'total_windows' => 1,
            'completed_windows' => 1,
            'cutoff_at' => now(),
        ]);

        $manager = app(ImapBackfillManager::class);
        $this->assertSame(100.0, $manager->status($installation->id)['progress_percent']);

        $backfill->forceFill([
            'status' => ImapBackfill::STATUS_RUNNING,
            'processed_messages' => 150,
        ])->save();
        $this->assertSame(100.0, $manager->status($installation->id)['progress_percent']);
    }

    public function test_exhausted_window_retries_fail_the_window_and_campaign(): void
    {
        $installation = $this->installation();
        $backfill = ImapBackfill::create([
            'tenant_id' => $this->tenantId(),
            'connector_installation_id' => $installation->id,
            'status' => ImapBackfill::STATUS_RUNNING,
            'batch_size' => 100,
            'total_windows' => 1,
            'cutoff_at' => now(),
        ]);
        $window = ImapBackfillWindow::create([
            'tenant_id' => $this->tenantId(),
            'imap_backfill_id' => $backfill->id,
            'connector_installation_id' => $installation->id,
            'mailbox' => 'INBOX',
            'window_start' => '2026-01-01',
            'window_end' => '2026-02-01',
            'status' => ImapBackfillWindow::STATUS_RUNNING,
        ]);

        (new ImportImapBackfillWindowJob($window->id, $this->tenantId()))
            ->failed(new \RuntimeException('UIDVALIDITY changed'));

        $this->assertSame(ImapBackfillWindow::STATUS_FAILED, $window->fresh()->status);
        $this->assertNull($window->fresh()->next_attempt_at);
        $this->assertSame(ImapBackfill::STATUS_FAILED, $backfill->fresh()->status);
        $this->assertSame('UIDVALIDITY changed', $backfill->fresh()->error_json['message']);
    }

    public function test_import_job_lock_requeues_are_bounded_by_wall_clock_not_attempts(): void
    {
        config()->set('connectors.imap.mailbox_lock.requeue_window_minutes', 30);
        $job = new ImportImapBackfillWindowJob(1, $this->tenantId());
        $now = now();

        $this->assertSame(0, $job->tries);
        $this->assertGreaterThan($now->copy()->addMinutes(29)->getTimestamp(), $job->retryUntil()->getTimestamp());
        $this->assertLessThanOrEqual($now->copy()->addMinutes(31)->getTimestamp(), $job->retryUntil()->getTimestamp());
    }

    public function test_import_job_restores_tenants_and_middleware_does_not_mutate_them(): void
    {
        $hostTenant = app(TenantContext::class);
        $packageTenant = app(PackageTenantContext::class);
        $hostTenant->set('host-worker');
        $packageTenant->set('package-worker');
        $job = new ImportImapBackfillWindowJob(999999, 'job-tenant');

        $job->middleware();
        $this->assertSame('host-worker', $hostTenant->current());
        $this->assertSame('package-worker', $packageTenant->current());

        $job->handle(app(\App\Connectors\Imap\Backfill\ImapBackfillImporter::class));
        $this->assertSame('host-worker', $hostTenant->current());
        $this->assertSame('package-worker', $packageTenant->current());

        $job->failed(new \RuntimeException('test failure'));
        $this->assertSame('host-worker', $hostTenant->current());
        $this->assertSame('package-worker', $packageTenant->current());
    }

    public function test_discovery_job_lock_requeues_are_bounded_by_wall_clock_not_attempts(): void
    {
        config()->set('connectors.imap.mailbox_lock.requeue_window_minutes', 45);
        $job = new DiscoverImapBackfillJob(1, $this->tenantId());
        $now = now();

        $this->assertSame(0, $job->tries);
        $this->assertGreaterThan($now->copy()->addMinutes(44)->getTimestamp(), $job->retryUntil()->getTimestamp());
        $this->assertLessThanOrEqual($now->copy()->addMinutes(46)->getTimestamp(), $job->retryUntil()->getTimestamp());
    }

    public function test_discovery_job_restores_tenants_and_middleware_does_not_mutate_them(): void
    {
        $hostTenant = app(TenantContext::class);
        $packageTenant = app(PackageTenantContext::class);
        $hostTenant->set('host-worker');
        $packageTenant->set('package-worker');
        $job = new DiscoverImapBackfillJob(999999, 'job-tenant');

        $job->middleware();
        $this->assertSame('host-worker', $hostTenant->current());
        $this->assertSame('package-worker', $packageTenant->current());

        $job->handle(app(\App\Connectors\Imap\Backfill\ImapBackfillDiscovery::class));
        $this->assertSame('host-worker', $hostTenant->current());
        $this->assertSame('package-worker', $packageTenant->current());

        $job->failed(new \RuntimeException('test failure'));
        $this->assertSame('host-worker', $hostTenant->current());
        $this->assertSame('package-worker', $packageTenant->current());
    }

    public function test_discovery_failure_bulk_update_serializes_error_json(): void
    {
        $installation = $this->installation();
        $backfill = ImapBackfill::create([
            'tenant_id' => $this->tenantId(),
            'connector_installation_id' => $installation->id,
            'status' => ImapBackfill::STATUS_DISCOVERING,
            'batch_size' => 100,
            'cutoff_at' => now(),
        ]);

        (new DiscoverImapBackfillJob($backfill->id, $this->tenantId()))
            ->failed(new \RuntimeException('discovery exploded'));

        $this->assertSame(ImapBackfill::STATUS_FAILED, $backfill->fresh()->status);
        $error = $backfill->fresh()->error_json;
        $this->assertSame('discovery exploded', $error['message']);
        $this->assertSame(\RuntimeException::class, $error['type']);
        $this->assertSame('discovery exploded', $error['terminal_queue_error']['message']);
        $this->assertSame(\RuntimeException::class, $error['terminal_queue_error']['type']);
        $this->assertNotEmpty($error['terminal_queue_error']['at']);
    }

    public function test_terminal_queue_failure_preserves_the_original_discovery_error(): void
    {
        $installation = $this->installation();
        $backfill = ImapBackfill::create([
            'tenant_id' => $this->tenantId(),
            'connector_installation_id' => $installation->id,
            'status' => ImapBackfill::STATUS_DISCOVERING,
            'batch_size' => 100,
            'cutoff_at' => now(),
            'error_json' => [
                'message' => 'IMAP discovery failed [diag=abc phase=list_mailboxes]: fwrite(): SSL: Broken pipe',
                'type' => \RuntimeException::class,
            ],
        ]);

        (new DiscoverImapBackfillJob($backfill->id, $this->tenantId()))
            ->failed(new \RuntimeException('has been attempted too many times'));

        $error = $backfill->fresh()->error_json;
        $this->assertSame('IMAP discovery failed [diag=abc phase=list_mailboxes]: fwrite(): SSL: Broken pipe', $error['message']);
        $this->assertSame('has been attempted too many times', $error['terminal_queue_error']['message']);
    }

    public function test_deleting_installation_cascades_campaigns_and_windows(): void
    {
        $installation = $this->installation();
        $backfill = ImapBackfill::create([
            'tenant_id' => $this->tenantId(),
            'connector_installation_id' => $installation->id,
            'status' => ImapBackfill::STATUS_RUNNING,
            'batch_size' => 100,
            'cutoff_at' => now(),
        ]);
        ImapBackfillWindow::create([
            'tenant_id' => $this->tenantId(),
            'imap_backfill_id' => $backfill->id,
            'connector_installation_id' => $installation->id,
            'mailbox' => 'INBOX',
            'window_start' => '2026-01-01',
            'window_end' => '2026-02-01',
        ]);

        $installation->delete();

        $this->assertDatabaseCount('imap_backfills', 0);
        $this->assertDatabaseCount('imap_backfill_windows', 0);
    }

    private function installation(): ConnectorInstallation
    {
        return ConnectorInstallation::create([
            'tenant_id' => $this->tenantId(),
            'connector_name' => 'imap',
            'label' => 'autry',
            'config_json' => [
                'auth_mode' => 'basic',
                'date_window_days' => 0,
                'skip_auto_generated' => true,
                'only_unseen' => true,
                'folders' => ['include' => ['INBOX']],
                'connection' => ['host' => 'imap.example.test', 'username' => 'mail@example.test'],
            ],
            'status' => ConnectorInstallation::STATUS_ACTIVE,
            'last_sync_at' => null,
            'created_by' => 1,
        ]);
    }

    private function tenantId(): string
    {
        return app(TenantContext::class)->current();
    }
}
