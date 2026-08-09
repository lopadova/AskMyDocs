<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Jobs\SendDigestWebhookJob;
use App\Jobs\SendExternalNotificationJob;
use App\Models\NotificationEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Proves the SSRF guard is wired into BOTH outbound webhook sinks (SEC-SSRF-001,
 * R26): an internal target sends NOTHING on the wire, a public target sends. The
 * short-circuit is proved with Http::assertNothingSent() — both sinks issue
 * their request through Laravel's HTTP client, so faking it captures every send.
 */
class WebhookSsrfGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_digest_job_blocks_internal_target(): void
    {
        Http::fake();

        (new SendDigestWebhookJob(
            channelName: 'slack',
            tenantId: 'default',
            url: 'https://169.254.169.254/latest/meta-data',
            payload: ['text' => 'x'],
        ))->handle();

        Http::assertNothingSent();
    }

    public function test_digest_job_sends_to_public_target(): void
    {
        Http::fake(['*' => Http::response('', 200)]);

        (new SendDigestWebhookJob(
            channelName: 'slack',
            tenantId: 'default',
            url: 'https://93.184.216.34/webhook',
            payload: ['text' => 'x'],
        ))->handle();

        Http::assertSent(fn ($request) => str_contains($request->url(), '93.184.216.34'));
    }

    public function test_external_notification_job_blocks_internal_target(): void
    {
        Http::fake();

        $row = NotificationEvent::create([
            'tenant_id' => 'default',
            'event_type' => 'test.event',
            'payload' => ['a' => 1],
        ]);

        (new SendExternalNotificationJob(
            channelName: 'webhook',
            eventRowId: (int) $row->id,
            tenantId: 'default',
            // https so the block is on the resolved-internal-IP path, not merely
            // the https-only scheme rule.
            url: 'https://127.0.0.1:9200/_bulk',
            payload: ['text' => 'x'],
        ))->handle();

        Http::assertNothingSent();

        $row->refresh();
        $log = $row->channel_dispatch_log ?? [];
        $statuses = array_column(is_array($log) ? $log : [], 'status');
        $this->assertContains('failed', $statuses, 'blocked send must be logged failed, not retried');
    }
}
