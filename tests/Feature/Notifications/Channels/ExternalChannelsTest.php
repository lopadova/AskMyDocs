<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications\Channels;

use App\Jobs\SendExternalNotificationJob;
use App\Models\NotificationEvent;
use App\Models\User;
use App\Notifications\Channels\DiscordChannel;
use App\Notifications\Channels\SlackChannel;
use App\Notifications\Channels\TeamsChannel;
use App\Notifications\Channels\WebhookChannel;
use App\Notifications\Events\KbDocumentChanged;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * v8.0/W2.1 — acceptance gate for the 4 external channel adapters
 * (Discord / Slack / Teams / generic Webhook).
 *
 * Covers per the plan §C.2 W2.1 acceptance gate:
 *   - unit test per ogni adapter con Http::fake() → assertion sul body POST
 *   - retry test su 503 (job is released + re-attempted with backoff)
 *   - HMAC verify test per WebhookChannel
 *
 * The channel `send()` itself dispatches {@see SendExternalNotificationJob}
 * and appends a `'queued'` log entry — the channel side is exercised
 * with `Bus::fake()` and `Bus::assertDispatched(...)`. The actual HTTP
 * body assertions hit the job's `handle()` directly with `Http::fake()`
 * so we get a deterministic round-trip without the queue worker.
 */
final class ExternalChannelsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set('default');
        // Wire all 4 external channel URLs so the registry boots
        // them and `Bus::assertDispatched` can find the calls.
        config([
            'askmydocs.notifications.channels.discord.url' => 'https://discord.com/api/webhooks/123/abc',
            'askmydocs.notifications.channels.slack.url' => 'https://hooks.slack.com/services/T/B/X',
            'askmydocs.notifications.channels.teams.url' => 'https://example.webhook.office.com/webhookb2/abc/IncomingWebhook/xyz/123',
            'askmydocs.notifications.channels.webhook.url' => 'https://example.test/inbox',
            'askmydocs.notifications.channels.webhook.secret' => 'shared-secret-32bytes-for-hmac-sha256',
            'askmydocs.notifications.hmac_secret' => 'fixed-test-secret-for-deterministic-tokens',
        ]);
    }

    public function test_discord_channel_dispatches_job_with_embed_payload(): void
    {
        Bus::fake();

        $row = $this->makeRow();
        $user = $this->makeUser('discord-tester');
        $event = $this->makeEvent(['slug' => 'dec-cache-v2', 'project_key' => 'proj-d']);

        (new DiscordChannel())->send($event, $user, $row);

        Bus::assertDispatched(SendExternalNotificationJob::class, function (SendExternalNotificationJob $job) use ($row): bool {
            return $job->channelName === 'discord'
                && $job->eventRowId === (int) $row->id
                && $job->url === 'https://discord.com/api/webhooks/123/abc'
                && $job->hmacSecret === null
                && isset($job->payload['embeds'])
                && is_array($job->payload['embeds'])
                && count($job->payload['embeds']) === 1
                && $job->payload['embeds'][0]['color'] === 0x6F42C1
                && str_contains((string) $job->payload['embeds'][0]['description'], 'dec-cache-v2');
        });

        $row->refresh();
        $statuses = array_combine(
            array_column($row->channel_dispatch_log, 'channel'),
            array_column($row->channel_dispatch_log, 'status'),
        );
        $this->assertSame('queued', $statuses['discord'] ?? null);
    }

    public function test_slack_channel_dispatches_job_with_block_kit_payload(): void
    {
        Bus::fake();

        $row = $this->makeRow();
        $user = $this->makeUser('slack-tester');
        $event = $this->makeEvent(['slug' => 'dec-cache-v3', 'project_key' => 'proj-s']);

        (new SlackChannel())->send($event, $user, $row);

        Bus::assertDispatched(SendExternalNotificationJob::class, function (SendExternalNotificationJob $job): bool {
            if ($job->channelName !== 'slack') {
                return false;
            }
            if (! isset($job->payload['blocks']) || ! is_array($job->payload['blocks'])) {
                return false;
            }
            $blockTypes = array_column($job->payload['blocks'], 'type');
            return in_array('header', $blockTypes, true) && in_array('section', $blockTypes, true);
        });
    }

    public function test_teams_channel_dispatches_job_with_adaptive_card_envelope(): void
    {
        Bus::fake();

        $row = $this->makeRow();
        $user = $this->makeUser('teams-tester');
        $event = $this->makeEvent(['slug' => 'dec-cache-v4', 'project_key' => 'proj-t']);

        (new TeamsChannel())->send($event, $user, $row);

        Bus::assertDispatched(SendExternalNotificationJob::class, function (SendExternalNotificationJob $job): bool {
            return $job->channelName === 'teams'
                && ($job->payload['type'] ?? null) === 'message'
                && isset($job->payload['attachments'][0]['contentType'])
                && $job->payload['attachments'][0]['contentType'] === 'application/vnd.microsoft.card.adaptive'
                && ($job->payload['attachments'][0]['content']['type'] ?? null) === 'AdaptiveCard'
                && ($job->payload['attachments'][0]['content']['version'] ?? null) === '1.4';
        });
    }

    public function test_webhook_channel_dispatches_job_with_envelope_and_hmac_secret(): void
    {
        Bus::fake();

        $row = $this->makeRow();
        $user = $this->makeUser('webhook-tester');
        $event = $this->makeEvent(['slug' => 'dec-cache-v5', 'project_key' => 'proj-w']);

        (new WebhookChannel())->send($event, $user, $row);

        Bus::assertDispatched(SendExternalNotificationJob::class, function (SendExternalNotificationJob $job) use ($user): bool {
            return $job->channelName === 'webhook'
                && $job->hmacSecret === 'shared-secret-32bytes-for-hmac-sha256'
                && ($job->payload['event_type'] ?? null) === NotificationEvent::EVENT_KB_DOC_CREATED
                && ($job->payload['tenant_id'] ?? null) === 'default'
                && ($job->payload['user_id'] ?? null) === $user->id
                && ($job->payload['user_email'] ?? null) === $user->email
                && is_array($job->payload['payload'])
                && ($job->payload['payload']['slug'] ?? null) === 'dec-cache-v5';
        });
    }

    public function test_unconfigured_channel_appends_skipped_log_and_does_not_dispatch_job(): void
    {
        Bus::fake();

        // Reset Discord URL to empty — channel should skip + log.
        config(['askmydocs.notifications.channels.discord.url' => '']);

        $row = $this->makeRow();
        $user = $this->makeUser('discord-skip');
        $event = $this->makeEvent(['slug' => 'x']);

        (new DiscordChannel())->send($event, $user, $row);

        Bus::assertNotDispatched(SendExternalNotificationJob::class);

        $row->refresh();
        $statuses = array_combine(
            array_column($row->channel_dispatch_log, 'channel'),
            array_column($row->channel_dispatch_log, 'status'),
        );
        $this->assertSame('skipped', $statuses['discord'] ?? null);
    }

    public function test_send_external_notification_job_posts_payload_and_records_delivered(): void
    {
        Http::fake([
            'https://discord.com/*' => Http::response('', 204),
        ]);

        $row = $this->makeRow();
        $job = new SendExternalNotificationJob(
            channelName: 'discord',
            eventRowId: (int) $row->id,
            url: 'https://discord.com/api/webhooks/123/abc',
            payload: ['embeds' => [['title' => 't', 'description' => 'd']]],
        );
        $job->handle();

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://discord.com/api/webhooks/123/abc'
                && $request->method() === 'POST'
                && ($request->data()['embeds'][0]['title'] ?? null) === 't';
        });

        $row->refresh();
        $statuses = array_combine(
            array_column($row->channel_dispatch_log, 'channel'),
            array_column($row->channel_dispatch_log, 'status'),
        );
        $this->assertSame('delivered', $statuses['discord'] ?? null);
    }

    public function test_send_external_notification_job_signs_request_with_hmac_when_secret_present(): void
    {
        Http::fake([
            'https://example.test/*' => Http::response('', 200),
        ]);

        $row = $this->makeRow();
        $payload = ['event_type' => 'kb.doc.created', 'tenant_id' => 'default', 'payload' => ['x' => 'y']];
        $secret = 'shared-secret-32bytes-for-hmac-sha256';
        $job = new SendExternalNotificationJob(
            channelName: 'webhook',
            eventRowId: (int) $row->id,
            url: 'https://example.test/inbox',
            payload: $payload,
            hmacSecret: $secret,
        );
        $job->handle();

        Http::assertSent(function ($request) use ($payload, $secret): bool {
            $expected = 'sha256='.hash_hmac('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES), $secret);
            return $request->hasHeader('X-AskMyDocs-Signature', $expected);
        });
    }

    public function test_send_external_notification_job_throws_on_5xx_to_trigger_retry(): void
    {
        Http::fake([
            'https://slack.example/*' => Http::response('upstream down', 503),
        ]);

        $row = $this->makeRow();
        $job = new SendExternalNotificationJob(
            channelName: 'slack',
            eventRowId: (int) $row->id,
            url: 'https://slack.example/hook',
            payload: ['text' => 'x'],
        );

        $this->expectException(\RuntimeException::class);
        try {
            $job->handle();
        } finally {
            // Channel must NOT be logged as 'failed' yet — retry is
            // still in flight. The 'failed' entry only lands when
            // failed() fires after $tries is exhausted.
            $row->refresh();
            $channels = array_column($row->channel_dispatch_log ?? [], 'channel');
            $this->assertNotContains('slack', $channels);
        }
    }

    public function test_send_external_notification_job_records_failed_for_non_retryable_4xx(): void
    {
        Http::fake([
            'https://discord.example/*' => Http::response('bad request', 400),
        ]);

        $row = $this->makeRow();
        $job = new SendExternalNotificationJob(
            channelName: 'discord',
            eventRowId: (int) $row->id,
            url: 'https://discord.example/hook',
            payload: ['embeds' => []],
        );
        $job->handle();

        $row->refresh();
        $statuses = array_combine(
            array_column($row->channel_dispatch_log, 'channel'),
            array_column($row->channel_dispatch_log, 'status'),
        );
        $this->assertSame('failed', $statuses['discord'] ?? null);
    }

    public function test_send_external_notification_job_failed_callback_records_terminal_error(): void
    {
        $row = $this->makeRow();
        $job = new SendExternalNotificationJob(
            channelName: 'teams',
            eventRowId: (int) $row->id,
            url: 'https://teams.example/hook',
            payload: ['type' => 'message'],
        );
        $job->failed(new \RuntimeException('upstream dead after all retries'));

        $row->refresh();
        $statuses = array_combine(
            array_column($row->channel_dispatch_log, 'channel'),
            array_column($row->channel_dispatch_log, 'status'),
        );
        $errors = array_column($row->channel_dispatch_log, 'error');
        $this->assertSame('failed', $statuses['teams'] ?? null);
        $this->assertNotEmpty(array_filter($errors, fn (?string $e) => $e !== null && str_contains($e, 'retries exhausted')));
    }

    public function test_helper_preserves_existing_log_entries_across_concurrent_appends(): void
    {
        // Lost-update regression: simulate the W1.3 in-memory write
        // pattern (channel A) sandwiched around the job-driven
        // append (channel B reading the fresh DB row). All entries
        // must survive. This is the bug Copilot caught on PR #195
        // round-1.
        $row = $this->makeRow();

        // 1. Channel A writes via the legacy in-memory pattern.
        $row->channel_dispatch_log = [
            ['channel' => 'in_app', 'status' => 'delivered', 'at' => now()->toIso8601String()],
        ];
        $row->save();

        // 2. The job (channel B) runs and appends through the atomic
        //    helper.
        \App\Notifications\NotificationEventLogger::append(
            eventRowId: (int) $row->id,
            channel: 'discord',
            status: 'delivered',
        );

        // 3. Channel C (e.g. AbstractWebhookChannel.appendLog called
        //    after dispatch under sync) also appends through the
        //    helper, passing in the stale in-memory $row.
        \App\Notifications\NotificationEventLogger::append(
            eventRowId: (int) $row->id,
            channel: 'discord',
            status: 'queued',
            inMemoryRow: $row,
        );

        // 4. Final DB state has ALL 3 entries.
        $fresh = NotificationEvent::find($row->id);
        $channels = array_column($fresh->channel_dispatch_log, 'channel');
        $statuses = array_column($fresh->channel_dispatch_log, 'status');
        $this->assertCount(3, $fresh->channel_dispatch_log);
        $this->assertSame(['in_app', 'discord', 'discord'], $channels);
        $this->assertSame(['delivered', 'delivered', 'queued'], $statuses);

        // 5. The in-memory $row that was passed to step 3 was
        //    refreshed in-place so callers reading from it see the
        //    full log too (defense-in-depth against stale state).
        $this->assertCount(3, $row->channel_dispatch_log);
    }

    public function test_provider_only_registers_channels_with_a_url(): void
    {
        // Wipe a channel URL, re-bind provider so the channel skips
        // registration. We assert against the live ChannelRegistry.
        config(['askmydocs.notifications.channels.discord.url' => '']);

        $registry = $this->app->make(\App\Notifications\ChannelRegistry::class);
        // The registry is a singleton seeded by NotificationServiceProvider
        // in boot(). Re-running boot is not exposed by Laravel; instead
        // we forget the instance and let the provider re-boot on
        // resolve.
        $this->app->forgetInstance(\App\Notifications\ChannelRegistry::class);
        $this->app->forgetInstance(\App\Notifications\NotificationDispatcher::class);
        /** @var \App\Providers\NotificationServiceProvider $provider */
        $provider = $this->app->getProvider(\App\Providers\NotificationServiceProvider::class);
        // Re-resolve the registry through the singleton; provider
        // boot does not re-run, so we manually register channels via
        // reflection of the provider's helper. Easier path: invoke
        // the provider's boot once on a fresh registry.
        $registry = $this->app->make(\App\Notifications\ChannelRegistry::class);
        $registry->register(new \App\Notifications\Channels\InAppChannel());
        $registry->register(new \App\Notifications\Channels\EmailChannel());
        $ref = new \ReflectionMethod($provider, 'registerExternalChannels');
        $ref->setAccessible(true);
        $ref->invoke($provider, $registry);

        $registered = $registry->registered();
        $this->assertContains('slack', $registered);
        $this->assertContains('teams', $registered);
        $this->assertContains('webhook', $registered);
        $this->assertNotContains('discord', $registered);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function makeEvent(array $payload): KbDocumentChanged
    {
        return new KbDocumentChanged(
            recipients: [],
            payload: $payload + ['change' => 'created'],
            tenantId: 'default',
        );
    }

    private function makeRow(): NotificationEvent
    {
        return NotificationEvent::create([
            'event_type' => NotificationEvent::EVENT_KB_DOC_CREATED,
            'payload' => ['change' => 'created'],
            'channel_dispatch_log' => [],
        ]);
    }

    private function makeUser(string $slug): User
    {
        return User::create([
            'name' => "external-channel-{$slug}",
            'email' => "{$slug}-".uniqid('', true).'@test.local',
            'password' => Hash::make('secret123'),
        ]);
    }
}
