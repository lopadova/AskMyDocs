<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Connectors\BuiltIn\ConfluenceConnector;
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
 * v4.5/W5 — ConfluenceConnector behaviour: OAuth flow incl. cloud_id
 * resolution, full sync (space walk + page walk + storage-format
 * conversion), CQL-based incremental sync, archive-on-incremental,
 * health probe, tenant isolation, PII redaction at the boundary.
 *
 * Every Atlassian API interaction is stubbed via Http::fake.
 */
final class ConfluenceConnectorTest extends TestCase
{
    use RefreshDatabase;

    private function connector(): ConfluenceConnector
    {
        return $this->app->make(ConfluenceConnector::class);
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
            'connector_name' => 'confluence',
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
        string $access = 'AT-conf',
        ?string $refresh = 'RT-conf',
        array $extra = ['cloud_id' => 'cloud-123'],
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

    private function seedKnowledgeDocument(string $tenantId, string $pageId): KnowledgeDocument
    {
        return KnowledgeDocument::create([
            'tenant_id' => $tenantId,
            'project_key' => 'connector-confluence',
            'source_type' => 'markdown',
            'title' => 'Confluence page '.$pageId,
            'source_path' => 'connector-confluence/connectors/confluence/eng/'.$pageId.'.md',
            'mime_type' => 'text/markdown',
            'document_hash' => str_repeat('c', 64),
            'version_hash' => str_repeat('d', 64),
            'metadata' => [
                'connector' => 'confluence',
                'confluence_page_id' => $pageId,
            ],
        ]);
    }

    public function test_key_and_display_name(): void
    {
        $this->assertSame('confluence', $this->connector()->key());
        $this->assertSame('Confluence', $this->connector()->displayName());
    }

    public function test_oauth_scopes_include_confluence_read_and_offline_access(): void
    {
        $scopes = $this->connector()->oauthScopes();
        $this->assertContains('read:confluence-content.all', $scopes);
        $this->assertContains('read:confluence-space.summary', $scopes);
        $this->assertContains('offline_access', $scopes);
    }

    public function test_icon_url_resolves_to_existing_file(): void
    {
        $url = $this->connector()->iconUrl();
        $this->assertStringEndsWith('/connectors/confluence.svg', $url);

        $repoRoot = realpath(__DIR__.'/../../../');
        $this->assertNotFalse($repoRoot);
        $this->assertFileExists($repoRoot.'/public/connectors/confluence.svg');
    }

    public function test_initiate_oauth_returns_atlassian_auth_url_with_state(): void
    {
        config()->set('connectors.providers.confluence.client_id', 'cid');
        config()->set('connectors.providers.confluence.redirect_uri', 'http://localhost/cb');

        $installation = $this->makeInstallation();

        $url = $this->connector()->initiateOAuth($installation->id);

        $this->assertStringStartsWith('https://auth.atlassian.com/authorize?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('cid', $query['client_id']);
        $this->assertSame('api.atlassian.com', $query['audience']);
        $this->assertSame('http://localhost/cb', $query['redirect_uri']);
        $this->assertNotEmpty($query['state']);
    }

    public function test_oauth_callback_exchanges_code_and_resolves_cloud_id(): void
    {
        config()->set('connectors.providers.confluence.client_id', 'cid');
        config()->set('connectors.providers.confluence.client_secret', 'cs');
        config()->set('connectors.providers.confluence.redirect_uri', 'http://localhost/cb');

        $installation = $this->makeInstallation();
        $state = $this->initiateAndExtractState($installation->id);

        Http::fake([
            'auth.atlassian.com/oauth/token' => Http::response([
                'access_token' => 'AT-real',
                'refresh_token' => 'RT-real',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
                'scope' => 'read:confluence-content.all',
            ], 200),
            'api.atlassian.com/oauth/token/accessible-resources' => Http::response([
                [
                    'id' => 'cloud-abc',
                    'name' => 'Acme Wiki',
                    'scopes' => ['read:confluence-content.all', 'read:confluence-user'],
                    'url' => 'https://acme.atlassian.net',
                ],
            ], 200),
        ]);

        $req = Request::create('/cb', 'GET', ['code' => 'auth-code', 'state' => $state]);
        $this->connector()->handleOAuthCallback($installation->id, $req);

        $row = ConnectorCredential::query()
            ->where('connector_installation_id', $installation->id)
            ->first();
        $this->assertNotNull($row);
        $this->assertSame('AT-real', Crypt::decryptString($row->encrypted_access_token));
        $this->assertSame('cloud-abc', $row->extra_json['cloud_id'] ?? null);
    }

    public function test_oauth_callback_picks_first_confluence_capable_resource(): void
    {
        config()->set('connectors.providers.confluence.client_id', 'cid');
        config()->set('connectors.providers.confluence.client_secret', 'cs');
        config()->set('connectors.providers.confluence.redirect_uri', 'http://localhost/cb');

        $installation = $this->makeInstallation();
        $state = $this->initiateAndExtractState($installation->id);

        Http::fake([
            'auth.atlassian.com/oauth/token' => Http::response([
                'access_token' => 'AT-x',
                'expires_in' => 3600,
            ], 200),
            // Two sites — first is Jira-only, second is Confluence.
            'api.atlassian.com/oauth/token/accessible-resources' => Http::response([
                [
                    'id' => 'cloud-jira',
                    'scopes' => ['read:jira-work'],
                ],
                [
                    'id' => 'cloud-conf',
                    'scopes' => ['read:confluence-content.all'],
                ],
            ], 200),
        ]);

        $req = Request::create('/cb', 'GET', ['code' => 'c', 'state' => $state]);
        $this->connector()->handleOAuthCallback($installation->id, $req);

        $row = ConnectorCredential::query()->where('connector_installation_id', $installation->id)->first();
        $this->assertSame('cloud-conf', $row->extra_json['cloud_id'] ?? null);
    }

    public function test_oauth_callback_throws_when_no_accessible_resources(): void
    {
        config()->set('connectors.providers.confluence.client_id', 'cid');
        config()->set('connectors.providers.confluence.client_secret', 'cs');
        config()->set('connectors.providers.confluence.redirect_uri', 'http://localhost/cb');

        $installation = $this->makeInstallation();
        $state = $this->initiateAndExtractState($installation->id);

        Http::fake([
            'auth.atlassian.com/oauth/token' => Http::response([
                'access_token' => 'AT-y',
                'expires_in' => 3600,
            ], 200),
            'api.atlassian.com/oauth/token/accessible-resources' => Http::response([], 200),
        ]);

        $req = Request::create('/cb', 'GET', ['code' => 'c', 'state' => $state]);

        $this->expectException(ConnectorAuthException::class);
        $this->connector()->handleOAuthCallback($installation->id, $req);
    }

    public function test_oauth_callback_throws_on_token_exchange_failure(): void
    {
        $installation = $this->makeInstallation();
        $state = $this->initiateAndExtractState($installation->id);

        Http::fake([
            'auth.atlassian.com/oauth/token' => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        $req = Request::create('/cb', 'GET', ['code' => 'bad', 'state' => $state]);

        $this->expectException(ConnectorAuthException::class);
        $this->connector()->handleOAuthCallback($installation->id, $req);
    }

    public function test_sync_full_walks_spaces_then_pages_and_ingests_them(): void
    {
        Queue::fake();
        Storage::fake('kb');

        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id, extra: ['cloud_id' => 'cloud-1']);

        Http::fake([
            // Spaces: one global space.
            'api.atlassian.com/ex/confluence/cloud-1/wiki/rest/api/space*' => Http::response([
                'results' => [
                    ['key' => 'ENG', 'name' => 'Engineering', 'type' => 'global'],
                ],
                '_links' => [],
            ], 200),
            // Pages in ENG.
            'api.atlassian.com/ex/confluence/cloud-1/wiki/rest/api/content*' => Http::response([
                'results' => [
                    [
                        'id' => 'page-1',
                        'title' => 'How to deploy',
                        'status' => 'current',
                        'space' => ['key' => 'ENG'],
                        'version' => ['number' => 7, 'when' => '2026-05-10T10:00:00Z'],
                        'body' => [
                            'storage' => [
                                'value' => '<p>Run <code>artisan deploy</code> daily.</p>',
                            ],
                        ],
                    ],
                ],
                '_links' => [],
            ], 200),
        ]);

        $result = $this->connector()->syncFull($installation->id);

        $this->assertSame(1, $result->documentsAdded);
        $this->assertSame([], $result->errors);

        Queue::assertPushed(IngestDocumentJob::class, function (IngestDocumentJob $job) {
            return ($job->metadata['confluence_page_id'] ?? null) === 'page-1'
                && ($job->metadata['confluence_space_key'] ?? null) === 'ENG'
                && ($job->metadata['confluence_cloud_id'] ?? null) === 'cloud-1';
        });
    }

    public function test_sync_full_converts_storage_format_to_markdown_on_disk(): void
    {
        Queue::fake();
        Storage::fake('kb');

        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id, extra: ['cloud_id' => 'cloud-1']);

        Http::fake([
            'api.atlassian.com/ex/confluence/cloud-1/wiki/rest/api/space*' => Http::response([
                'results' => [['key' => 'ENG', 'type' => 'global']],
                '_links' => [],
            ], 200),
            'api.atlassian.com/ex/confluence/cloud-1/wiki/rest/api/content*' => Http::response([
                'results' => [[
                    'id' => 'page-md',
                    'title' => 'Architecture',
                    'status' => 'current',
                    'space' => ['key' => 'ENG'],
                    'body' => [
                        'storage' => [
                            'value' => '<h1>Section</h1><p>Some <strong>bold</strong> text.</p>',
                        ],
                    ],
                ]],
                '_links' => [],
            ], 200),
        ]);

        $this->connector()->syncFull($installation->id);

        $disk = Storage::disk((string) config('kb.sources.disk', 'kb'));
        $files = $disk->allFiles();
        $this->assertNotEmpty($files);
        $contents = $disk->get($files[0]);
        $this->assertStringContainsString('# Architecture', $contents);
        $this->assertStringContainsString('# Section', $contents);
        $this->assertStringContainsString('**bold**', $contents);
    }

    public function test_sync_full_throws_when_cloud_id_missing(): void
    {
        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id, extra: []);

        $this->expectException(ConnectorAuthException::class);
        $this->expectExceptionMessageMatches('/cloud_id missing/i');

        $this->connector()->syncFull($installation->id);
    }

    public function test_sync_incremental_uses_cql_filter_and_updates_pages(): void
    {
        Queue::fake();
        Storage::fake('kb');

        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id, extra: ['cloud_id' => 'cloud-1']);

        $since = Carbon::parse('2026-05-10T00:00:00Z');

        Http::fake([
            'api.atlassian.com/ex/confluence/cloud-1/wiki/rest/api/content/search*' => Http::response([
                'results' => [
                    [
                        'id' => 'page-updated',
                        'title' => 'Updated runbook',
                        'status' => 'current',
                        'space' => ['key' => 'OPS'],
                        'version' => ['number' => 2, 'when' => '2026-05-11T08:00:00Z'],
                        'body' => [
                            'storage' => ['value' => '<p>refreshed content</p>'],
                        ],
                    ],
                ],
                '_links' => [],
            ], 200),
        ]);

        $result = $this->connector()->syncIncremental($installation->id, $since);

        $this->assertSame(0, $result->documentsAdded);
        $this->assertSame(1, $result->documentsUpdated);

        Http::assertSent(function ($req) {
            $url = (string) $req->url();

            return str_contains($url, '/content/search')
                && str_contains($url, 'cql=')
                && str_contains($url, 'lastModified');
        });
    }

    public function test_sync_incremental_soft_deletes_archived_pages(): void
    {
        Queue::fake();
        Storage::fake('kb');

        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id, extra: ['cloud_id' => 'cloud-1']);

        $doc = $this->seedKnowledgeDocument($installation->tenant_id, 'page-archived');

        Http::fake([
            'api.atlassian.com/ex/confluence/cloud-1/wiki/rest/api/content/search*' => Http::response([
                'results' => [
                    [
                        'id' => 'page-archived',
                        'status' => 'archived',
                        'space' => ['key' => 'ENG'],
                        'body' => ['storage' => ['value' => '']],
                    ],
                ],
                '_links' => [],
            ], 200),
        ]);

        $result = $this->connector()->syncIncremental(
            $installation->id,
            Carbon::parse('2026-05-10T00:00:00Z'),
        );

        $this->assertSame(1, $result->documentsRemoved);
        $this->assertSoftDeleted('knowledge_documents', ['id' => $doc->id]);
    }

    public function test_sync_incremental_falls_back_to_full_when_since_is_null(): void
    {
        Queue::fake();
        Storage::fake('kb');

        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id, extra: ['cloud_id' => 'cloud-1']);

        Http::fake([
            'api.atlassian.com/ex/confluence/cloud-1/wiki/rest/api/space*' => Http::response([
                'results' => [],
                '_links' => [],
            ], 200),
        ]);

        $result = $this->connector()->syncIncremental($installation->id, null);

        $this->assertSame(0, $result->documentsAdded);
        $this->assertSame(0, $result->documentsUpdated);
    }

    public function test_disconnect_clears_credentials_without_atlassian_revoke_call(): void
    {
        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id);

        Http::fake();

        $this->connector()->disconnect($installation->id);

        Http::assertNothingSent();
        $this->assertDatabaseMissing('connector_credentials', [
            'connector_installation_id' => $installation->id,
        ]);
    }

    public function test_health_pings_user_current_endpoint(): void
    {
        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id, extra: ['cloud_id' => 'cloud-1']);

        Http::fake([
            'api.atlassian.com/ex/confluence/cloud-1/wiki/rest/api/user/current' => Http::response([
                'accountId' => 'u-1',
            ], 200),
        ]);

        $status = $this->connector()->health($installation->id);
        $this->assertSame(HealthStatus::STATE_HEALTHY, $status->state);
    }

    public function test_health_errors_when_cloud_id_missing(): void
    {
        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id, extra: []);

        $status = $this->connector()->health($installation->id);
        $this->assertSame(HealthStatus::STATE_ERRORED, $status->state);
        $this->assertStringContainsString('cloud_id', (string) $status->message);
    }

    public function test_health_returns_errored_when_no_credentials(): void
    {
        $installation = $this->makeInstallation();
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
            extra: ['cloud_id' => 'cloud-a'],
            tenantId: 'tenant-a',
        );

        $docB = $this->seedKnowledgeDocument('tenant-b', 'page-shared');

        Http::fake([
            'api.atlassian.com/ex/confluence/cloud-a/wiki/rest/api/content/search*' => Http::response([
                'results' => [[
                    'id' => 'page-shared',
                    'status' => 'archived',
                    'space' => ['key' => 'ENG'],
                ]],
                '_links' => [],
            ], 200),
        ]);

        $this->app->make(TenantContext::class)->set('tenant-a');
        $result = $this->connector()->syncIncremental(
            $installationA->id,
            Carbon::parse('2026-05-10T00:00:00Z'),
        );
        $this->app->make(TenantContext::class)->reset();

        $this->assertSame(0, $result->documentsRemoved);
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
        $this->seedActiveCredential($installation->id, extra: ['cloud_id' => 'cloud-1']);

        Http::fake([
            'api.atlassian.com/ex/confluence/cloud-1/wiki/rest/api/space*' => Http::response([
                'results' => [['key' => 'ENG', 'type' => 'global']],
                '_links' => [],
            ], 200),
            'api.atlassian.com/ex/confluence/cloud-1/wiki/rest/api/content*' => Http::response([
                'results' => [[
                    'id' => 'pg-pii',
                    'title' => 'Contacts',
                    'status' => 'current',
                    'space' => ['key' => 'ENG'],
                    'body' => [
                        'storage' => ['value' => '<p>ping me at agent@example.com soon</p>'],
                    ],
                ]],
                '_links' => [],
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
