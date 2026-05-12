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
    public function gets_authenticated_user_via_real_api(): void
    {
        // Evernote sandbox base. The /shard/s1/v2/users/me REST shim
        // returns the authenticated user profile — the lightest-weight
        // call we can make as a connectivity + auth smoke. Evernote's
        // production "list notebooks" surface is Thrift-only; the
        // sandbox does NOT expose a REST shim for it, so this test
        // intentionally targets users/me. R16: the test name
        // ("gets_authenticated_user") matches the call it makes.
        $response = Http::withToken((string) getenv('CONNECTOR_EVERNOTE_TOKEN'))
            ->withHeaders(['Accept' => 'application/json'])
            ->timeout(10)
            ->get('https://sandbox.evernote.com/shard/s1/v2/users/me');

        $this->assertTrue($response->successful(), 'Evernote /users/me returned: ' . $response->status());
    }
}
