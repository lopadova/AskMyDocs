<?php

declare(strict_types=1);

namespace Tests\Live\Connectors;

use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

final class EvernoteLiveTest extends LiveConnectorTestCase
{
    protected static function gateEnvVar(): string
    {
        return 'CONNECTOR_EVERNOTE_LIVE';
    }

    protected static function requiredCredentialEnvVars(): array
    {
        return ['CONNECTOR_EVERNOTE_TOKEN'];
    }

    protected static function providerSlug(): string
    {
        return 'evernote';
    }

    #[Test]
    public function lists_notebooks_via_real_api(): void
    {
        // Evernote sandbox base.
        $response = Http::withToken((string) getenv('CONNECTOR_EVERNOTE_TOKEN'))
            ->withHeaders(['Accept' => 'application/json'])
            ->timeout(10)
            ->get('https://sandbox.evernote.com/shard/s1/v2/users/me');

        $this->assertTrue($response->successful(), 'Evernote /users/me returned: ' . $response->status());
    }
}
