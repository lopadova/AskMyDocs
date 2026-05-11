<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Connectors\Auth\OAuthCredentialVault;
use App\Connectors\BuiltIn\NotionConnector;
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
 * v4.5/W2 — NotionConnector behaviour: OAuth flow, sync (full +
 * incremental), disconnect (no revoke endpoint), health probe,
 * tenant isolation, PII redaction at the boundary.
 *
 * Every Notion API interaction is stubbed via Http::fake. The
 * connector talks to Notion via raw `Http::` (CLAUDE.md: no
 * vendor SDKs).
 */
final class NotionConnectorTest extends TestCase
{
    use RefreshDatabase;

    private function connector(): NotionConnector
    {
        return $this->app->make(NotionConnector::class);
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
            'connector_name' => 'notion',
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
        string $access = 'AT-xyz',
        array $extra = [],
        string $tenantId = 'default',
    ): void {
        ConnectorCredential::create([
            'tenant_id' => $tenantId,
            'connector_installation_id' => $installationId,
            'encrypted_access_token' => Crypt::encryptString($access),
            'encrypted_refresh_token' => null,
            // Notion tokens never expire — far-future is the canonical shape.
            'expires_at' => Carbon::now()->addYears(10),
            'extra_json' => $extra === [] ? null : $extra,
        ]);
    }

    private function seedKnowledgeDocument(string $tenantId, string $notionPageId): KnowledgeDocument
    {
        return KnowledgeDocument::create([
            'tenant_id' => $tenantId,
            'project_key' => 'connector-notion',
            'source_type' => 'markdown',
            'title' => 'Notion page '.$notionPageId,
            'source_path' => 'connector-notion/connectors/notion/ws/'.$notionPageId.'.md',
            'mime_type' => 'text/markdown',
            'document_hash' => str_repeat('a', 64),
            'version_hash' => str_repeat('b', 64),
            'metadata' => [
                'connector' => 'notion',
                'notion_page_id' => $notionPageId,
            ],
        ]);
    }

    public function test_initiate_oauth_returns_notion_auth_url_with_state_token(): void
    {
        config()->set('connectors.providers.notion.client_id', 'cid');
        config()->set('connectors.providers.notion.redirect_uri', 'http://localhost/cb');

        $installation = $this->makeInstallation();

        $url = $this->connector()->initiateOAuth($installation->id);

        $this->assertStringStartsWith('https://api.notion.com/v1/oauth/authorize?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('cid', $query['client_id']);
        $this->assertSame('http://localhost/cb', $query['redirect_uri']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('user', $query['owner']);
        $this->assertNotEmpty($query['state']);
    }

    public function test_oauth_callback_exchanges_code_and_stores_bot_id_workspace_id_in_extra(): void
    {
        config()->set('connectors.providers.notion.client_id', 'cid');
        config()->set('connectors.providers.notion.client_secret', 'cs');
        config()->set('connectors.providers.notion.redirect_uri', 'http://localhost/cb');

        $installation = $this->makeInstallation();
        $state = $this->initiateAndExtractState($installation->id);

        Http::fake([
            'api.notion.com/v1/oauth/token' => Http::response([
                'access_token' => 'AT-notion',
                'bot_id' => 'bot-uuid-1',
                'workspace_id' => 'ws-uuid-1',
                'workspace_name' => 'Acme Workspace',
                'workspace_icon' => 'https://example.test/icon.png',
                'token_type' => 'bearer',
            ], 200),
        ]);

        $req = Request::create('/cb', 'GET', ['code' => 'auth-code', 'state' => $state]);
        $this->connector()->handleOAuthCallback($installation->id, $req);

        $row = ConnectorCredential::query()
            ->where('connector_installation_id', $installation->id)
            ->first();
        $this->assertNotNull($row);
        $this->assertSame('AT-notion', Crypt::decryptString($row->encrypted_access_token));
        $this->assertNull($row->encrypted_refresh_token);
        $this->assertNull($row->expires_at);
        $this->assertSame('bot-uuid-1', $row->extra_json['bot_id']);
        $this->assertSame('ws-uuid-1', $row->extra_json['workspace_id']);
        $this->assertSame('Acme Workspace', $row->extra_json['workspace_name']);
    }

    public function test_oauth_callback_throws_on_token_exchange_failure(): void
    {
        $installation = $this->makeInstallation();
        $state = $this->initiateAndExtractState($installation->id);

        Http::fake([
            'api.notion.com/v1/oauth/token' => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        $req = Request::create('/cb', 'GET', ['code' => 'bad-code', 'state' => $state]);

        $this->expectException(\App\Connectors\Exceptions\ConnectorAuthException::class);
        $this->connector()->handleOAuthCallback($installation->id, $req);
    }

    public function test_sync_full_paginates_search_endpoint(): void
    {
        Queue::fake();
        Storage::fake('kb');

        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id, extra: ['workspace_id' => 'ws-1']);

        Http::fake([
            // First search page → has_more=true + cursor
            // Second search page → has_more=false
            'api.notion.com/v1/search' => Http::sequence()
                ->push([
                    'results' => [
                        ['id' => 'page-aaa', 'properties' => [], 'last_edited_time' => '2026-05-10T10:00:00Z'],
                    ],
                    'next_cursor' => 'cursor-1',
                    'has_more' => true,
                ], 200)
                ->push([
                    'results' => [
                        ['id' => 'page-bbb', 'properties' => [], 'last_edited_time' => '2026-05-10T11:00:00Z'],
                    ],
                    'next_cursor' => null,
                    'has_more' => false,
                ], 200),
            // Block children → empty for both pages
            'api.notion.com/v1/blocks/*' => Http::response([
                'results' => [],
                'next_cursor' => null,
                'has_more' => false,
            ], 200),
        ]);

        $result = $this->connector()->syncFull($installation->id);

        $this->assertSame(2, $result->documentsAdded);
        $this->assertSame(0, $result->documentsUpdated);
        $this->assertSame(0, $result->documentsRemoved);
        $this->assertSame([], $result->errors);

        Queue::assertPushed(IngestDocumentJob::class, 2);
    }

    public function test_sync_full_converts_notion_blocks_to_markdown(): void
    {
        Queue::fake();
        Storage::fake('kb');

        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id, extra: ['workspace_id' => 'ws-1']);

        Http::fake([
            'api.notion.com/v1/search' => Http::response([
                'results' => [[
                    'id' => 'page-xxx',
                    'last_edited_time' => '2026-05-10T10:00:00Z',
                    'properties' => [
                        'title' => [
                            'type' => 'title',
                            'title' => [
                                ['plain_text' => 'Hello'],
                            ],
                        ],
                    ],
                ]],
                'next_cursor' => null,
                'has_more' => false,
            ], 200),
            'api.notion.com/v1/blocks/*' => Http::response([
                'results' => [
                    [
                        'id' => 'b1',
                        'type' => 'heading_1',
                        'has_children' => false,
                        'heading_1' => [
                            'rich_text' => [['plain_text' => 'My Heading', 'annotations' => []]],
                        ],
                    ],
                    [
                        'id' => 'b2',
                        'type' => 'paragraph',
                        'has_children' => false,
                        'paragraph' => [
                            'rich_text' => [['plain_text' => 'body text', 'annotations' => []]],
                        ],
                    ],
                ],
                'next_cursor' => null,
                'has_more' => false,
            ], 200),
        ]);

        $this->connector()->syncFull($installation->id);

        $disk = Storage::disk((string) config('kb.sources.disk', 'kb'));
        $files = $disk->allFiles();
        $this->assertNotEmpty($files);
        $contents = $disk->get($files[0]);
        $this->assertStringContainsString('# Hello', $contents);
        $this->assertStringContainsString('# My Heading', $contents);
        $this->assertStringContainsString('body text', $contents);
    }

    public function test_sync_full_writes_to_kb_sources_disk_with_path_prefix(): void
    {
        Queue::fake();
        config()->set('kb.sources.disk', 'kb');
        config()->set('kb.sources.path_prefix', 'tenant-prefix');
        Storage::fake('kb');

        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id, extra: ['workspace_id' => 'ws-1']);

        Http::fake([
            'api.notion.com/v1/search' => Http::response([
                'results' => [[
                    'id' => 'page-yyy',
                    'last_edited_time' => '2026-05-10T10:00:00Z',
                    'properties' => [],
                ]],
                'next_cursor' => null,
                'has_more' => false,
            ], 200),
            'api.notion.com/v1/blocks/*' => Http::response([
                'results' => [],
                'next_cursor' => null,
                'has_more' => false,
            ], 200),
        ]);

        $this->connector()->syncFull($installation->id);

        $disk = Storage::disk('kb');
        $files = $disk->allFiles();
        $this->assertCount(1, $files);
        $this->assertStringStartsWith('tenant-prefix/', $files[0]);

        // Dispatched job carries the UN-prefixed relative path.
        Queue::assertPushed(IngestDocumentJob::class, function (IngestDocumentJob $job) {
            return ! str_starts_with($job->relativePath, 'tenant-prefix/')
                && str_contains($job->relativePath, 'page-yyy');
        });
    }

    public function test_sync_full_stores_notion_page_id_in_metadata(): void
    {
        Queue::fake();
        Storage::fake('kb');

        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id, extra: ['workspace_id' => 'ws-1']);

        Http::fake([
            'api.notion.com/v1/search' => Http::response([
                'results' => [[
                    'id' => 'page-zzz',
                    'last_edited_time' => '2026-05-10T10:00:00Z',
                    'properties' => [],
                ]],
                'next_cursor' => null,
                'has_more' => false,
            ], 200),
            'api.notion.com/v1/blocks/*' => Http::response([
                'results' => [],
                'next_cursor' => null,
                'has_more' => false,
            ], 200),
        ]);

        $this->connector()->syncFull($installation->id);

        Queue::assertPushed(IngestDocumentJob::class, function (IngestDocumentJob $job) {
            return ($job->metadata['notion_page_id'] ?? null) === 'page-zzz'
                && ($job->metadata['notion_workspace_id'] ?? null) === 'ws-1'
                && ($job->metadata['connector'] ?? null) === 'notion';
        });
    }

    public function test_sync_incremental_filters_by_last_edited_time(): void
    {
        Queue::fake();
        Storage::fake('kb');

        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id, extra: ['workspace_id' => 'ws-1']);

        $since = Carbon::parse('2026-05-10T12:00:00Z');

        Http::fake([
            'api.notion.com/v1/search' => Http::response([
                'results' => [
                    // Fresh — must be processed
                    [
                        'id' => 'page-fresh',
                        'last_edited_time' => '2026-05-10T13:00:00Z',
                        'properties' => [],
                    ],
                    // Stale — must be skipped
                    [
                        'id' => 'page-stale',
                        'last_edited_time' => '2026-05-10T09:00:00Z',
                        'properties' => [],
                    ],
                ],
                'next_cursor' => null,
                'has_more' => false,
            ], 200),
            'api.notion.com/v1/blocks/*' => Http::response([
                'results' => [],
                'next_cursor' => null,
                'has_more' => false,
            ], 200),
        ]);

        $result = $this->connector()->syncIncremental($installation->id, $since);

        $this->assertSame(0, $result->documentsAdded);
        $this->assertSame(1, $result->documentsUpdated);
        Queue::assertPushed(IngestDocumentJob::class, 1);
    }

    public function test_sync_incremental_archived_pages_soft_deleted_via_helper(): void
    {
        Queue::fake();
        Storage::fake('kb');

        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id, extra: ['workspace_id' => 'ws-1']);

        $doc = $this->seedKnowledgeDocument($installation->tenant_id, 'page-archived');

        $since = Carbon::parse('2026-05-10T12:00:00Z');

        Http::fake([
            'api.notion.com/v1/search' => Http::response([
                'results' => [[
                    'id' => 'page-archived',
                    'last_edited_time' => '2026-05-10T13:00:00Z',
                    'archived' => true,
                    'properties' => [],
                ]],
                'next_cursor' => null,
                'has_more' => false,
            ], 200),
        ]);

        $result = $this->connector()->syncIncremental($installation->id, $since);

        $this->assertSame(1, $result->documentsRemoved);
        $this->assertSoftDeleted('knowledge_documents', ['id' => $doc->id]);
    }

    public function test_sync_incremental_falls_back_to_full_when_no_cursor(): void
    {
        Queue::fake();
        Storage::fake('kb');

        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id, extra: ['workspace_id' => 'ws-1']);

        Http::fake([
            'api.notion.com/v1/search' => Http::response([
                'results' => [],
                'next_cursor' => null,
                'has_more' => false,
            ], 200),
        ]);

        $result = $this->connector()->syncIncremental($installation->id, null);

        $this->assertSame(0, $result->documentsAdded);
        $this->assertSame(0, $result->documentsUpdated);
    }

    public function test_disconnect_clears_credentials_without_provider_revoke_call(): void
    {
        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id);

        // Fake every Http call — assert below that we DO NOT call any
        // Notion endpoint during disconnect (Notion has no revoke API).
        Http::fake();

        $this->connector()->disconnect($installation->id);

        Http::assertNothingSent();
        $this->assertDatabaseMissing('connector_credentials', [
            'connector_installation_id' => $installation->id,
        ]);
    }

    public function test_health_pings_users_me_endpoint(): void
    {
        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id);

        Http::fake([
            'api.notion.com/v1/users/me' => Http::response([
                'id' => 'bot-1',
                'type' => 'bot',
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

    public function test_tenant_isolation_blocks_cross_tenant_sync(): void
    {
        Queue::fake();
        Storage::fake('kb');

        // tenant-a's installation; tenant-b has a doc with the same
        // notion_page_id (different Notion workspace, same page id is
        // unlikely but the test asserts the boundary regardless).
        $installationA = $this->makeInstallation('tenant-a');
        $this->seedActiveCredential(
            $installationA->id,
            extra: ['workspace_id' => 'ws-1'],
            tenantId: 'tenant-a',
        );

        $docB = $this->seedKnowledgeDocument('tenant-b', 'page-shared');

        Http::fake([
            'api.notion.com/v1/search' => Http::response([
                'results' => [[
                    'id' => 'page-shared',
                    'last_edited_time' => '2026-05-10T13:00:00Z',
                    'archived' => true,
                    'properties' => [],
                ]],
                'next_cursor' => null,
                'has_more' => false,
            ], 200),
        ]);

        $this->app->make(TenantContext::class)->set('tenant-a');
        $result = $this->connector()->syncIncremental(
            $installationA->id,
            Carbon::parse('2026-05-10T12:00:00Z'),
        );
        $this->app->make(TenantContext::class)->reset();

        // No matching doc in tenant-a → counter stays at 0.
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
        $this->seedActiveCredential($installation->id, extra: ['workspace_id' => 'ws-1']);

        Http::fake([
            'api.notion.com/v1/search' => Http::response([
                'results' => [[
                    'id' => 'page-pii',
                    'last_edited_time' => '2026-05-10T10:00:00Z',
                    'properties' => [],
                ]],
                'next_cursor' => null,
                'has_more' => false,
            ], 200),
            'api.notion.com/v1/blocks/*' => Http::response([
                'results' => [[
                    'id' => 'b1',
                    'type' => 'paragraph',
                    'has_children' => false,
                    'paragraph' => [
                        'rich_text' => [['plain_text' => 'mail me at user@example.com today', 'annotations' => []]],
                    ],
                ]],
                'next_cursor' => null,
                'has_more' => false,
            ], 200),
        ]);

        $this->connector()->syncFull($installation->id);

        $disk = Storage::disk((string) config('kb.sources.disk', 'kb'));
        $files = $disk->allFiles();
        $this->assertNotEmpty($files);
        $contents = $disk->get($files[0]);
        $this->assertStringNotContainsString('user@example.com', $contents);
    }
}
