<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Connectors\Imap\Backfill\ImapBackfillManager;
use App\Jobs\Imap\PumpImapBackfillJob;
use App\Mcp\Tools\KbImapBackfillTool;
use App\Models\ImapBackfill;
use App\Models\ImapBackfillWindow;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Mcp\Request;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Tests\TestCase;

final class KbImapBackfillToolTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string,mixed> $arguments */
    private function invoke(array $arguments): array
    {
        $response = (new KbImapBackfillTool)->handle(
            new Request($arguments),
            app(ImapBackfillManager::class),
            app(TenantContext::class),
        );

        return json_decode((string) $response->content(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_status_and_start_share_the_manager_contract(): void
    {
        Queue::fake();
        $installation = $this->installation(app(TenantContext::class)->current());

        $status = $this->invoke(['installation_id' => $installation->id, 'action' => 'status']);
        $this->assertTrue($status['enabled']);
        $this->assertNull($status['backfill']);

        $started = $this->invoke(['installation_id' => $installation->id, 'action' => 'start']);
        $this->assertSame(app(TenantContext::class)->current(), $started['tenant_id']);
        $this->assertSame($installation->id, $started['backfill']['installation_id']);
        $this->assertSame('discovering', $started['backfill']['status']);
    }

    public function test_cross_tenant_status_returns_an_error_without_data(): void
    {
        $installation = $this->installation('tenant-b');
        app(TenantContext::class)->set('tenant-a');

        $payload = $this->invoke(['installation_id' => $installation->id, 'action' => 'status']);

        $this->assertArrayHasKey('error', $payload);
        $this->assertArrayNotHasKey('backfill', $payload);
    }

    public function test_start_action_resumes_a_failed_campaign_instead_of_replacing_it(): void
    {
        Queue::fake();
        $installation = $this->installation(app(TenantContext::class)->current());
        $backfill = ImapBackfill::create([
            'tenant_id' => app(TenantContext::class)->current(),
            'connector_installation_id' => $installation->id,
            'status' => ImapBackfill::STATUS_FAILED,
            'batch_size' => 100,
            'total_messages' => 10_000,
            'processed_messages' => 9_000,
            'total_windows' => 1,
            'cutoff_at' => now()->subHour(),
            'error_json' => ['message' => 'transport failure'],
        ]);
        ImapBackfillWindow::create([
            'tenant_id' => app(TenantContext::class)->current(),
            'imap_backfill_id' => $backfill->id,
            'connector_installation_id' => $installation->id,
            'mailbox' => 'INBOX',
            'window_start' => '2025-01-01',
            'window_end' => '2025-02-01',
            'status' => ImapBackfillWindow::STATUS_FAILED,
            'last_uid' => 9_000,
            'processed_messages' => 9_000,
            'error_json' => ['message' => 'transport failure'],
        ]);

        $payload = $this->invoke(['installation_id' => $installation->id, 'action' => 'start']);

        $this->assertSame($backfill->id, $payload['backfill']['id']);
        $this->assertSame('running', $payload['backfill']['status']);
        $this->assertSame(9_000, $payload['backfill']['processed_messages']);
        $this->assertDatabaseCount('imap_backfills', 1);
        Queue::assertPushed(PumpImapBackfillJob::class, 1);
    }

    public function test_status_reports_the_disabled_deployment_state(): void
    {
        config()->set('connectors.imap.backfill.enabled', false);
        $installation = $this->installation(app(TenantContext::class)->current());

        $payload = $this->invoke(['installation_id' => $installation->id, 'action' => 'status']);

        $this->assertFalse($payload['enabled']);
        $this->assertNull($payload['backfill']);
    }

    private function installation(string $tenantId): ConnectorInstallation
    {
        return ConnectorInstallation::create([
            'tenant_id' => $tenantId,
            'connector_name' => 'imap',
            'label' => 'mcp-backfill-'.$tenantId,
            'config_json' => ['connection' => ['host' => 'imap.example.test', 'username' => 'mcp@example.test']],
            'status' => ConnectorInstallation::STATUS_ACTIVE,
        ]);
    }
}
