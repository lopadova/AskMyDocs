<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Connectors\Imap\Backfill\ImapBackfillDiscovery;
use App\Connectors\Imap\Backfill\ImapBackfillImporter;
use App\Connectors\Imap\Backfill\ImapBackfillManager;
use App\Connectors\Imap\MailboxLockKey;
use App\Jobs\Imap\DiscoverImapBackfillJob;
use App\Jobs\Imap\ImportImapBackfillWindowJob;
use App\Models\ImapBackfill;
use App\Models\ImapBackfillWindow;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Tests\TestCase;

final class ResetImapBackfillCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_discards_only_the_selected_campaign_and_allows_a_clean_restart(): void
    {
        Queue::fake();
        $targetInstallation = $this->installation('prima-demo', 'prima@example.test');
        $target = $this->backfill($targetInstallation, 'prima-demo');
        $targetWindow = $this->window($targetInstallation, $target, 'prima-demo');
        $otherInstallation = $this->installation('other-tenant', 'other@example.test');
        $other = $this->backfill($otherInstallation, 'other-tenant');
        $otherWindow = $this->window($otherInstallation, $other, 'other-tenant');

        $this->artisan('connectors:imap-backfill:reset', [
            'tenant' => 'prima-demo',
            'backfill' => $target->id,
            '--wait' => 0,
            '--force' => true,
        ])
            ->expectsOutputToContain("Backfill {$target->id} was discarded; 1 window(s) removed.")
            ->assertSuccessful();

        $this->assertDatabaseMissing('imap_backfills', ['id' => $target->id]);
        $this->assertDatabaseMissing('imap_backfill_windows', ['id' => $targetWindow->id]);
        $this->assertDatabaseHas('imap_backfills', ['id' => $other->id, 'tenant_id' => 'other-tenant']);
        $this->assertDatabaseHas('imap_backfill_windows', ['id' => $otherWindow->id, 'tenant_id' => 'other-tenant']);

        // Jobs already serialized in Redis retain only these old ids. Once the
        // campaign/window rows are gone, their guards return before any IMAP I/O.
        (new DiscoverImapBackfillJob($target->id, 'prima-demo'))
            ->handle(app(ImapBackfillDiscovery::class));
        (new ImportImapBackfillWindowJob($targetWindow->id, 'prima-demo'))
            ->handle(app(ImapBackfillImporter::class));

        app(TenantContext::class)->set('prima-demo');
        $replacement = app(ImapBackfillManager::class)->start($targetInstallation->id);

        $this->assertNotSame($target->id, $replacement->id);
        $this->assertSame(ImapBackfill::STATUS_DISCOVERING, $replacement->status);
        Queue::assertPushed(DiscoverImapBackfillJob::class, fn (DiscoverImapBackfillJob $job): bool =>
            $job->backfillId === $replacement->id && $job->tenantId === 'prima-demo'
        );
    }

    public function test_it_changes_nothing_when_confirmation_is_refused(): void
    {
        $installation = $this->installation('prima-demo', 'prima@example.test');
        $backfill = $this->backfill($installation, 'prima-demo');
        $window = $this->window($installation, $backfill, 'prima-demo');

        $this->artisan('connectors:imap-backfill:reset', [
            'tenant' => 'prima-demo',
            'backfill' => $backfill->id,
        ])
            ->expectsConfirmation('Discard this IMAP backfill now?', 'no')
            ->expectsOutputToContain('IMAP backfill reset cancelled; no data was changed.')
            ->assertSuccessful();

        $this->assertDatabaseHas('imap_backfills', ['id' => $backfill->id]);
        $this->assertDatabaseHas('imap_backfill_windows', ['id' => $window->id]);
    }

    public function test_it_refuses_to_reset_while_the_queue_overlap_lock_is_busy(): void
    {
        $installation = $this->installation('prima-demo', 'prima@example.test');
        $backfill = $this->backfill($installation, 'prima-demo');
        $window = $this->window($installation, $backfill, 'prima-demo');
        $mailboxKey = MailboxLockKey::forInstallation($installation);
        $this->assertNotNull($mailboxKey);
        $queueKey = (new WithoutOverlapping($mailboxKey))->shared()->getLockKey($this);
        $heldLock = Cache::lock($queueKey, 60);
        $this->assertTrue($heldLock->get());

        try {
            $this->artisan('connectors:imap-backfill:reset', [
                'tenant' => 'prima-demo',
                'backfill' => $backfill->id,
                '--wait' => 0,
                '--force' => true,
            ])
                ->expectsOutputToContain('The mailbox is still busy after 0 second(s); no data was changed.')
                ->assertFailed();
        } finally {
            $heldLock->release();
        }

        $this->assertDatabaseHas('imap_backfills', ['id' => $backfill->id]);
        $this->assertDatabaseHas('imap_backfill_windows', ['id' => $window->id]);
    }

    public function test_it_rejects_a_cross_tenant_or_unknown_backfill(): void
    {
        $installation = $this->installation('other-tenant', 'other@example.test');
        $backfill = $this->backfill($installation, 'other-tenant');

        $this->artisan('connectors:imap-backfill:reset', [
            'tenant' => 'prima-demo',
            'backfill' => $backfill->id,
            '--force' => true,
        ])
            ->expectsOutputToContain("does not exist in tenant 'prima-demo'")
            ->assertFailed();

        $this->assertDatabaseHas('imap_backfills', ['id' => $backfill->id, 'tenant_id' => 'other-tenant']);
    }

    private function installation(string $tenantId, string $username): ConnectorInstallation
    {
        return ConnectorInstallation::query()->create([
            'tenant_id' => $tenantId,
            'connector_name' => 'imap',
            'label' => 'mail-'.$tenantId,
            'config_json' => [
                'auth_mode' => 'basic',
                'folders' => ['include' => ['INBOX']],
                'connection' => [
                    'host' => 'imap.example.test',
                    'port' => 993,
                    'username' => $username,
                ],
            ],
            'status' => ConnectorInstallation::STATUS_ACTIVE,
            'created_by' => 1,
        ]);
    }

    private function backfill(ConnectorInstallation $installation, string $tenantId): ImapBackfill
    {
        return ImapBackfill::query()->create([
            'tenant_id' => $tenantId,
            'connector_installation_id' => $installation->id,
            'status' => ImapBackfill::STATUS_DISCOVERING,
            'batch_size' => 10,
            'cutoff_at' => now(),
            'started_at' => now(),
            'heartbeat_at' => now(),
        ]);
    }

    private function window(
        ConnectorInstallation $installation,
        ImapBackfill $backfill,
        string $tenantId,
    ): ImapBackfillWindow {
        return ImapBackfillWindow::query()->create([
            'tenant_id' => $tenantId,
            'imap_backfill_id' => $backfill->id,
            'connector_installation_id' => $installation->id,
            'mailbox' => 'INBOX',
            'window_start' => '2026-01-01',
            'window_end' => '2026-02-01',
            'status' => ImapBackfillWindow::STATUS_QUEUED,
        ]);
    }
}
