<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Connectors\BuiltIn\GoogleDriveConnector;
use App\Connectors\Exceptions\ConnectorAuthException;
use App\Connectors\HealthStatus;
use App\Jobs\IngestDocumentJob;
use App\Models\ConnectorCredential;
use App\Models\ConnectorInstallation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * v4.5/W1 — GoogleDriveConnector behaviour: OAuth flow, sync (full +
 * incremental), disconnect (revoke + clear), health probe.
 *
 * Every Google API interaction is stubbed via Http::fake so the
 * tests run without network access. The connector talks to Google
 * via raw `Http::` (CLAUDE.md: no AI/vendor SDKs).
 */
final class GoogleDriveConnectorTest extends TestCase
{
    use RefreshDatabase;

    private function connector(): GoogleDriveConnector
    {
        return $this->app->make(GoogleDriveConnector::class);
    }

    private function makeInstallation(string $tenantId = 'default'): ConnectorInstallation
    {
        $user = User::create([
            'name' => 'Tester',
            'email' => 'u-'.uniqid().'@demo.local',
            'password' => Hash::make('secret123'),
        ]);

        return ConnectorInstallation::create([
            'tenant_id' => $tenantId,
            'connector_name' => 'google-drive',
            'status' => ConnectorInstallation::STATUS_PENDING,
            'created_by' => $user->id,
        ]);
    }

    public function test_initiate_oauth_returns_google_auth_url_with_correct_scopes(): void
    {
        config()->set('connectors.providers.google-drive.client_id', 'test-client-id');
        config()->set('connectors.providers.google-drive.redirect_uri', 'http://localhost/cb');

        $installation = $this->makeInstallation();

        $url = $this->connector()->initiateOAuth($installation->id);

        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('test-client-id', $query['client_id']);
        $this->assertSame('http://localhost/cb', $query['redirect_uri']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('offline', $query['access_type']);
        $this->assertStringContainsString('drive.readonly', $query['scope']);
        $this->assertStringContainsString('drive.metadata.readonly', $query['scope']);
        $this->assertNotEmpty($query['state']);
    }

    public function test_oauth_callback_exchanges_code_for_token_and_stores_credentials(): void
    {
        config()->set('connectors.providers.google-drive.client_id', 'cid');
        config()->set('connectors.providers.google-drive.client_secret', 'cs');
        config()->set('connectors.providers.google-drive.redirect_uri', 'http://localhost/cb');

        $installation = $this->makeInstallation();
        $state = $this->initiateAndExtractState($installation->id);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'AT-xyz',
                'refresh_token' => 'RT-abc',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
                'scope' => 'drive.readonly',
            ], 200),
        ]);

        $req = Request::create('/cb', 'GET', ['code' => 'auth-code', 'state' => $state]);
        $this->connector()->handleOAuthCallback($installation->id, $req);

        $row = ConnectorCredential::query()
            ->where('connector_installation_id', $installation->id)
            ->first();
        $this->assertNotNull($row);
        $this->assertSame('AT-xyz', Crypt::decryptString($row->encrypted_access_token));
        $this->assertSame('RT-abc', Crypt::decryptString($row->encrypted_refresh_token));
    }

    public function test_oauth_callback_throws_on_missing_code(): void
    {
        $installation = $this->makeInstallation();
        $req = Request::create('/cb', 'GET', ['state' => 'whatever']);

        $this->expectException(ConnectorAuthException::class);
        $this->expectExceptionMessage('code');
        $this->connector()->handleOAuthCallback($installation->id, $req);
    }

    public function test_oauth_callback_throws_on_token_exchange_failure(): void
    {
        $installation = $this->makeInstallation();
        $state = $this->initiateAndExtractState($installation->id);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        $req = Request::create('/cb', 'GET', ['code' => 'bad-code', 'state' => $state]);

        $this->expectException(ConnectorAuthException::class);
        $this->expectExceptionMessage('token exchange failed');
        $this->connector()->handleOAuthCallback($installation->id, $req);
    }

    public function test_sync_full_dispatches_ingest_jobs_for_each_markdown_pdf_doc_file(): void
    {
        Queue::fake();
        Storage::fake('kb');

        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id, 'AT-xyz');

        // ORDER MATTERS: Http::fake patterns evaluate first-match-wins.
        // More-specific patterns (per-file download URLs) must come
        // BEFORE the broader `files*` wildcard that lists.
        Http::fake([
            'www.googleapis.com/drive/v3/files/file-md*' => Http::response('# markdown body', 200),
            'www.googleapis.com/drive/v3/files/file-pdf*' => Http::response('PDF-binary', 200),
            'www.googleapis.com/drive/v3/files/file-gdoc*' => Http::response('# exported gdoc as md', 200),
            'www.googleapis.com/drive/v3/changes/startPageToken' => Http::response([
                'startPageToken' => 'cursor-1',
            ], 200),
            'www.googleapis.com/drive/v3/files*' => Http::response([
                'files' => [
                    ['id' => 'file-md', 'name' => 'doc.md', 'mimeType' => 'text/markdown'],
                    ['id' => 'file-pdf', 'name' => 'doc.pdf', 'mimeType' => 'application/pdf'],
                    ['id' => 'file-gdoc', 'name' => 'doc-gdoc', 'mimeType' => 'application/vnd.google-apps.document'],
                ],
            ], 200),
        ]);

        $result = $this->connector()->syncFull($installation->id);

        $this->assertSame(3, $result->documentsAdded);
        $this->assertSame(0, $result->documentsUpdated);
        $this->assertSame(0, $result->documentsRemoved);
        $this->assertSame([], $result->errors);

        Queue::assertPushed(IngestDocumentJob::class, 3);

        // The changes cursor was initialised on the credential row.
        $extra = $this->app->make(\App\Connectors\Auth\OAuthCredentialVault::class)
            ->getExtra($installation->id);
        $this->assertSame('cursor-1', $extra['changes_page_token']);
    }

    public function test_sync_incremental_uses_changes_api_with_page_token(): void
    {
        Queue::fake();
        Storage::fake('kb');

        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id, 'AT-xyz', extra: ['changes_page_token' => 'cursor-1']);

        Http::fake([
            'www.googleapis.com/drive/v3/changes*' => Http::response([
                'changes' => [
                    [
                        'fileId' => 'file-md',
                        'removed' => false,
                        'file' => ['id' => 'file-md', 'name' => 'doc.md', 'mimeType' => 'text/markdown'],
                    ],
                    [
                        'fileId' => 'file-deleted',
                        'removed' => true,
                    ],
                ],
                'newStartPageToken' => 'cursor-2',
            ], 200),
            'www.googleapis.com/drive/v3/files/file-md*' => Http::response('# new body', 200),
        ]);

        $result = $this->connector()->syncIncremental($installation->id, Carbon::now()->subHour());

        $this->assertSame(0, $result->documentsAdded);
        $this->assertSame(1, $result->documentsUpdated);
        $this->assertSame(1, $result->documentsRemoved);
        $this->assertSame([], $result->errors);

        Queue::assertPushed(IngestDocumentJob::class, 1);

        $extra = $this->app->make(\App\Connectors\Auth\OAuthCredentialVault::class)
            ->getExtra($installation->id);
        $this->assertSame('cursor-2', $extra['changes_page_token']);
    }

    public function test_sync_incremental_falls_back_to_full_when_no_cursor(): void
    {
        Queue::fake();
        Storage::fake('kb');

        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id, 'AT-xyz');

        Http::fake([
            'www.googleapis.com/drive/v3/files?*' => Http::response(['files' => []], 200),
            'www.googleapis.com/drive/v3/changes/startPageToken' => Http::response([
                'startPageToken' => 'cursor-init',
            ], 200),
        ]);

        $result = $this->connector()->syncIncremental($installation->id, null);

        $this->assertSame(0, $result->documentsAdded);
        $this->assertSame(0, $result->documentsUpdated);
    }

    public function test_disconnect_revokes_token_at_google_endpoint(): void
    {
        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id, 'AT-xyz', refresh: 'RT-abc');

        Http::fake([
            'oauth2.googleapis.com/revoke' => Http::response([], 200),
        ]);

        $this->connector()->disconnect($installation->id);

        Http::assertSent(function ($req) {
            return str_contains((string) $req->url(), 'oauth2.googleapis.com/revoke');
        });
        $this->assertDatabaseMissing('connector_credentials', [
            'connector_installation_id' => $installation->id,
        ]);
    }

    public function test_disconnect_continues_when_revoke_fails(): void
    {
        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id, 'AT-xyz');

        // Upstream is unreachable / 5xx.
        Http::fake([
            'oauth2.googleapis.com/revoke' => function () {
                throw new \RuntimeException('Network down');
            },
        ]);

        $this->connector()->disconnect($installation->id);

        $this->assertDatabaseMissing('connector_credentials', [
            'connector_installation_id' => $installation->id,
        ]);
    }

    public function test_health_pings_about_endpoint(): void
    {
        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id, 'AT-xyz');

        Http::fake([
            'www.googleapis.com/drive/v3/about*' => Http::response([
                'user' => ['emailAddress' => 'tester@example.com'],
            ], 200),
        ]);

        $status = $this->connector()->health($installation->id);
        $this->assertSame(HealthStatus::STATE_HEALTHY, $status->state);
    }

    public function test_health_returns_errored_when_no_credentials(): void
    {
        $installation = $this->makeInstallation();
        $status = $this->connector()->health($installation->id);
        $this->assertSame(HealthStatus::STATE_ERRORED, $status->state);
    }

    public function test_pii_redactor_runs_at_ingest_boundary_when_enabled(): void
    {
        Queue::fake();
        Storage::fake('kb');

        // Enable PII redaction at the boundary.
        config()->set('kb.pii_redactor.enabled', true);

        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id, 'AT-xyz');

        // The default RedactorEngine strategy is mask; an email
        // address in the body becomes [REDACTED:EMAIL] (or similar).
        Http::fake([
            'www.googleapis.com/drive/v3/files?*' => Http::response([
                'files' => [['id' => 'file-md', 'name' => 'doc.md', 'mimeType' => 'text/markdown']],
            ], 200),
            'www.googleapis.com/drive/v3/files/file-md*' => Http::response(
                'contact me at user@example.com please',
                200,
            ),
            'www.googleapis.com/drive/v3/changes/startPageToken' => Http::response([
                'startPageToken' => 'cursor-x',
            ], 200),
        ]);

        $this->connector()->syncFull($installation->id);

        // The KB disk should hold a redacted body — no raw email.
        $disk = Storage::disk((string) (config('kb.disk') ?? 'kb'));
        $files = $disk->allFiles();
        $this->assertNotEmpty($files);
        $contents = $disk->get($files[0]);
        $this->assertStringNotContainsString('user@example.com', $contents);
    }

    private function initiateAndExtractState(int $installationId): string
    {
        Cache::flush();
        $url = $this->connector()->initiateOAuth($installationId);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        return (string) ($query['state'] ?? '');
    }

    /**
     * @param  array<string,mixed>  $extra
     */
    private function seedActiveCredential(
        int $installationId,
        string $access,
        ?string $refresh = null,
        array $extra = [],
    ): void {
        ConnectorCredential::create([
            'tenant_id' => 'default',
            'connector_installation_id' => $installationId,
            'encrypted_access_token' => Crypt::encryptString($access),
            'encrypted_refresh_token' => $refresh === null ? null : Crypt::encryptString($refresh),
            'expires_at' => Carbon::now()->addHour(),
            'extra_json' => $extra === [] ? null : $extra,
        ]);
    }
}
