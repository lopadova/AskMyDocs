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
use Illuminate\Support\Facades\Queue;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
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
