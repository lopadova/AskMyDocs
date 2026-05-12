<?php

declare(strict_types=1);

namespace Tests\Live\Connectors;

use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

final class FabricLiveTest extends LiveConnectorTestCase
{
    protected static function gateEnvVar(): string
    {
        return 'CONNECTOR_FABRIC_LIVE';
    }

    protected static function requiredCredentialEnvVars(): array
    {
        return ['CONNECTOR_FABRIC_API_KEY', 'CONNECTOR_FABRIC_WORKSPACE_ID'];
    }

    protected static function providerSlug(): string
    {
        return 'fabric';
    }

    #[Test]
    public function lists_notes_via_real_api(): void
    {
        $base = (string) (getenv('CONNECTOR_FABRIC_API_BASE') ?: 'https://api.fabric.so/v1');

        $response = Http::withHeaders([
            'X-Api-Key' => (string) getenv('CONNECTOR_FABRIC_API_KEY'),
            'X-Fabric-Workspace-Id' => (string) getenv('CONNECTOR_FABRIC_WORKSPACE_ID'),
            'Accept' => 'application/json',
        ])
            ->timeout(10)
            ->get("{$base}/notes", ['limit' => 5]);

        $this->assertTrue($response->successful(), 'Fabric /notes returned: ' . $response->status());
    }
}
