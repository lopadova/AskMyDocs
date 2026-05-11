<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Connectors\BuiltIn\FabricConnector;
use App\Connectors\Exceptions\ConnectorAuthException;
use App\Connectors\HealthStatus;
use App\Jobs\IngestDocumentJob;
use App\Models\ConnectorInstallation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * v4.5/W4 — FabricConnector (fabric.so) tests.
 *
 * Fabric.so currently uses API-key auth (X-Api-Key header); OAuth2 is
 * documented as "coming soon" upstream. The connector therefore:
 *   - throws a ConnectorAuthException with an actionable message when
 *     initiateOAuth() is called while oauth_enabled=false (the default);
 *   - reads the API key from the installation's `config_json.api_key`
 *     OR from `CONNECTOR_FABRIC_API_KEY` env (development convenience);
 *   - walks `/v2/notes` for full + incremental sync via X-Api-Key.
 */
final class FabricConnectorTest extends TestCase
{
    use RefreshDatabase;

    private function connector(): FabricConnector
    {
        return $this->app->make(FabricConnector::class);
    }

    private function makeInstallation(array $config = [], string $tenantId = 'default'): ConnectorInstallation
    {
        $user = User::create([
            'name' => 'Tester',
            'email' => 'u-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);

        return ConnectorInstallation::create([
            'tenant_id' => $tenantId,
            'connector_name' => 'fabric',
            'status' => ConnectorInstallation::STATUS_ACTIVE,
            'created_by' => $user->id,
            'config_json' => $config === [] ? null : $config,
        ]);
    }

    public function test_key_and_display_name(): void
    {
        $this->assertSame('fabric', $this->connector()->key());
        $this->assertSame('Fabric', $this->connector()->displayName());
    }

    public function test_oauth_scopes_are_empty_until_upstream_oauth_is_GA(): void
    {
        $this->assertSame([], $this->connector()->oauthScopes());
    }

    public function test_icon_url_resolves_to_existing_file(): void
    {
        $url = $this->connector()->iconUrl();
        $this->assertStringEndsWith('/connectors/fabric.svg', $url);

        $repoRoot = realpath(__DIR__.'/../../../');
        $this->assertNotFalse($repoRoot);
        $this->assertFileExists($repoRoot.'/public/connectors/fabric.svg');
    }

    public function test_initiate_oauth_throws_when_upstream_oauth_is_disabled(): void
    {
        config()->set('connectors.providers.fabric.oauth_enabled', false);

        $installation = $this->makeInstallation();

        $this->expectException(ConnectorAuthException::class);
        $this->expectExceptionMessageMatches('/coming soon|API Key|oauth_enabled/i');

        $this->connector()->initiateOAuth($installation->id);
    }

    public function test_initiate_oauth_returns_authorize_url_when_enabled(): void
    {
        config()->set('connectors.providers.fabric.oauth_enabled', true);
        config()->set('connectors.providers.fabric.client_id', 'cid');
        config()->set('connectors.providers.fabric.redirect_uri', 'http://localhost/cb');

        $installation = $this->makeInstallation();

        $url = $this->connector()->initiateOAuth($installation->id);
        $this->assertStringContainsString('client_id=cid', $url);
        $this->assertStringContainsString('state=', $url);
    }

    public function test_sync_full_walks_notes_endpoint_and_ingests_each(): void
    {
        Queue::fake();
        Storage::fake('kb');

        $installation = $this->makeInstallation(['api_key' => 'PK-fabric']);

        Http::fake([
            'api.fabric.so/v2/notes*' => Http::sequence()
                ->push([
                    'notes' => [
                        [
                            'id' => 'fab-1',
                            'title' => 'Fabric note one',
                            'content_markdown' => 'first body',
                            'workspace_id' => 'ws-1',
                            'updated_at' => '2026-05-10T10:00:00Z',
                        ],
                        [
                            'id' => 'fab-2',
                            'title' => 'Fabric note two',
                            'content_markdown' => 'second body',
                            'workspace_id' => 'ws-1',
                        ],
                    ],
                    'next_cursor' => null,
                ], 200),
        ]);

        $result = $this->connector()->syncFull($installation->id);

        $this->assertSame([], $result->errors);
        $this->assertSame(2, $result->documentsAdded);
        Queue::assertPushed(IngestDocumentJob::class, 2);

        // Confirm the X-Api-Key header was set on the outgoing request.
        Http::assertSent(function ($req) {
            return $req->hasHeader('X-Api-Key', 'PK-fabric');
        });
    }

    public function test_sync_full_throws_auth_exception_on_401(): void
    {
        Queue::fake();
        Storage::fake('kb');

        $installation = $this->makeInstallation(['api_key' => 'bad-key']);

        Http::fake([
            'api.fabric.so/v2/notes*' => Http::response(['error' => 'unauthorized'], 401),
        ]);

        $this->expectException(ConnectorAuthException::class);
        $this->connector()->syncFull($installation->id);
    }

    public function test_sync_full_throws_when_no_api_key_configured(): void
    {
        config()->set('connectors.providers.fabric.api_key', null);

        $installation = $this->makeInstallation();

        $this->expectException(ConnectorAuthException::class);
        $this->expectExceptionMessageMatches('/no fabric api key/i');

        $this->connector()->syncFull($installation->id);
    }

    public function test_sync_full_paginates_via_next_cursor(): void
    {
        Queue::fake();
        Storage::fake('kb');

        $installation = $this->makeInstallation(['api_key' => 'PK-fabric']);

        Http::fake([
            'api.fabric.so/v2/notes*' => Http::sequence()
                ->push([
                    'notes' => [['id' => 'fab-a', 'title' => 'A', 'content_markdown' => 'aa']],
                    'next_cursor' => 'cursor-2',
                ], 200)
                ->push([
                    'notes' => [['id' => 'fab-b', 'title' => 'B', 'content_markdown' => 'bb']],
                    'next_cursor' => null,
                ], 200),
        ]);

        $result = $this->connector()->syncFull($installation->id);

        $this->assertSame(2, $result->documentsAdded);
        Queue::assertPushed(IngestDocumentJob::class, 2);
    }

    public function test_sync_full_dispatches_with_workspace_header_when_configured(): void
    {
        Queue::fake();
        Storage::fake('kb');

        $installation = $this->makeInstallation([
            'api_key' => 'DK-fabric',
            'workspace_id' => 'ws-42',
        ]);

        Http::fake([
            'api.fabric.so/v2/notes*' => Http::response([
                'notes' => [],
                'next_cursor' => null,
            ], 200),
        ]);

        $this->connector()->syncFull($installation->id);

        Http::assertSent(function ($req) {
            return $req->hasHeader('X-Api-Key', 'DK-fabric')
                && $req->hasHeader('X-Fabric-Workspace-Id', 'ws-42');
        });
    }

    public function test_sync_incremental_falls_back_to_full_when_no_watermark(): void
    {
        Queue::fake();
        Storage::fake('kb');

        $installation = $this->makeInstallation(['api_key' => 'PK-fabric']);

        Http::fake([
            'api.fabric.so/v2/notes*' => Http::response([
                'notes' => [],
                'next_cursor' => null,
            ], 200),
        ]);

        $result = $this->connector()->syncIncremental($installation->id, null);

        $this->assertSame(0, $result->documentsAdded);
    }

    public function test_health_pings_users_me_with_api_key_header(): void
    {
        $installation = $this->makeInstallation(['api_key' => 'PK-fabric']);

        Http::fake([
            'api.fabric.so/v2/users/me' => Http::response(['id' => 'u-1'], 200),
        ]);

        $status = $this->connector()->health($installation->id);
        $this->assertSame(HealthStatus::STATE_HEALTHY, $status->state);

        Http::assertSent(function ($req) {
            return str_contains((string) $req->url(), '/v2/users/me')
                && $req->hasHeader('X-Api-Key', 'PK-fabric');
        });
    }

    public function test_health_returns_errored_when_no_api_key(): void
    {
        config()->set('connectors.providers.fabric.api_key', null);

        $installation = $this->makeInstallation();

        $status = $this->connector()->health($installation->id);
        $this->assertSame(HealthStatus::STATE_ERRORED, $status->state);
    }

    public function test_disconnect_clears_credentials_without_provider_revoke_call(): void
    {
        $installation = $this->makeInstallation(['api_key' => 'PK-fabric']);

        Http::fake();

        $this->connector()->disconnect($installation->id);

        // Fabric has no programmatic revoke endpoint, so no HTTP call.
        Http::assertNothingSent();
    }
}
