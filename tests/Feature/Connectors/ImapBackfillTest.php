<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Connectors\Imap\Backfill\ImapBackfillManager;
use App\Connectors\Scheduling\SerializedSyncScheduler;
use App\Jobs\Imap\DiscoverImapBackfillJob;
use App\Jobs\Imap\ImportImapBackfillWindowJob;
use App\Jobs\Imap\PumpImapBackfillJob;
use App\Models\ImapBackfill;
use App\Models\ImapBackfillWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
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
        $installation = $this->installation();
        $backfill = ImapBackfill::create([
            'tenant_id' => 'default',
            'connector_installation_id' => $installation->id,
            'status' => ImapBackfill::STATUS_RUNNING,
            'batch_size' => 100,
            'total_messages' => 20,
            'total_windows' => 2,
            'cutoff_at' => now(),
        ]);
        foreach ([['2025-01-01', '2025-02-01'], ['2025-02-01', '2025-03-01']] as [$start, $end]) {
            ImapBackfillWindow::create([
                'tenant_id' => 'default',
                'imap_backfill_id' => $backfill->id,
                'connector_installation_id' => $installation->id,
                'mailbox' => 'INBOX',
                'window_start' => $start,
                'window_end' => $end,
                'status' => ImapBackfillWindow::STATUS_PENDING,
                'next_attempt_at' => now(),
            ]);
        }

        (new PumpImapBackfillJob($backfill->id, 'default'))->handle();
        (new PumpImapBackfillJob($backfill->id, 'default'))->handle();

        $this->assertSame(1, ImapBackfillWindow::query()->where('status', ImapBackfillWindow::STATUS_QUEUED)->count());
        $this->assertSame(1, ImapBackfillWindow::query()->where('status', ImapBackfillWindow::STATUS_PENDING)->count());
        Queue::assertPushed(ImportImapBackfillWindowJob::class, 1);
    }

    public function test_normal_scheduler_skips_imap_while_backfill_is_active(): void
    {
        Queue::fake();
        $installation = $this->installation();
        ImapBackfill::create([
            'tenant_id' => 'default',
            'connector_installation_id' => $installation->id,
            'status' => ImapBackfill::STATUS_RUNNING,
            'batch_size' => 100,
            'cutoff_at' => now(),
        ]);

        $this->assertSame(0, (new SerializedSyncScheduler)->dispatchDueSyncs());
        Queue::assertNothingPushed();
    }

    private function installation(): ConnectorInstallation
    {
        return ConnectorInstallation::create([
            'tenant_id' => 'default',
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
}
