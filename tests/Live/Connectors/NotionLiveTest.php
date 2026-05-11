<?php

declare(strict_types=1);

namespace Tests\Live\Connectors;

use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

/**
 * Notion live test — hits api.notion.com when CONNECTOR_NOTION_LIVE=1.
 *
 * Operators run this manually (or via the
 * `live-recording-nightly.yml` workflow_dispatch) to record real
 * Notion response shapes into the fixtures directory. The recorded
 * fixtures are committed to the repo and serve as the chunker test
 * input source.
 *
 * See docs/v4-platform/RUNBOOK-live-fixture-recording.md for the
 * step-by-step Notion integration setup.
 */
final class NotionLiveTest extends LiveConnectorTestCase
{
    protected static function gateEnvVar(): string
    {
        return 'CONNECTOR_NOTION_LIVE';
    }

    protected static function requiredCredentialEnvVars(): array
    {
        return ['CONNECTOR_NOTION_TOKEN'];
    }

    protected static function providerSlug(): string
    {
        return 'notion';
    }

    #[Test]
    public function lists_users_via_real_api(): void
    {
        $response = Http::withToken((string) getenv('CONNECTOR_NOTION_TOKEN'))
            ->withHeaders(['Notion-Version' => '2022-06-28'])
            ->timeout(10)
            ->get('https://api.notion.com/v1/users');

        $this->assertTrue($response->successful(), 'Notion /v1/users returned non-2xx: ' . $response->status());
        $json = $response->json();
        $this->assertIsArray($json);
        $this->assertArrayHasKey('results', $json);
    }
}
