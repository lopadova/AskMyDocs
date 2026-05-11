<?php

declare(strict_types=1);

namespace Tests\Live\Connectors;

use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

final class GoogleDriveLiveTest extends LiveConnectorTestCase
{
    protected static function gateEnvVar(): string
    {
        return 'CONNECTOR_GOOGLE_DRIVE_LIVE';
    }

    protected static function requiredCredentialEnvVars(): array
    {
        return ['CONNECTOR_GOOGLE_DRIVE_TOKEN'];
    }

    protected static function providerSlug(): string
    {
        return 'google_drive';
    }

    #[Test]
    public function lists_files_via_real_api(): void
    {
        $response = Http::withToken((string) getenv('CONNECTOR_GOOGLE_DRIVE_TOKEN'))
            ->timeout(10)
            ->get('https://www.googleapis.com/drive/v3/files', [
                'pageSize' => 5,
                'fields'   => 'files(id,name,mimeType,modifiedTime,owners)',
            ]);

        $this->assertTrue($response->successful(), 'Drive /v3/files returned: ' . $response->status());
        $this->assertArrayHasKey('files', $response->json() ?? []);
    }
}
