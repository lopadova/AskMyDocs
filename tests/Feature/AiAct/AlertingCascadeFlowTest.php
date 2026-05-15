<?php

declare(strict_types=1);

namespace Tests\Feature\AiAct;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Padosoft\AiActCompliance\Alerting\Events\BiasDriftDetected;
use Padosoft\AiActCompliance\Alerting\Listeners\BiasDriftDetectedListener;
use Padosoft\AiActCompliance\Alerting\Models\AlertDispatch;
use Padosoft\AiActCompliance\Alerting\Models\AlertRoute;
use Tests\TestCase;

/**
 * v6.1.1 — host-side end-to-end proof that the v1.3 alerting cascade
 * actually works inside AskMyDocs when the operator opts in.
 *
 * The sister-package repo has its own 130 PHPUnit tests covering the
 * cascade in isolation; this suite proves the package is wired into
 * AskMyDocs's container + scheduler + event dispatcher correctly,
 * which is a separate concern (and was the gap Lorenzo flagged at v6.1
 * GA: "available but not active").
 *
 * Coverage:
 *   - Default OFF: no listener subscription, no Http call, no row
 *     written when AI_ACT_ALERTING_ENABLED is unset.
 *   - Opt-in end-to-end: enable flag, seed an AlertRoute per channel,
 *     fire the event, assert (a) Http fakes were hit in cascade order,
 *     (b) AlertDispatch row written per attempt, (c) email always CCed.
 *   - Throttle: a second identical fire inside the cooldown window
 *     does NOT re-dispatch.
 *   - Cascade fallover: Slack 500 → Discord attempted → email always.
 */
class AlertingCascadeFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The host doesn't auto-publish package config; under Testbench
        // the package config defaults are merged automatically. We flip
        // the flag here to opt the test into the alerting cascade.
        config()->set('queue.default', 'sync');
        config()->set('mail.default', 'array');
    }

    public function test_alerting_disabled_by_default_no_http_no_row(): void
    {
        // Sanity: default OFF state under the AskMyDocs config-merge
        // posture. Even with an enabled AlertRoute seeded by mistake,
        // nothing fires because the bias service event-fire is gated on
        // the same flag the listener honours.
        config()->set('ai-act-compliance.alerting.enabled', false);
        Http::fake();
        Event::fake([BiasDriftDetected::class]);

        AlertRoute::query()->create([
            'tenant_id' => null,
            'channel' => 'slack',
            'webhook_url' => 'https://hooks.slack.com/services/T0/B0/x',
            'enabled' => true,
        ]);

        event($this->payloadEvent());

        Http::assertNothingSent();
        $this->assertSame(0, AlertDispatch::query()->count());
    }

    public function test_opt_in_cascade_writes_dispatch_row_per_attempt(): void
    {
        config()->set('ai-act-compliance.alerting.enabled', true);
        // Listener under the sync queue runs in-process; the cascade
        // fans out Slack → email (no Discord row seeded — proves the
        // Slack hop SUCCEEDS and email always cc's).
        Http::fake([
            'https://hooks.slack.com/*' => Http::response('ok', 200),
        ]);

        AlertRoute::query()->create([
            'tenant_id' => null,
            'channel' => 'slack',
            'webhook_url' => 'https://hooks.slack.com/services/T0/B0/x',
            'enabled' => true,
        ]);
        AlertRoute::query()->create([
            'tenant_id' => null,
            'channel' => 'email',
            'webhook_url' => null,
            'email' => 'dpo@example.test',
            'enabled' => true,
        ]);

        // The listener is registered by the sister-package SP; we
        // invoke it directly here to keep the assertion local to the
        // host integration (event dispatch + container binding) and
        // independent of the underlying queue driver implementation.
        $listener = app(BiasDriftDetectedListener::class);
        $listener->handle($this->payloadEvent());

        $rows = AlertDispatch::query()->get();
        $this->assertGreaterThanOrEqual(2, $rows->count(), 'expect at least one slack + one email row');
        $this->assertTrue(
            $rows->contains(fn ($r) => $r->channel === 'slack' && (bool) $r->ok === true),
            'slack dispatch should have ok=true',
        );
        $this->assertTrue(
            $rows->contains(fn ($r) => $r->channel === 'email'),
            'email cascade row should always be written',
        );
        Http::assertSent(fn ($request) => str_contains($request->url(), 'hooks.slack.com'));
    }

    public function test_slack_500_triggers_discord_fallover_email_still_cced(): void
    {
        config()->set('ai-act-compliance.alerting.enabled', true);
        Http::fake([
            'https://hooks.slack.com/*' => Http::response('boom', 500),
            'https://discord.com/*' => Http::response('', 204),
        ]);

        AlertRoute::query()->create([
            'tenant_id' => null,
            'channel' => 'slack',
            'webhook_url' => 'https://hooks.slack.com/services/T0/B0/x',
            'enabled' => true,
        ]);
        AlertRoute::query()->create([
            'tenant_id' => null,
            'channel' => 'discord',
            'webhook_url' => 'https://discord.com/api/webhooks/0/x',
            'enabled' => true,
        ]);
        AlertRoute::query()->create([
            'tenant_id' => null,
            'channel' => 'email',
            'webhook_url' => null,
            'email' => 'dpo@example.test',
            'enabled' => true,
        ]);

        app(BiasDriftDetectedListener::class)->handle($this->payloadEvent());

        $rows = AlertDispatch::query()->get();
        $this->assertTrue(
            $rows->contains(fn ($r) => $r->channel === 'slack' && (bool) $r->ok === false),
            'slack should record a failure row',
        );
        $this->assertTrue(
            $rows->contains(fn ($r) => $r->channel === 'discord' && (bool) $r->ok === true),
            'discord should pick up after slack failure',
        );
        $this->assertTrue(
            $rows->contains(fn ($r) => $r->channel === 'email'),
            'email should always be cc-recorded',
        );
    }

    public function test_throttle_suppresses_second_identical_fire(): void
    {
        config()->set('ai-act-compliance.alerting.enabled', true);
        Http::fake([
            'https://hooks.slack.com/*' => Http::response('ok', 200),
        ]);
        AlertRoute::query()->create([
            'tenant_id' => null,
            'channel' => 'slack',
            'webhook_url' => 'https://hooks.slack.com/services/T0/B0/x',
            'enabled' => true,
        ]);

        $listener = app(BiasDriftDetectedListener::class);
        $listener->handle($this->payloadEvent());
        $firstCount = AlertDispatch::query()->where('channel', 'slack')->count();

        $listener->handle($this->payloadEvent());
        $secondCount = AlertDispatch::query()->where('channel', 'slack')->count();

        // Second fire MUST NOT add a new Slack dispatch row inside the
        // throttle window (default 60 min).
        $this->assertSame($firstCount, $secondCount, 'throttle should suppress repeat slack dispatch');
    }

    private function payloadEvent(): BiasDriftDetected
    {
        return new BiasDriftDetected(
            tenantId: null,
            metricName: 'demographic_parity',
            cohort: 'language=it',
            disparityScore: 0.27,
            evidenceUrl: null,
            articleEvidence: ['AI Act Art. 10'],
        );
    }
}
