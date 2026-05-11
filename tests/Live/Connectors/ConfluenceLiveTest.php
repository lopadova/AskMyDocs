<?php

declare(strict_types=1);

namespace Tests\Live\Connectors;

use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

final class ConfluenceLiveTest extends LiveConnectorTestCase
{
    protected static function gateEnvVar(): string
    {
        return 'CONNECTOR_CONFLUENCE_LIVE';
    }

    protected static function requiredCredentialEnvVars(): array
    {
        return ['CONNECTOR_CONFLUENCE_TOKEN', 'CONNECTOR_CONFLUENCE_CLOUD_ID'];
    }

    protected static function providerSlug(): string
    {
        return 'confluence';
    }

    #[Test]
    public function lists_spaces_via_real_api(): void
    {
        $cloudId = (string) getenv('CONNECTOR_CONFLUENCE_CLOUD_ID');
        $url = "https://api.atlassian.com/ex/confluence/{$cloudId}/wiki/api/v2/spaces";

        $response = Http::withToken((string) getenv('CONNECTOR_CONFLUENCE_TOKEN'))
            ->withHeaders(['Accept' => 'application/json'])
            ->timeout(10)
            ->get($url, ['limit' => 5]);

        $this->assertTrue($response->successful(), 'Confluence /spaces returned: ' . $response->status());
        $this->assertArrayHasKey('results', $response->json() ?? []);
    }
}
