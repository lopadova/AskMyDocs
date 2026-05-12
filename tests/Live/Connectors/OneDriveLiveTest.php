<?php

declare(strict_types=1);

namespace Tests\Live\Connectors;

use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

final class OneDriveLiveTest extends LiveConnectorTestCase
{
    protected static function gateEnvVar(): string
    {
        return 'CONNECTOR_ONEDRIVE_LIVE';
    }

    protected static function requiredCredentialEnvVars(): array
    {
        return ['CONNECTOR_ONEDRIVE_TOKEN'];
    }

    protected static function providerSlug(): string
    {
        return 'onedrive';
    }

    #[Test]
    public function lists_drive_root_via_real_api(): void
    {
        $response = Http::withToken((string) getenv('CONNECTOR_ONEDRIVE_TOKEN'))
            ->timeout(10)
            ->get('https://graph.microsoft.com/v1.0/me/drive/root/children', ['$top' => 5]);

        $this->assertTrue($response->successful(), 'Graph /me/drive/root/children returned: ' . $response->status());
        $this->assertArrayHasKey('value', $response->json() ?? []);
    }
}
