<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Connectors\BuiltIn\EvernoteConnector;
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
 * v4.5/W4 — EvernoteConnector OAuth + sync behaviour.
 *
 * The connector talks to Evernote via raw `Http::` (no SDK). Every API
 * interaction is stubbed via Http::fake.
 */
final class EvernoteConnectorTest extends TestCase
{
    use RefreshDatabase;

    private function connector(): EvernoteConnector
    {
        return $this->app->make(EvernoteConnector::class);
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
            'connector_name' => 'evernote',
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

    private function seedActiveCredential(
        int $installationId,
        string $access = 'AT-evernote',
        ?string $refresh = 'RT-evernote',
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

    private function seedKnowledgeDocument(string $tenantId, string $noteGuid): KnowledgeDocument
    {
        return KnowledgeDocument::create([
            'tenant_id' => $tenantId,
            'project_key' => 'connector-evernote',
            'source_type' => 'markdown',
            'title' => 'Evernote note '.$noteGuid,
            'source_path' => 'connector-evernote/connectors/evernote/installation-1/'.$noteGuid.'.md',
            'mime_type' => 'text/markdown',
            'document_hash' => str_repeat('a', 64),
            'version_hash' => str_repeat('b', 64),
            'metadata' => [
                'connector' => 'evernote',
                'evernote_note_guid' => $noteGuid,
            ],
        ]);
    }

    public function test_initiate_oauth_returns_evernote_auth_url_with_state_token(): void
    {
        config()->set('connectors.providers.evernote.client_id', 'cid');
        config()->set('connectors.providers.evernote.redirect_uri', 'http://localhost/cb');

        $installation = $this->makeInstallation();

        $url = $this->connector()->initiateOAuth($installation->id);

        $this->assertStringStartsWith('https://www.evernote.com/oauth2/authorize?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('cid', $query['client_id']);
        $this->assertSame('http://localhost/cb', $query['redirect_uri']);
        $this->assertSame('code', $query['response_type']);
        $this->assertNotEmpty($query['state']);
    }

    public function test_oauth_callback_exchanges_code_and_stores_tokens(): void
    {
        config()->set('connectors.providers.evernote.client_id', 'cid');
        config()->set('connectors.providers.evernote.client_secret', 'cs');
        config()->set('connectors.providers.evernote.redirect_uri', 'http://localhost/cb');

        $installation = $this->makeInstallation();
        $state = $this->initiateAndExtractState($installation->id);

        Http::fake([
            'evernote.com/oauth2/token' => Http::response([
                'access_token' => 'AT-new',
                'refresh_token' => 'RT-new',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
                'user_id' => 'user-42',
                'shard' => 's1',
            ], 200),
        ]);

        $req = Request::create('/cb', 'GET', ['code' => 'auth-code', 'state' => $state]);
        $this->connector()->handleOAuthCallback($installation->id, $req);

        $row = ConnectorCredential::query()
            ->where('connector_installation_id', $installation->id)
            ->first();
        $this->assertNotNull($row);
        $this->assertSame('AT-new', Crypt::decryptString($row->encrypted_access_token));
        $this->assertSame('RT-new', Crypt::decryptString($row->encrypted_refresh_token));
        $this->assertSame('user-42', $row->extra_json['evernote_user_id']);
        $this->assertSame('s1', $row->extra_json['evernote_shard']);
    }

    public function test_oauth_callback_throws_on_invalid_state(): void
    {
        $installation = $this->makeInstallation();
        // Skip initiate → no state token cached.

        $req = Request::create('/cb', 'GET', ['code' => 'auth-code', 'state' => 'forged']);

        $this->expectException(\App\Connectors\Exceptions\ConnectorAuthException::class);
        $this->connector()->handleOAuthCallback($installation->id, $req);
    }

    public function test_oauth_callback_throws_on_token_exchange_failure(): void
    {
        $installation = $this->makeInstallation();
        $state = $this->initiateAndExtractState($installation->id);

        Http::fake([
            'evernote.com/oauth2/token' => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        $req = Request::create('/cb', 'GET', ['code' => 'bad-code', 'state' => $state]);

        $this->expectException(\App\Connectors\Exceptions\ConnectorAuthException::class);
        $this->connector()->handleOAuthCallback($installation->id, $req);
    }

    public function test_sync_full_walks_notes_search_and_ingests_each_note(): void
    {
        Queue::fake();
        Storage::fake('kb');

        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id);

        Http::fake([
            'api.evernote.com/v1/notes/search' => Http::response([
                'notes' => [
                    ['guid' => 'note-a', 'title' => 'Note A', 'updated' => 1620000000000],
                    ['guid' => 'note-b', 'title' => 'Note B', 'updated' => 1620000001000],
                ],
                'totalNotes' => 2,
            ], 200),
            'api.evernote.com/v1/notes/note-a*' => Http::response([
                'guid' => 'note-a',
                'title' => 'Note A',
                'content' => '<?xml version="1.0" encoding="UTF-8"?><en-note><p>body A</p></en-note>',
            ], 200),
            'api.evernote.com/v1/notes/note-b*' => Http::response([
                'guid' => 'note-b',
                'title' => 'Note B',
                'content' => '<?xml version="1.0" encoding="UTF-8"?><en-note><p>body B</p></en-note>',
            ], 200),
        ]);

        $result = $this->connector()->syncFull($installation->id);

        $this->assertSame([], $result->errors);
        $this->assertSame(2, $result->documentsAdded);
        $this->assertSame(0, $result->documentsRemoved);

        Queue::assertPushed(IngestDocumentJob::class, 2);
    }

    public function test_sync_full_writes_markdown_to_kb_disk_with_title_heading(): void
    {
        Queue::fake();
        Storage::fake('kb');

        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id);

        Http::fake([
            'api.evernote.com/v1/notes/search' => Http::response([
                'notes' => [
                    ['guid' => 'note-x', 'title' => 'Hello World'],
                ],
                'totalNotes' => 1,
            ], 200),
            'api.evernote.com/v1/notes/note-x*' => Http::response([
                'guid' => 'note-x',
                'title' => 'Hello World',
                'content' => '<en-note><h1>Section</h1><p>some body text</p></en-note>',
            ], 200),
        ]);

        $this->connector()->syncFull($installation->id);

        $disk = Storage::disk((string) config('kb.sources.disk', 'kb'));
        $files = $disk->allFiles();
        $this->assertNotEmpty($files);
        $contents = $disk->get($files[0]);
        $this->assertStringContainsString('# Hello World', $contents);
        $this->assertStringContainsString('# Section', $contents);
        $this->assertStringContainsString('some body text', $contents);
    }

    public function test_sync_full_stores_note_guid_in_dispatched_metadata(): void
    {
        Queue::fake();
        Storage::fake('kb');

        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id);

        Http::fake([
            'api.evernote.com/v1/notes/search' => Http::response([
                'notes' => [['guid' => 'note-meta', 'title' => 'M']],
                'totalNotes' => 1,
            ], 200),
            'api.evernote.com/v1/notes/note-meta*' => Http::response([
                'guid' => 'note-meta',
                'title' => 'M',
                'content' => '<en-note><p>body</p></en-note>',
                'notebookGuid' => 'nb-1',
            ], 200),
        ]);

        $this->connector()->syncFull($installation->id);

        Queue::assertPushed(IngestDocumentJob::class, function (IngestDocumentJob $job) {
            return ($job->metadata['evernote_note_guid'] ?? null) === 'note-meta'
                && ($job->metadata['connector'] ?? null) === 'evernote'
                && ($job->metadata['evernote_source'] ?? null) === 'oauth';
        });
    }

    public function test_sync_incremental_processes_changed_notes_only(): void
    {
        Queue::fake();
        Storage::fake('kb');

        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id);

        $since = Carbon::parse('2026-05-10T12:00:00Z');

        Http::fake([
            'api.evernote.com/v1/notes/search' => Http::response([
                'notes' => [
                    ['guid' => 'note-fresh', 'title' => 'Fresh', 'updated' => 1620000003000],
                ],
                'totalNotes' => 1,
            ], 200),
            'api.evernote.com/v1/notes/note-fresh*' => Http::response([
                'guid' => 'note-fresh',
                'title' => 'Fresh',
                'content' => '<en-note><p>fresh content</p></en-note>',
            ], 200),
        ]);

        $result = $this->connector()->syncIncremental($installation->id, $since);

        $this->assertSame(0, $result->documentsAdded);
        $this->assertSame(1, $result->documentsUpdated);
        Queue::assertPushed(IngestDocumentJob::class, 1);
    }

    public function test_sync_incremental_deleted_notes_soft_deleted_via_helper(): void
    {
        Queue::fake();
        Storage::fake('kb');

        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id);

        $doc = $this->seedKnowledgeDocument($installation->tenant_id, 'note-bye');

        $since = Carbon::parse('2026-05-10T12:00:00Z');

        Http::fake([
            'api.evernote.com/v1/notes/search' => Http::response([
                'notes' => [
                    [
                        'guid' => 'note-bye',
                        'title' => 'Removed',
                        'deleted' => 1620000004000,
                    ],
                ],
                'totalNotes' => 1,
            ], 200),
        ]);

        $result = $this->connector()->syncIncremental($installation->id, $since);

        $this->assertSame(1, $result->documentsRemoved);
        $this->assertSoftDeleted('knowledge_documents', ['id' => $doc->id]);
    }

    public function test_sync_incremental_falls_back_to_full_when_no_watermark(): void
    {
        Queue::fake();
        Storage::fake('kb');

        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id);

        Http::fake([
            'api.evernote.com/v1/notes/search' => Http::response([
                'notes' => [],
                'totalNotes' => 0,
            ], 200),
        ]);

        $result = $this->connector()->syncIncremental($installation->id, null);

        $this->assertSame(0, $result->documentsAdded);
        $this->assertSame(0, $result->documentsUpdated);
    }

    public function test_disconnect_calls_provider_revoke_and_clears_credentials(): void
    {
        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id);

        Http::fake();

        $this->connector()->disconnect($installation->id);

        // Best-effort revoke call MUST have been issued.
        Http::assertSent(function ($req) {
            return str_contains((string) $req->url(), '/oauth2/revoke');
        });
        $this->assertDatabaseMissing('connector_credentials', [
            'connector_installation_id' => $installation->id,
        ]);
    }

    public function test_health_pings_users_me(): void
    {
        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id);

        Http::fake([
            'api.evernote.com/v1/users/me' => Http::response(['user' => ['id' => 1]], 200),
        ]);

        $status = $this->connector()->health($installation->id);
        $this->assertSame(HealthStatus::STATE_HEALTHY, $status->state);
    }

    public function test_health_returns_errored_without_credentials(): void
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
        $this->seedActiveCredential($installationA->id, tenantId: 'tenant-a');

        $docB = $this->seedKnowledgeDocument('tenant-b', 'note-shared');

        Http::fake([
            'api.evernote.com/v1/notes/search' => Http::response([
                'notes' => [
                    [
                        'guid' => 'note-shared',
                        'deleted' => 1620000004000,
                    ],
                ],
                'totalNotes' => 1,
            ], 200),
        ]);

        $this->app->make(TenantContext::class)->set('tenant-a');
        $result = $this->connector()->syncIncremental(
            $installationA->id,
            Carbon::parse('2026-05-10T12:00:00Z'),
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
        $this->seedActiveCredential($installation->id);

        Http::fake([
            'api.evernote.com/v1/notes/search' => Http::response([
                'notes' => [['guid' => 'note-pii']],
                'totalNotes' => 1,
            ], 200),
            'api.evernote.com/v1/notes/note-pii*' => Http::response([
                'guid' => 'note-pii',
                'title' => 'Sensitive',
                'content' => '<en-note><p>mail me at user@example.com today</p></en-note>',
            ], 200),
        ]);

        $this->connector()->syncFull($installation->id);

        $disk = Storage::disk((string) config('kb.sources.disk', 'kb'));
        $files = $disk->allFiles();
        $this->assertNotEmpty($files);
        $contents = $disk->get($files[0]);
        $this->assertStringNotContainsString('user@example.com', $contents);
    }

    public function test_icon_url_resolves_to_existing_file(): void
    {
        $url = $this->connector()->iconUrl();
        $this->assertStringEndsWith('/connectors/evernote.svg', $url);

        $repoRoot = realpath(__DIR__.'/../../../');
        $this->assertNotFalse($repoRoot);
        $this->assertFileExists($repoRoot.'/public/connectors/evernote.svg');
    }
}
