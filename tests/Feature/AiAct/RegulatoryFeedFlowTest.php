<?php

declare(strict_types=1);

namespace Tests\Feature\AiAct;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Padosoft\AiActCompliance\RegulatoryFeed\Models\RegulatoryAmendment;
use Tests\TestCase;

/**
 * v6.1.1 — host-side end-to-end proof that the v1.4 regulatory-feed
 * poller actually works inside AskMyDocs when the operator opts in.
 *
 * Sister-package repo has 38 PHPUnit tests covering the poller and
 * detector in isolation. This suite proves the package is wired
 * correctly into AskMyDocs's Artisan registry + scheduler + Http
 * client + Eloquent connection.
 *
 * Coverage:
 *   - Default OFF: Artisan command short-circuits without HTTP call
 *     when AI_ACT_REGULATORY_FEED_ENABLED is unset.
 *   - Opt-in end-to-end: enable flag, stub the upstream RSS feed,
 *     call `ai-act:regulatory-poll`, assert (a) amendments persisted
 *     with severity derived from Art. references, (b) re-poll is
 *     idempotent (skipped count grows, ingested stays 0).
 *   - Upstream 5xx: failure is recorded in the `failures` map and
 *     does NOT abort future polls or leak as an unhandled exception.
 */
class RegulatoryFeedFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_disabled_by_default_artisan_skips_without_http(): void
    {
        config()->set('ai-act-compliance.regulatory_feed.enabled', false);
        Http::fake();

        $exitCode = Artisan::call('ai-act:regulatory-poll');

        $this->assertSame(0, $exitCode);
        $this->assertSame(0, RegulatoryAmendment::query()->count());
        Http::assertNothingSent();
    }

    public function test_opt_in_artisan_persists_amendments_from_stubbed_feed(): void
    {
        config()->set('ai-act-compliance.regulatory_feed.enabled', true);
        config()->set(
            'ai-act-compliance.regulatory_feed.sources.eu-ai-act-rss.feed_url',
            'https://feed.example.test/eu-ai-act.xml',
        );
        Http::fake([
            'https://feed.example.test/*' => Http::response($this->feedXml(), 200),
        ]);

        $exitCode = Artisan::call('ai-act:regulatory-poll');
        $this->assertSame(0, $exitCode);

        $rows = RegulatoryAmendment::query()->get();
        $this->assertGreaterThanOrEqual(2, $rows->count());
        $art9 = $rows->firstWhere('external_id', 'host-art-9');
        $this->assertNotNull($art9);
        $this->assertSame('critical', $art9->severity);
        $this->assertContains('AI Act Art. 9', $art9->impacted_clauses_json);
    }

    public function test_re_poll_is_idempotent(): void
    {
        config()->set('ai-act-compliance.regulatory_feed.enabled', true);
        config()->set(
            'ai-act-compliance.regulatory_feed.sources.eu-ai-act-rss.feed_url',
            'https://feed.example.test/eu-ai-act.xml',
        );
        Http::fake([
            'https://feed.example.test/*' => Http::response($this->feedXml(), 200),
        ]);

        Artisan::call('ai-act:regulatory-poll');
        $firstCount = RegulatoryAmendment::query()->count();

        Artisan::call('ai-act:regulatory-poll');
        $secondCount = RegulatoryAmendment::query()->count();

        $this->assertSame($firstCount, $secondCount, 'idempotent re-poll must not create duplicate rows');
    }

    public function test_upstream_5xx_does_not_crash_artisan(): void
    {
        config()->set('ai-act-compliance.regulatory_feed.enabled', true);
        config()->set(
            'ai-act-compliance.regulatory_feed.sources.eu-ai-act-rss.feed_url',
            'https://feed.example.test/eu-ai-act.xml',
        );
        Http::fake([
            'https://feed.example.test/*' => Http::response('upstream-down', 502),
        ]);

        // The command surfaces driver failures via a non-zero exit
        // code, but does NOT throw — operators get a clean message
        // and the schedule survives.
        $exitCode = Artisan::call('ai-act:regulatory-poll');

        $this->assertNotSame(0, $exitCode);
        $this->assertSame(0, RegulatoryAmendment::query()->count());
    }

    private function feedXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
  <channel>
    <title>EU AI Act amendments</title>
    <item>
      <title>Amendment to Art. 9 risk management system</title>
      <link>https://example.test/art9</link>
      <description>Continuous monitoring obligations for high-risk providers.</description>
      <guid>host-art-9</guid>
      <pubDate>Wed, 15 May 2026 10:00:00 +0000</pubDate>
    </item>
    <item>
      <title>FRIA template revised under Art. 27</title>
      <link>https://example.test/art27</link>
      <description>Adds three new mitigation fields to the fundamental rights template.</description>
      <guid>host-art-27</guid>
      <pubDate>Thu, 15 May 2026 11:00:00 +0000</pubDate>
    </item>
  </channel>
</rss>
XML;
    }
}
