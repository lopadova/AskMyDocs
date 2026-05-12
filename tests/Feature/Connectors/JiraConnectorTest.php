<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Connectors\BuiltIn\JiraConnector;
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
 * v4.5/W6 — JiraConnector behaviour: OAuth flow + cloud_id resolution,
 * full sync (project walk + JQL issue walk + ADF rendering),
 * incremental sync with JQL `updated >=`, JQL-date-format quirk, ADF
 * unknown-node placeholder, tenant isolation, PII redaction,
 * pagination-limit error.
 */
final class JiraConnectorTest extends TestCase
{
    use RefreshDatabase;

    private function connector(): JiraConnector
    {
        return $this->app->make(JiraConnector::class);
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
            'connector_name' => 'jira',
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
        string $access = 'AT-jira',
        ?string $refresh = 'RT-jira',
        array $extra = ['cloud_id' => 'cloud-jira-1'],
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

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function makeAdfBody(string $text = 'Description body.'): array
    {
        return [
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'content' => [['type' => 'text', 'text' => $text]],
            ]],
        ];
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function makeIssue(string $key = 'PROJ-1', array $overrides = []): array
    {
        $issue = [
            'id' => '10001',
            'key' => $key,
            'fields' => [
                'summary' => 'Sample issue',
                'description' => $this->makeAdfBody(),
                'issuetype' => ['name' => 'Bug'],
                'status' => ['name' => 'Open'],
                'priority' => ['name' => 'High'],
                'project' => [
                    'key' => 'PROJ',
                    'name' => 'Sample Project',
                    'self' => 'https://acme.atlassian.net/rest/api/3/project/10000',
                ],
                'assignee' => ['emailAddress' => 'alice@example.com', 'displayName' => 'Alice'],
                'reporter' => ['emailAddress' => 'bob@example.com', 'displayName' => 'Bob'],
                'labels' => ['backend'],
                'components' => [['name' => 'api']],
                'fixVersions' => [['name' => 'v2.5']],
                'created' => '2026-05-01T10:00:00.000+0000',
                'updated' => '2026-05-11T12:00:00.000+0000',
                'comment' => ['comments' => []],
            ],
        ];

        return array_replace_recursive($issue, $overrides);
    }

    public function test_key_and_display_name(): void
    {
        $this->assertSame('jira', $this->connector()->key());
        $this->assertSame('Jira', $this->connector()->displayName());
    }

    public function test_oauth_scopes_include_jira_read_and_offline_access(): void
    {
        $scopes = $this->connector()->oauthScopes();
        $this->assertContains('read:jira-work', $scopes);
        $this->assertContains('read:jira-user', $scopes);
        $this->assertContains('offline_access', $scopes);
    }

    public function test_icon_url_resolves_to_existing_file(): void
    {
        $url = $this->connector()->iconUrl();
        $this->assertStringEndsWith('/connectors/jira.svg', $url);

        $repoRoot = realpath(__DIR__.'/../../../');
        $this->assertNotFalse($repoRoot);
        $this->assertFileExists($repoRoot.'/public/connectors/jira.svg');
    }

    public function test_initiate_oauth_returns_atlassian_auth_url_with_state(): void
    {
        config()->set('connectors.providers.jira.client_id', 'cid');
        config()->set('connectors.providers.jira.redirect_uri', 'http://localhost/cb');

        $installation = $this->makeInstallation();
        $url = $this->connector()->initiateOAuth($installation->id);

        $this->assertStringStartsWith('https://auth.atlassian.com/authorize?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('cid', $query['client_id']);
        $this->assertSame('api.atlassian.com', $query['audience']);
        $this->assertSame('http://localhost/cb', $query['redirect_uri']);
        $this->assertStringContainsString('read:jira-work', $query['scope']);
        $this->assertNotEmpty($query['state']);
    }

    public function test_oauth_callback_exchanges_code_and_picks_jira_capable_resource(): void
    {
        config()->set('connectors.providers.jira.client_id', 'cid');
        config()->set('connectors.providers.jira.client_secret', 'cs');
        config()->set('connectors.providers.jira.redirect_uri', 'http://localhost/cb');

        $installation = $this->makeInstallation();
        $state = $this->initiateAndExtractState($installation->id);

        Http::fake([
            'auth.atlassian.com/oauth/token' => Http::response([
                'access_token' => 'AT-real',
                'refresh_token' => 'RT-real',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
                'scope' => 'read:jira-work',
            ], 200),
            // Two sites — first is Confluence-only, second is Jira.
            'api.atlassian.com/oauth/token/accessible-resources' => Http::response([
                ['id' => 'cloud-conf', 'scopes' => ['read:confluence-content.all']],
                ['id' => 'cloud-jira', 'scopes' => ['read:jira-work']],
            ], 200),
        ]);

        $req = Request::create('/cb', 'GET', ['code' => 'auth-code', 'state' => $state]);
        $this->connector()->handleOAuthCallback($installation->id, $req);

        $row = ConnectorCredential::query()
            ->where('connector_installation_id', $installation->id)
            ->first();
        $this->assertNotNull($row);
        $this->assertSame('AT-real', Crypt::decryptString($row->encrypted_access_token));
        $this->assertSame('cloud-jira', $row->extra_json['cloud_id'] ?? null);
    }

    public function test_oauth_callback_throws_when_no_jira_resource_available(): void
    {
        config()->set('connectors.providers.jira.client_id', 'cid');
        config()->set('connectors.providers.jira.client_secret', 'cs');
        config()->set('connectors.providers.jira.redirect_uri', 'http://localhost/cb');

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

    public function test_oauth_callback_rejects_bad_state_token(): void
    {
        $installation = $this->makeInstallation();
        $req = Request::create('/cb', 'GET', ['code' => 'c', 'state' => 'forged']);
        $this->expectException(ConnectorAuthException::class);
        $this->expectExceptionMessageMatches('/state token/');
        $this->connector()->handleOAuthCallback($installation->id, $req);
    }

    public function test_sync_full_walks_projects_then_issues(): void
    {
        Queue::fake();
        Storage::fake('kb');

        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id, extra: ['cloud_id' => 'c1']);

        Http::fake([
            'api.atlassian.com/ex/jira/c1/rest/api/3/project/search*' => Http::response([
                'values' => [[
                    'id' => '10000',
                    'key' => 'PROJ',
                    'name' => 'Sample Project',
                    'self' => 'https://acme.atlassian.net/rest/api/3/project/10000',
                ]],
                'isLast' => true,
            ], 200),
            'api.atlassian.com/ex/jira/c1/rest/api/3/search*' => Http::response([
                'issues' => [$this->makeIssue('PROJ-1')],
                'startAt' => 0,
                'maxResults' => 50,
                'total' => 1,
                'isLast' => true,
            ], 200),
        ]);

        $result = $this->connector()->syncFull($installation->id);

        $this->assertSame(1, $result->documentsAdded);
        $this->assertSame([], $result->errors);

        Queue::assertPushed(IngestDocumentJob::class, function (IngestDocumentJob $job) {
            return ($job->metadata['jira_issue_key'] ?? null) === 'PROJ-1'
                && ($job->metadata['jira_project_key'] ?? null) === 'PROJ'
                && ($job->metadata['jira_cloud_id'] ?? null) === 'c1'
                && ($job->metadata['source'] ?? null) === 'jira'
                && ($job->metadata['source_id'] ?? null) === 'PROJ-1';
        });
    }

    public function test_sync_full_converts_adf_description_to_markdown_on_disk(): void
    {
        Queue::fake();
        Storage::fake('kb');

        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id, extra: ['cloud_id' => 'c1']);

        $issue = $this->makeIssue('PROJ-7', [
            'fields' => [
                'summary' => 'Architecture issue',
                'description' => [
                    'type' => 'doc',
                    'content' => [
                        ['type' => 'heading', 'attrs' => ['level' => 1], 'content' => [['type' => 'text', 'text' => 'Section']]],
                        ['type' => 'paragraph', 'content' => [
                            ['type' => 'text', 'text' => 'A '],
                            ['type' => 'text', 'text' => 'bold', 'marks' => [['type' => 'strong']]],
                            ['type' => 'text', 'text' => ' word.'],
                        ]],
                    ],
                ],
            ],
        ]);

        Http::fake([
            'api.atlassian.com/ex/jira/c1/rest/api/3/project/search*' => Http::response([
                'values' => [['id' => '10000', 'key' => 'PROJ']],
                'isLast' => true,
            ], 200),
            'api.atlassian.com/ex/jira/c1/rest/api/3/search*' => Http::response([
                'issues' => [$issue],
                'isLast' => true,
            ], 200),
        ]);

        $this->connector()->syncFull($installation->id);

        $disk = Storage::disk((string) config('kb.sources.disk', 'kb'));
        $files = $disk->allFiles();
        $this->assertNotEmpty($files);
        $contents = $disk->get($files[0]);
        $this->assertStringContainsString('# [PROJ-7] Architecture issue', $contents);
        $this->assertStringContainsString('# Section', $contents);
        $this->assertStringContainsString('**bold**', $contents);
    }

    public function test_sync_full_renders_comments_appendix(): void
    {
        Queue::fake();
        Storage::fake('kb');

        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id, extra: ['cloud_id' => 'c1']);

        $issue = $this->makeIssue('PROJ-2', [
            'fields' => [
                'comment' => [
                    'comments' => [[
                        'author' => ['displayName' => 'Carol'],
                        'created' => '2026-05-10T09:00:00.000+0000',
                        'body' => $this->makeAdfBody('First reply.'),
                    ]],
                ],
            ],
        ]);

        Http::fake([
            'api.atlassian.com/ex/jira/c1/rest/api/3/project/search*' => Http::response([
                'values' => [['id' => '10000', 'key' => 'PROJ']],
                'isLast' => true,
            ], 200),
            'api.atlassian.com/ex/jira/c1/rest/api/3/search*' => Http::response([
                'issues' => [$issue],
                'isLast' => true,
            ], 200),
        ]);

        $this->connector()->syncFull($installation->id);

        $disk = Storage::disk((string) config('kb.sources.disk', 'kb'));
        $contents = $disk->get($disk->allFiles()[0]);
        $this->assertStringContainsString('## Comments', $contents);
        $this->assertStringContainsString('### Carol — 2026-05-10', $contents);
        $this->assertStringContainsString('First reply.', $contents);
    }

    public function test_sync_full_throws_when_cloud_id_missing(): void
    {
        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id, extra: []);

        $this->expectException(ConnectorAuthException::class);
        $this->expectExceptionMessageMatches('/cloud_id missing/i');

        $this->connector()->syncFull($installation->id);
    }

    public function test_sync_incremental_uses_jql_date_format_not_iso8601(): void
    {
        Queue::fake();
        Storage::fake('kb');

        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id, extra: ['cloud_id' => 'c1']);

        $since = Carbon::parse('2026-05-10T08:30:15Z');

        Http::fake([
            'api.atlassian.com/ex/jira/c1/rest/api/3/search*' => Http::response([
                'issues' => [$this->makeIssue('PROJ-9')],
                'isLast' => true,
            ], 200),
        ]);

        $result = $this->connector()->syncIncremental($installation->id, $since);

        $this->assertSame(0, $result->documentsAdded);
        $this->assertSame(1, $result->documentsUpdated);

        Http::assertSent(function ($req) {
            $url = (string) $req->url();
            if (! str_contains($url, '/search')) {
                return false;
            }
            // JQL wire format: "YYYY-MM-DD HH:mm" — NOT ISO-8601.
            // After URL-encoding the JQL clause appears in the
            // query string. We verify the JQL contains the proper
            // date shape.
            $decoded = urldecode($url);

            return str_contains($decoded, 'updated >= "2026-05-10 08:30"')
                && ! str_contains($decoded, '2026-05-10T')
                && ! str_contains($decoded, 'Z"');
        });
    }

    public function test_sync_incremental_falls_back_to_full_when_since_null(): void
    {
        Queue::fake();
        Storage::fake('kb');

        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id, extra: ['cloud_id' => 'c1']);

        Http::fake([
            'api.atlassian.com/ex/jira/c1/rest/api/3/project/search*' => Http::response([
                'values' => [],
                'isLast' => true,
            ], 200),
        ]);

        $result = $this->connector()->syncIncremental($installation->id, null);
        $this->assertSame(0, $result->documentsAdded);
        $this->assertSame(0, $result->documentsUpdated);
    }

    public function test_unknown_adf_node_emits_placeholder_in_markdown_R14(): void
    {
        Queue::fake();
        Storage::fake('kb');

        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id, extra: ['cloud_id' => 'c1']);

        $issue = $this->makeIssue('PROJ-X', [
            'fields' => [
                'description' => [
                    'type' => 'doc',
                    'content' => [['type' => 'unicorn-extension']],
                ],
            ],
        ]);

        Http::fake([
            'api.atlassian.com/ex/jira/c1/rest/api/3/project/search*' => Http::response([
                'values' => [['id' => '10000', 'key' => 'PROJ']],
                'isLast' => true,
            ], 200),
            'api.atlassian.com/ex/jira/c1/rest/api/3/search*' => Http::response([
                'issues' => [$issue],
                'isLast' => true,
            ], 200),
        ]);

        $this->connector()->syncFull($installation->id);

        $disk = Storage::disk((string) config('kb.sources.disk', 'kb'));
        $contents = $disk->get($disk->allFiles()[0]);
        // R14 — unknown node must surface as an audit-trail marker.
        $this->assertStringContainsString('[adf-node: unicorn-extension]', $contents);
    }

    public function test_disconnect_attempts_token_revoke_then_clears_credentials(): void
    {
        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id);

        Http::fake([
            'auth.atlassian.com/oauth/token/revoke' => Http::response([], 200),
        ]);

        $this->connector()->disconnect($installation->id);

        Http::assertSent(function ($req) {
            return str_contains((string) $req->url(), 'auth.atlassian.com/oauth/token/revoke');
        });
        $this->assertDatabaseMissing('connector_credentials', [
            'connector_installation_id' => $installation->id,
        ]);
    }

    public function test_disconnect_revokes_both_access_and_refresh_tokens(): void
    {
        // Copilot iter1 finding #1 — revoking only the short-lived
        // access token leaves the refresh token valid upstream. The
        // connector must revoke BOTH, with `token_type_hint` set so
        // Atlassian's revoke endpoint doesn't have to guess.
        $installation = $this->makeInstallation();
        $this->seedActiveCredential(
            $installation->id,
            access: 'AT-real',
            refresh: 'RT-real',
        );

        Http::fake([
            'auth.atlassian.com/oauth/token/revoke' => Http::response([], 200),
        ]);

        $this->connector()->disconnect($installation->id);

        $accessRevoked = false;
        $refreshRevoked = false;
        Http::assertSent(function ($req) use (&$accessRevoked, &$refreshRevoked) {
            if (! str_contains((string) $req->url(), 'auth.atlassian.com/oauth/token/revoke')) {
                return false;
            }
            $data = $req->data();
            $token = $data['token'] ?? null;
            $hint = $data['token_type_hint'] ?? null;
            if ($token === 'AT-real' && $hint === 'access_token') {
                $accessRevoked = true;
            }
            if ($token === 'RT-real' && $hint === 'refresh_token') {
                $refreshRevoked = true;
            }

            return true;
        });
        $this->assertTrue($accessRevoked, 'Access token must be revoked.');
        $this->assertTrue($refreshRevoked, 'Refresh token must be revoked.');
    }

    public function test_disconnect_clears_local_creds_even_when_revoke_fails(): void
    {
        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id);

        Http::fake([
            'auth.atlassian.com/oauth/token/revoke' => Http::response(['error' => 'oops'], 500),
        ]);

        // Must not throw — local cleanup runs unconditionally.
        $this->connector()->disconnect($installation->id);

        $this->assertDatabaseMissing('connector_credentials', [
            'connector_installation_id' => $installation->id,
        ]);
    }

    public function test_health_pings_myself_endpoint(): void
    {
        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id, extra: ['cloud_id' => 'c1']);

        Http::fake([
            'api.atlassian.com/ex/jira/c1/rest/api/3/myself' => Http::response(['accountId' => 'u-1'], 200),
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

    public function test_health_returns_errored_on_401(): void
    {
        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id, extra: ['cloud_id' => 'c1']);

        Http::fake([
            'api.atlassian.com/ex/jira/c1/rest/api/3/myself' => Http::response(['error' => 'denied'], 401),
        ]);

        $status = $this->connector()->health($installation->id);
        $this->assertSame(HealthStatus::STATE_ERRORED, $status->state);
        $this->assertStringContainsString('Authorization rejected', (string) $status->message);
    }

    public function test_tenant_isolation_blocks_cross_tenant_writes(): void
    {
        Queue::fake();
        Storage::fake('kb');

        $installationA = $this->makeInstallation('tenant-a');
        $this->seedActiveCredential(
            $installationA->id,
            extra: ['cloud_id' => 'cloud-a'],
            tenantId: 'tenant-a',
        );

        Http::fake([
            'api.atlassian.com/ex/jira/cloud-a/rest/api/3/project/search*' => Http::response([
                'values' => [['id' => '10000', 'key' => 'PROJ']],
                'isLast' => true,
            ], 200),
            'api.atlassian.com/ex/jira/cloud-a/rest/api/3/search*' => Http::response([
                'issues' => [$this->makeIssue('PROJ-T1')],
                'isLast' => true,
            ], 200),
        ]);

        $this->app->make(TenantContext::class)->set('tenant-a');
        $this->connector()->syncFull($installationA->id);
        $this->app->make(TenantContext::class)->reset();

        // Every dispatched ingest job must carry tenant-a.
        Queue::assertPushed(IngestDocumentJob::class, function (IngestDocumentJob $job) {
            return $job->tenantId === 'tenant-a';
        });
    }

    public function test_pii_redaction_applied_at_persist_boundary_when_enabled(): void
    {
        Queue::fake();
        Storage::fake('kb');
        config()->set('kb.pii_redactor.enabled', true);

        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id, extra: ['cloud_id' => 'c1']);

        $issue = $this->makeIssue('PROJ-PII', [
            'fields' => [
                'description' => $this->makeAdfBody('ping me at agent@example.com soon'),
            ],
        ]);

        Http::fake([
            'api.atlassian.com/ex/jira/c1/rest/api/3/project/search*' => Http::response([
                'values' => [['id' => '10000', 'key' => 'PROJ']],
                'isLast' => true,
            ], 200),
            'api.atlassian.com/ex/jira/c1/rest/api/3/search*' => Http::response([
                'issues' => [$issue],
                'isLast' => true,
            ], 200),
        ]);

        $this->connector()->syncFull($installation->id);

        $disk = Storage::disk((string) config('kb.sources.disk', 'kb'));
        $files = $disk->allFiles();
        $this->assertNotEmpty($files);
        $found = false;
        foreach ($files as $file) {
            if (str_contains((string) $disk->get($file), 'agent@example.com')) {
                $found = true;
                break;
            }
        }
        $this->assertFalse($found, 'plaintext email must NOT survive when PII redactor is enabled');
    }

    public function test_pagination_truncation_surfaces_as_error_in_sync_result(): void
    {
        Queue::fake();
        Storage::fake('kb');

        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id, extra: ['cloud_id' => 'c1']);

        // Project search always reports more pages — paginator caps
        // out → SyncResult carries the truncation as a non-fatal error.
        Http::fake([
            'api.atlassian.com/ex/jira/c1/rest/api/3/project/search*' => Http::response([
                'values' => [['id' => '1', 'key' => 'P1']],
                'isLast' => false,
                'startAt' => 0,
                'total' => 99999,
                'maxResults' => 1,
            ], 200),
            'api.atlassian.com/ex/jira/c1/rest/api/3/search*' => Http::response([
                'issues' => [],
                'isLast' => true,
            ], 200),
        ]);

        $result = $this->connector()->syncFull($installation->id);
        $this->assertNotEmpty($result->errors);
        $this->assertStringContainsString('truncated', $result->errors[count($result->errors) - 1]);
    }

    public function test_persists_last_synced_at_after_sync(): void
    {
        Queue::fake();
        Storage::fake('kb');

        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id, extra: ['cloud_id' => 'c1']);

        Http::fake([
            'api.atlassian.com/ex/jira/c1/rest/api/3/project/search*' => Http::response([
                'values' => [],
                'isLast' => true,
            ], 200),
        ]);

        $before = Carbon::now()->subSeconds(2);
        $this->connector()->syncFull($installation->id);

        $row = ConnectorCredential::query()
            ->where('connector_installation_id', $installation->id)
            ->first();
        $this->assertNotNull($row);
        $extra = $row->extra_json ?? [];
        $this->assertArrayHasKey('last_synced_at', $extra);
        $this->assertGreaterThanOrEqual(
            $before->toIso8601String(),
            (string) $extra['last_synced_at'],
        );
    }
}
