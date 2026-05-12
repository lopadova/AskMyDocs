<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Connectors\BuiltIn\OneDriveConnector;
use App\Connectors\Exceptions\ConnectorAuthException;
use App\Connectors\HealthStatus;
use App\Jobs\IngestDocumentJob;
use App\Models\ConnectorCredential;
use App\Models\ConnectorInstallation;
use App\Models\KnowledgeDocument;
use App\Models\User;
use App\Support\TenantContext;
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
 * v4.5/W5 — OneDriveConnector behaviour: OAuth flow, full sync with
 * recursive folder walk, incremental sync via Graph delta query,
 * disconnect, health probe, tenant isolation, PII redaction at the
 * boundary.
 *
 * Every Microsoft Graph interaction is stubbed via Http::fake. The
 * connector talks via raw `Http::` — no Microsoft SDKs (CLAUDE.md
 * §6 no-SDKs rule).
 */
final class OneDriveConnectorTest extends TestCase
{
    use RefreshDatabase;

    private function connector(): OneDriveConnector
    {
        return $this->app->make(OneDriveConnector::class);
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
            'connector_name' => 'onedrive',
            'status' => ConnectorInstallation::STATUS_PENDING,
            'created_by' => $user->id,
        ]);
    }

    private function initiateAndExtractState(int $installationId): string
    {
        Cache::flush();
        $url = $this->connector()->initiateOAuth($installationId);
        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);

        return (string) ($query['state'] ?? '');
    }

    /**
     * @param  array<string,mixed>  $extra
     */
    private function seedActiveCredential(
        int $installationId,
        string $access = 'AT-onedrive',
        ?string $refresh = 'RT-onedrive',
        array $extra = [],
        string $tenantId = 'default',
    ): void {
        ConnectorCredential::create([
            'tenant_id' => $tenantId,
            'connector_installation_id' => $installationId,
            'encrypted_access_token' => Crypt::encryptString($access),
            'encrypted_refresh_token' => $refresh === null ? null : Crypt::encryptString($refresh),
            'expires_at' => Carbon::now()->addHour(),
            'extra_json' => $extra === [] ? null : $extra,
        ]);
    }

    private function seedKnowledgeDocument(string $tenantId, string $onedriveItemId): KnowledgeDocument
    {
        return KnowledgeDocument::create([
            'tenant_id' => $tenantId,
            'project_key' => 'connector-onedrive',
            'source_type' => 'markdown',
            'title' => 'OneDrive doc '.$onedriveItemId,
            'source_path' => 'connector-onedrive/connectors/onedrive/installation-1/doc-'.$onedriveItemId.'.md',
            'mime_type' => 'text/markdown',
            'document_hash' => str_repeat('a', 64),
            'version_hash' => str_repeat('b', 64),
            'metadata' => [
                'connector' => 'onedrive',
                'onedrive_item_id' => $onedriveItemId,
            ],
        ]);
    }

    public function test_key_and_display_name(): void
    {
        $this->assertSame('onedrive', $this->connector()->key());
        $this->assertSame('Microsoft OneDrive', $this->connector()->displayName());
    }

    public function test_oauth_scopes_include_files_read_and_offline_access(): void
    {
        $scopes = $this->connector()->oauthScopes();
        $this->assertContains('Files.Read', $scopes);
        $this->assertContains('Files.Read.All', $scopes);
        $this->assertContains('User.Read', $scopes);
        $this->assertContains('offline_access', $scopes);
    }

    public function test_icon_url_resolves_to_existing_file(): void
    {
        $url = $this->connector()->iconUrl();
        $this->assertStringEndsWith('/connectors/onedrive.svg', $url);

        $repoRoot = realpath(__DIR__.'/../../../');
        $this->assertNotFalse($repoRoot, 'repo root must resolve');
        $this->assertFileExists($repoRoot.'/public/connectors/onedrive.svg');
    }

    public function test_initiate_oauth_returns_microsoft_auth_url_with_state_token(): void
    {
        config()->set('connectors.providers.onedrive.client_id', 'cid');
        config()->set('connectors.providers.onedrive.redirect_uri', 'http://localhost/cb');
        config()->set('connectors.providers.onedrive.tenant', 'common');

        $installation = $this->makeInstallation();

        $url = $this->connector()->initiateOAuth($installation->id);

        $this->assertStringStartsWith('https://login.microsoftonline.com/common/oauth2/v2.0/authorize?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('cid', $query['client_id']);
        $this->assertSame('http://localhost/cb', $query['redirect_uri']);
        $this->assertSame('code', $query['response_type']);
        $this->assertNotEmpty($query['state']);
        $this->assertStringContainsString('offline_access', $query['scope']);
    }

    public function test_oauth_callback_exchanges_code_and_stores_credentials(): void
    {
        config()->set('connectors.providers.onedrive.client_id', 'cid');
        config()->set('connectors.providers.onedrive.client_secret', 'cs');
        config()->set('connectors.providers.onedrive.redirect_uri', 'http://localhost/cb');

        $installation = $this->makeInstallation();
        $state = $this->initiateAndExtractState($installation->id);

        Http::fake([
            'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
                'access_token' => 'AT-msgraph',
                'refresh_token' => 'RT-msgraph',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
                'scope' => 'Files.Read Files.Read.All User.Read offline_access',
            ], 200),
        ]);

        $req = Request::create('/cb', 'GET', ['code' => 'auth-code', 'state' => $state]);
        $this->connector()->handleOAuthCallback($installation->id, $req);

        $row = ConnectorCredential::query()
            ->where('connector_installation_id', $installation->id)
            ->first();
        $this->assertNotNull($row);
        $this->assertSame('AT-msgraph', Crypt::decryptString($row->encrypted_access_token));
        $this->assertSame('RT-msgraph', Crypt::decryptString($row->encrypted_refresh_token));
        $this->assertNotNull($row->expires_at);
    }

    public function test_oauth_callback_throws_on_token_exchange_failure(): void
    {
        $installation = $this->makeInstallation();
        $state = $this->initiateAndExtractState($installation->id);

        Http::fake([
            'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        $req = Request::create('/cb', 'GET', ['code' => 'bad-code', 'state' => $state]);

        $this->expectException(ConnectorAuthException::class);
        $this->connector()->handleOAuthCallback($installation->id, $req);
    }

    public function test_oauth_callback_rejects_invalid_state(): void
    {
        $installation = $this->makeInstallation();

        Http::fake();

        $req = Request::create('/cb', 'GET', ['code' => 'auth-code', 'state' => 'never-issued']);

        $this->expectException(ConnectorAuthException::class);
        $this->expectExceptionMessageMatches('/state token invalid/i');

        $this->connector()->handleOAuthCallback($installation->id, $req);
    }

    public function test_sync_full_walks_root_folder_and_ingests_supported_files(): void
    {
        Queue::fake();
        Storage::fake('kb');

        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id);

        Http::fake([
            // Root children — one markdown file + one PDF + one unsupported docx.
            'graph.microsoft.com/v1.0/me/drive/root/children' => Http::response([
                'value' => [
                    [
                        'id' => 'item-md',
                        'name' => 'note.md',
                        'file' => ['mimeType' => 'text/markdown'],
                        'lastModifiedDateTime' => '2026-05-10T10:00:00Z',
                        'size' => 12,
                    ],
                    [
                        'id' => 'item-pdf',
                        'name' => 'spec.pdf',
                        'file' => ['mimeType' => 'application/pdf'],
                        'lastModifiedDateTime' => '2026-05-10T11:00:00Z',
                        'size' => 4096,
                    ],
                    [
                        'id' => 'item-docx',
                        'name' => 'rules.docx',
                        'file' => ['mimeType' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
                    ],
                ],
                '@odata.nextLink' => null,
            ], 200),
            'graph.microsoft.com/v1.0/me/drive/items/item-md/content' => Http::response('# Hello world', 200),
            'graph.microsoft.com/v1.0/me/drive/items/item-pdf/content' => Http::response('%PDF-1.7 fake pdf bytes', 200),
            // Initialise delta cursor.
            'graph.microsoft.com/v1.0/me/drive/root/delta*' => Http::response([
                'value' => [],
                '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/me/drive/root/delta?token=abc',
            ], 200),
        ]);

        $result = $this->connector()->syncFull($installation->id);

        // 2 supported items ingested; docx skipped.
        $this->assertSame(2, $result->documentsAdded);
        $this->assertSame(0, $result->documentsUpdated);
        $this->assertSame(0, $result->documentsRemoved);
        $this->assertSame([], $result->errors);

        Queue::assertPushed(IngestDocumentJob::class, 2);

        // Delta cursor persisted for the next incremental run.
        $cred = ConnectorCredential::query()->where('connector_installation_id', $installation->id)->first();
        $this->assertNotNull($cred);
        $this->assertIsArray($cred->extra_json);
        $this->assertArrayHasKey('delta_link', $cred->extra_json);
        $this->assertStringContainsString('token=abc', $cred->extra_json['delta_link']);
    }

    public function test_sync_full_recurses_into_subfolders(): void
    {
        Queue::fake();
        Storage::fake('kb');

        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id);

        Http::fake([
            // Root has one folder
            'graph.microsoft.com/v1.0/me/drive/root/children' => Http::response([
                'value' => [
                    [
                        'id' => 'folder-1',
                        'name' => 'Docs',
                        'folder' => ['childCount' => 1],
                    ],
                ],
                '@odata.nextLink' => null,
            ], 200),
            // Folder-1 contents — one markdown file
            'graph.microsoft.com/v1.0/me/drive/items/folder-1/children' => Http::response([
                'value' => [
                    [
                        'id' => 'item-nested',
                        'name' => 'nested.md',
                        'file' => ['mimeType' => 'text/markdown'],
                        'lastModifiedDateTime' => '2026-05-10T12:00:00Z',
                    ],
                ],
                '@odata.nextLink' => null,
            ], 200),
            'graph.microsoft.com/v1.0/me/drive/items/item-nested/content' => Http::response('# nested', 200),
            'graph.microsoft.com/v1.0/me/drive/root/delta*' => Http::response([
                'value' => [],
                '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/me/drive/root/delta?token=root',
            ], 200),
        ]);

        $result = $this->connector()->syncFull($installation->id);

        $this->assertSame(1, $result->documentsAdded);
        Queue::assertPushed(IngestDocumentJob::class, function (IngestDocumentJob $job) {
            return ($job->metadata['onedrive_item_id'] ?? null) === 'item-nested';
        });
    }

    public function test_sync_full_stores_onedrive_item_id_in_metadata(): void
    {
        Queue::fake();
        Storage::fake('kb');

        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id);

        Http::fake([
            'graph.microsoft.com/v1.0/me/drive/root/children' => Http::response([
                'value' => [[
                    'id' => 'item-meta',
                    'name' => 'meta.md',
                    'file' => ['mimeType' => 'text/markdown'],
                    'webUrl' => 'https://example.test/meta',
                    'lastModifiedDateTime' => '2026-05-10T10:00:00Z',
                ]],
                '@odata.nextLink' => null,
            ], 200),
            'graph.microsoft.com/v1.0/me/drive/items/item-meta/content' => Http::response('hello', 200),
            'graph.microsoft.com/v1.0/me/drive/root/delta*' => Http::response([
                'value' => [],
                '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/me/drive/root/delta?token=x',
            ], 200),
        ]);

        $this->connector()->syncFull($installation->id);

        Queue::assertPushed(IngestDocumentJob::class, function (IngestDocumentJob $job) {
            return ($job->metadata['onedrive_item_id'] ?? null) === 'item-meta'
                && ($job->metadata['onedrive_web_url'] ?? null) === 'https://example.test/meta'
                && ($job->metadata['connector'] ?? null) === 'onedrive';
        });
    }

    public function test_sync_full_writes_to_kb_sources_disk_with_path_prefix(): void
    {
        Queue::fake();
        config()->set('kb.sources.disk', 'kb');
        config()->set('kb.sources.path_prefix', 'tenant-prefix');
        Storage::fake('kb');

        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id);

        Http::fake([
            'graph.microsoft.com/v1.0/me/drive/root/children' => Http::response([
                'value' => [[
                    'id' => 'item-pref',
                    'name' => 'pref.md',
                    'file' => ['mimeType' => 'text/markdown'],
                ]],
                '@odata.nextLink' => null,
            ], 200),
            'graph.microsoft.com/v1.0/me/drive/items/item-pref/content' => Http::response('hi', 200),
            'graph.microsoft.com/v1.0/me/drive/root/delta*' => Http::response([
                'value' => [],
                '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/me/drive/root/delta?token=p',
            ], 200),
        ]);

        $this->connector()->syncFull($installation->id);

        $disk = Storage::disk('kb');
        $files = $disk->allFiles();
        $this->assertCount(1, $files);
        $this->assertStringStartsWith('tenant-prefix/', $files[0]);

        Queue::assertPushed(IngestDocumentJob::class, function (IngestDocumentJob $job) {
            return ! str_starts_with($job->relativePath, 'tenant-prefix/')
                && str_contains($job->relativePath, 'item-pref');
        });
    }

    public function test_sync_full_falls_back_to_default_item_suffix_when_item_id_sanitises_to_empty(): void
    {
        Queue::fake();
        Storage::fake('kb');

        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id);

        Http::fake([
            'graph.microsoft.com/v1.0/me/drive/root/children' => Http::response([
                'value' => [[
                    'id' => '🙂/??',
                    'name' => 'unsafe-id.md',
                    'file' => ['mimeType' => 'text/markdown'],
                ]],
                '@odata.nextLink' => null,
            ], 200),
            'graph.microsoft.com/v1.0/me/drive/items/%F0%9F%99%82%2F%3F%3F/content' => Http::response('hello', 200),
            'graph.microsoft.com/v1.0/me/drive/root/delta*' => Http::response([
                'value' => [],
                '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/me/drive/root/delta?token=fallback',
            ], 200),
        ]);

        $this->connector()->syncFull($installation->id);

        Queue::assertPushed(IngestDocumentJob::class, function (IngestDocumentJob $job) {
            return str_contains($job->relativePath, '-item.md');
        });
    }

    public function test_sync_incremental_uses_delta_cursor_and_persists_new_link(): void
    {
        Queue::fake();
        Storage::fake('kb');

        $installation = $this->makeInstallation();
        $this->seedActiveCredential(
            $installation->id,
            extra: ['delta_link' => 'https://graph.microsoft.com/v1.0/me/drive/root/delta?token=old'],
        );

        Http::fake([
            // The persisted delta link is the target of the incremental fetch.
            'graph.microsoft.com/v1.0/me/drive/root/delta*' => Http::response([
                'value' => [
                    [
                        'id' => 'item-changed',
                        'name' => 'changed.md',
                        'file' => ['mimeType' => 'text/markdown'],
                        'lastModifiedDateTime' => '2026-05-11T08:00:00Z',
                    ],
                ],
                '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/me/drive/root/delta?token=new',
            ], 200),
            'graph.microsoft.com/v1.0/me/drive/items/item-changed/content' => Http::response('# updated', 200),
        ]);

        $result = $this->connector()->syncIncremental(
            $installation->id,
            Carbon::parse('2026-05-10T00:00:00Z'),
        );

        $this->assertSame(0, $result->documentsAdded);
        $this->assertSame(1, $result->documentsUpdated);
        Queue::assertPushed(IngestDocumentJob::class, 1);

        // New delta link persisted.
        $cred = ConnectorCredential::query()->where('connector_installation_id', $installation->id)->first();
        $this->assertSame(
            'https://graph.microsoft.com/v1.0/me/drive/root/delta?token=new',
            $cred->extra_json['delta_link'] ?? null,
        );
    }

    public function test_sync_incremental_soft_deletes_via_metadata_key(): void
    {
        Queue::fake();
        Storage::fake('kb');

        $installation = $this->makeInstallation();
        $this->seedActiveCredential(
            $installation->id,
            extra: ['delta_link' => 'https://graph.microsoft.com/v1.0/me/drive/root/delta?token=cur'],
        );

        $doc = $this->seedKnowledgeDocument($installation->tenant_id, 'item-bye');

        Http::fake([
            'graph.microsoft.com/v1.0/me/drive/root/delta*' => Http::response([
                'value' => [
                    [
                        'id' => 'item-bye',
                        'deleted' => ['state' => 'deleted'],
                    ],
                ],
                '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/me/drive/root/delta?token=nxt',
            ], 200),
        ]);

        $result = $this->connector()->syncIncremental(
            $installation->id,
            Carbon::parse('2026-05-10T00:00:00Z'),
        );

        $this->assertSame(1, $result->documentsRemoved);
        $this->assertSoftDeleted('knowledge_documents', ['id' => $doc->id]);
    }

    public function test_sync_incremental_falls_back_to_full_when_no_cursor(): void
    {
        Queue::fake();
        Storage::fake('kb');

        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id);

        Http::fake([
            'graph.microsoft.com/v1.0/me/drive/root/children' => Http::response([
                'value' => [],
                '@odata.nextLink' => null,
            ], 200),
            'graph.microsoft.com/v1.0/me/drive/root/delta*' => Http::response([
                'value' => [],
                '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/me/drive/root/delta?token=fresh',
            ], 200),
        ]);

        $result = $this->connector()->syncIncremental($installation->id, null);

        $this->assertSame(0, $result->documentsAdded);
        $this->assertSame(0, $result->documentsUpdated);
    }

    public function test_disconnect_revokes_then_clears_credentials(): void
    {
        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id);

        Http::fake([
            'graph.microsoft.com/v1.0/me/revokeSignInSessions' => Http::response([], 204),
        ]);

        $this->connector()->disconnect($installation->id);

        $this->assertDatabaseMissing('connector_credentials', [
            'connector_installation_id' => $installation->id,
        ]);
        Http::assertSent(function ($req) {
            return str_contains((string) $req->url(), '/me/revokeSignInSessions');
        });
    }

    public function test_disconnect_still_clears_credentials_when_revoke_fails(): void
    {
        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id);

        Http::fake([
            'graph.microsoft.com/v1.0/me/revokeSignInSessions' => Http::response(['error' => 'down'], 500),
        ]);

        $this->connector()->disconnect($installation->id);

        $this->assertDatabaseMissing('connector_credentials', [
            'connector_installation_id' => $installation->id,
        ]);
    }

    public function test_health_pings_me_endpoint(): void
    {
        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id);

        Http::fake([
            'graph.microsoft.com/v1.0/me' => Http::response([
                'id' => 'user-1',
                'displayName' => 'Alice',
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

    public function test_health_returns_errored_on_401(): void
    {
        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id);

        Http::fake([
            'graph.microsoft.com/v1.0/me' => Http::response(['error' => 'unauthorized'], 401),
        ]);

        $status = $this->connector()->health($installation->id);
        $this->assertSame(HealthStatus::STATE_ERRORED, $status->state);
    }

    public function test_tenant_isolation_blocks_cross_tenant_deletion(): void
    {
        Queue::fake();
        Storage::fake('kb');

        $installationA = $this->makeInstallation('tenant-a');
        $this->seedActiveCredential(
            $installationA->id,
            extra: ['delta_link' => 'https://graph.microsoft.com/v1.0/me/drive/root/delta?token=a'],
            tenantId: 'tenant-a',
        );

        $docB = $this->seedKnowledgeDocument('tenant-b', 'item-shared');

        Http::fake([
            'graph.microsoft.com/v1.0/me/drive/root/delta*' => Http::response([
                'value' => [
                    [
                        'id' => 'item-shared',
                        'deleted' => ['state' => 'deleted'],
                    ],
                ],
                '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/me/drive/root/delta?token=a2',
            ], 200),
        ]);

        $this->app->make(TenantContext::class)->set('tenant-a');
        $result = $this->connector()->syncIncremental(
            $installationA->id,
            Carbon::parse('2026-05-10T00:00:00Z'),
        );
        $this->app->make(TenantContext::class)->reset();

        // tenant-a has no matching doc → counter stays 0.
        $this->assertSame(0, $result->documentsRemoved);
        // tenant-b's doc is untouched.
        $this->assertDatabaseHas('knowledge_documents', [
            'id' => $docB->id,
            'deleted_at' => null,
        ]);
    }

    public function test_pii_redaction_applied_at_persist_boundary_when_enabled(): void
    {
        Queue::fake();
        Storage::fake('kb');
        config()->set('kb.pii_redactor.enabled', true);

        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id);

        Http::fake([
            'graph.microsoft.com/v1.0/me/drive/root/children' => Http::response([
                'value' => [[
                    'id' => 'item-pii',
                    'name' => 'pii.md',
                    'file' => ['mimeType' => 'text/markdown'],
                ]],
                '@odata.nextLink' => null,
            ], 200),
            'graph.microsoft.com/v1.0/me/drive/items/item-pii/content' => Http::response(
                'reach me at agent@example.com any time',
                200,
            ),
            'graph.microsoft.com/v1.0/me/drive/root/delta*' => Http::response([
                'value' => [],
                '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/me/drive/root/delta?token=p',
            ], 200),
        ]);

        $this->connector()->syncFull($installation->id);

        $disk = Storage::disk((string) config('kb.sources.disk', 'kb'));
        $files = $disk->allFiles();
        $this->assertNotEmpty($files);
        $found = false;
        foreach ($files as $file) {
            $contents = $disk->get($file);
            if (str_contains($contents, 'agent@example.com')) {
                $found = true;
                break;
            }
        }
        $this->assertFalse($found, 'plaintext email must NOT survive when PII redactor is enabled');
    }
}
