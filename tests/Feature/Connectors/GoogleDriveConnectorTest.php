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

        // iter2 finding #7 — the connector now only counts a
        // deletion when the corresponding knowledge_documents row is
        // actually present. Seed one so the assertion below is
        // meaningful.
        $this->seedKnowledgeDocument($installation->tenant_id, 'file-deleted');

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

        // The seeded document is now soft-deleted.
        $this->assertSoftDeleted('knowledge_documents', [
            'project_key' => 'connector-google-drive',
            'tenant_id' => $installation->tenant_id,
        ]);
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

    /**
     * iter2 finding #6 — connector MUST write to `config('kb.sources.disk')`
     * and apply `config('kb.sources.path_prefix')`. The earlier impl
     * used a non-existent `kb.disk` config key and skipped the prefix,
     * making Drive-ingested docs invisible to RAG on any host with
     * KB_PATH_PREFIX set.
     */
    public function test_google_drive_sync_writes_to_kb_sources_disk_with_path_prefix(): void
    {
        Queue::fake();
        config()->set('kb.sources.disk', 'kb');
        config()->set('kb.sources.path_prefix', 'tenant-prefix');
        Storage::fake('kb');

        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id, 'AT-xyz');

        Http::fake([
            'www.googleapis.com/drive/v3/files/file-md*' => Http::response('# body', 200),
            'www.googleapis.com/drive/v3/changes/startPageToken' => Http::response(['startPageToken' => 'cursor-x'], 200),
            'www.googleapis.com/drive/v3/files*' => Http::response([
                'files' => [['id' => 'file-md', 'name' => 'doc.md', 'mimeType' => 'text/markdown']],
            ], 200),
        ]);

        $this->connector()->syncFull($installation->id);

        // Physical path on disk MUST be prefix-applied.
        $disk = Storage::disk('kb');
        $files = $disk->allFiles();
        $this->assertCount(1, $files);
        $this->assertStringStartsWith('tenant-prefix/', $files[0]);

        // The dispatched IngestDocumentJob MUST receive the UN-prefixed
        // relative path (ParseMarkdownStep re-applies the prefix when
        // reading). Otherwise the prefix gets applied twice and the
        // ingest reads the wrong path.
        Queue::assertPushed(\App\Jobs\IngestDocumentJob::class, function (\App\Jobs\IngestDocumentJob $job) {
            return ! str_starts_with($job->relativePath, 'tenant-prefix/')
                && str_contains($job->relativePath, 'file-md');
        });
    }

    /**
     * iter2 finding #7 — Drive deletion events MUST soft-delete the
     * corresponding `knowledge_documents` row (looked up by
     * `metadata->drive_file_id`). Without this, removed Drive files
     * linger in RAG indefinitely and `documentsRemoved` is misleading.
     */
    public function test_drive_deletion_event_soft_deletes_knowledge_document(): void
    {
        Queue::fake();
        Storage::fake('kb');

        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id, 'AT-xyz', extra: ['changes_page_token' => 'cursor-1']);

        $doc = $this->seedKnowledgeDocument($installation->tenant_id, 'file-to-delete');

        Http::fake([
            'www.googleapis.com/drive/v3/changes*' => Http::response([
                'changes' => [['fileId' => 'file-to-delete', 'removed' => true]],
                'newStartPageToken' => 'cursor-2',
            ], 200),
        ]);

        $result = $this->connector()->syncIncremental($installation->id, Carbon::now()->subHour());

        $this->assertSame(1, $result->documentsRemoved);
        $this->assertSoftDeleted('knowledge_documents', ['id' => $doc->id]);
    }

    /**
     * iter2 finding #7 — when a Drive deletion event references a
     * file_id we never ingested (or we already trashed), the counter
     * does NOT increment. Honest metric beats inflated metric.
     */
    public function test_drive_deletion_event_with_no_matching_document_does_not_increment_counter(): void
    {
        Queue::fake();
        Storage::fake('kb');

        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id, 'AT-xyz', extra: ['changes_page_token' => 'cursor-1']);

        Http::fake([
            'www.googleapis.com/drive/v3/changes*' => Http::response([
                'changes' => [['fileId' => 'never-seen-file', 'removed' => true]],
                'newStartPageToken' => 'cursor-2',
            ], 200),
        ]);

        $result = $this->connector()->syncIncremental($installation->id, Carbon::now()->subHour());

        $this->assertSame(0, $result->documentsRemoved);
    }

    /**
     * iter2 finding #7 — cross-tenant guard. A deletion event in
     * tenant A MUST NOT soft-delete a document in tenant B that
     * happens to carry the same drive_file_id (different Google
     * accounts, same file id is a real-world possibility).
     */
    public function test_drive_deletion_event_respects_tenant_boundary(): void
    {
        Queue::fake();
        Storage::fake('kb');

        $installationA = $this->makeInstallation('tenant-a');
        $this->seedActiveCredential(
            $installationA->id,
            'AT-xyz',
            extra: ['changes_page_token' => 'cursor-1'],
            tenantId: 'tenant-a',
        );

        // Same drive_file_id under tenant B; MUST NOT be touched.
        $docB = $this->seedKnowledgeDocument('tenant-b', 'shared-file-id');

        Http::fake([
            'www.googleapis.com/drive/v3/changes*' => Http::response([
                'changes' => [['fileId' => 'shared-file-id', 'removed' => true]],
                'newStartPageToken' => 'cursor-2',
            ], 200),
        ]);

        app(\App\Support\TenantContext::class)->set('tenant-a');
        $result = $this->connector()->syncIncremental($installationA->id, Carbon::now()->subHour());
        app(\App\Support\TenantContext::class)->reset();

        // No document in tenant-a → counter stays at 0.
        $this->assertSame(0, $result->documentsRemoved);
        // tenant-b's document is untouched.
        $this->assertDatabaseHas('knowledge_documents', [
            'id' => $docB->id,
            'deleted_at' => null,
        ]);
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
        $disk = Storage::disk((string) config('kb.sources.disk', 'kb'));
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
     * iter2 finding #7 — seed a knowledge_documents row carrying
     * the given drive_file_id in its metadata, so the deletion
     * handler can look it up.
     */
    private function seedKnowledgeDocument(string $tenantId, string $driveFileId): \App\Models\KnowledgeDocument
    {
        return \App\Models\KnowledgeDocument::create([
            'tenant_id' => $tenantId,
            'project_key' => 'connector-google-drive',
            'source_type' => 'markdown',
            'title' => 'Drive doc for '.$driveFileId,
            'source_path' => 'connector-google-drive/connectors/google-drive/installation-x/'.$driveFileId.'.md',
            'mime_type' => 'text/markdown',
            'document_hash' => str_repeat('a', 64),
            'version_hash' => str_repeat('b', 64),
            'metadata' => [
                'connector' => 'google-drive',
                'drive_file_id' => $driveFileId,
            ],
        ]);
    }
}
