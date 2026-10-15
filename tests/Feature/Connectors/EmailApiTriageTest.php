<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Connectors\HostIngestionBridge;
use App\Jobs\EmailTriage\AnalyzeEmailJob;
use App\Jobs\IngestDocumentJob;
use App\Models\EmailTriageEvaluation;
use App\Models\EmailTriageMessage;
use App\Models\EmailTriageProfile;
use App\Models\KbTag;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeDocumentAcl;
use App\Services\EmailTriage\EmailConnectorRegistry;
use App\Services\EmailTriage\EmailEnvelope;
use App\Services\EmailTriage\EmailTriageService;
use App\Services\EmailTriage\TriageProfiles;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class EmailApiTriageTest extends TestCase
{
    use RefreshDatabase;

    private const BODY = "# Shipment\n\nThe shipment is late. I am unhappy.";

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        Storage::fake('kb');
        config(['kb.sources.disk' => 'kb', 'kb.sources.path_prefix' => '', 'kb.ocr.enabled' => false,
            'ai.providers.openrouter.key' => 'synthetic-key']);
    }

    public static function apiProviders(): array
    {
        return ['Gmail' => ['gmail'], 'Microsoft Mail' => ['microsoft-mail']];
    }

    private function installation(string $connector, bool $enabled = true, string $tenant = 'test-tenant'): ConnectorInstallation
    {
        $installation = ConnectorInstallation::create(['tenant_id' => $tenant, 'connector_name' => $connector,
            'label' => uniqid('fixture-'), 'project_key' => 'mail', 'status' => 'active', 'config_json' => []]);
        if ($enabled) {
            $profile = EmailTriageProfile::create(['tenant_id' => $tenant, 'connector_installation_id' => $installation->id,
                'revision' => 1, 'enabled' => true, 'model' => config('decisions.models.jev.model'),
                'rules' => [['key' => 'complaint', 'label' => 'Complaint', 'type' => 'noul', 'enabled' => true,
                    'instructions' => 'Is the sender complaining?', 'threshold' => 0.85,
                    'options' => [['key' => 'true', 'label' => 'Yes', 'criterion' => 'Dissatisfaction', 'text' => 'Complaint', 'tag' => 'complaint'],
                        ['key' => 'false', 'label' => 'No', 'criterion' => 'Ordinary request', 'text' => 'Ordinary request', 'tag' => null]]]]]);
            $installation->forceFill(['config_json' => ['email_triage_profile_id' => $profile->id]])->save();
        }

        return $installation;
    }

    private function envelope(ConnectorInstallation $installation, array $files = [], string $body = self::BODY, string $mailbox = 'account-sub'): array
    {
        $key = $installation->connector_name.':'.hash('sha256', json_encode([
            (string) $installation->tenant_id, (int) $installation->id, $installation->connector_name, $mailbox, 'NativeID-CaseSensitive',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $metadata = ['connector' => $installation->connector_name, 'installation_id' => (int) $installation->id,
            'email_schema_version' => 1, 'email_provider' => $installation->connector_name,
            'email_mailbox_id' => $mailbox, 'email_message_id' => 'NativeID-CaseSensitive', 'email_message_key' => $key,
            'email_folder_ids' => ['inbox-id'], 'email_folder_names' => ['Inbox'], 'email_date' => '2026-10-14T10:00:00+00:00',
            'email_attachment_count' => count($files), 'email_body_hash' => hash('sha256', $body),
            'email_revision_hash' => app(EmailEnvelope::class)->revisionHash(hash('sha256', $body), $files)];

        return ['schema_version' => 1, 'tenant_id' => (string) $installation->tenant_id,
            'installation_id' => (int) $installation->id, 'project_key' => 'mail', 'metadata' => $metadata,
            'email_date' => $metadata['email_date'], 'manifest' => $files];
    }

    private function file(int $slot, string $id, string $content = 'Reference', string $mime = 'text/plain'): array
    {
        return ['slot' => $slot, 'id' => $id, 'name' => 'same-name.txt', 'mime' => $mime,
            'hash' => hash('sha256', $content), 'status' => 'pending', 'reason' => null];
    }

    private function prepare(array $envelope): void
    {
        Event::dispatch('askmydocs.email.prepared.v1', [$envelope]);
    }

    private function body(array $envelope, string $body = self::BODY): void
    {
        Storage::disk('kb')->put('mail/body.md', $body);
        app(HostIngestionBridge::class)->dispatchIngestion('mail', 'mail/body.md', 'kb', 'Shipment',
            $envelope['metadata'], 'text/markdown', $envelope['tenant_id']);
    }

    private function attachment(array $envelope, array $file, string $contents = 'Reference'): void
    {
        $path = 'mail/file-'.$file['slot'].'.txt';
        Storage::disk('kb')->put($path, $contents);
        $metadata = $envelope['metadata'] + ['attachment_filename' => $file['name'],
            'attachment_of_message_id' => $envelope['metadata']['email_message_id'],
            'email_attachment_id' => $file['id'], 'email_attachment_slot' => $file['slot']];
        app(HostIngestionBridge::class)->dispatchIngestion('mail', $path, 'kb', $file['name'], $metadata, $file['mime'], $envelope['tenant_id']);
    }

    #[DataProvider('apiProviders')]
    public function test_off_profile_validates_prepared_scope_and_preserves_plain_ingestion(string $provider): void
    {
        $installation = $this->installation($provider, false);
        $envelope = $this->envelope($installation);
        $this->assertTrue(app(TriageProfiles::class)->supports($installation));
        $this->prepare($envelope);
        $this->assertSame([], Storage::disk('kb')->allFiles());
        $this->assertSame(0, EmailTriageMessage::count());
        // The API cannot impersonate the host's reserved generated IMAP fixtures.
        $envelope['metadata'] += ['imap_message_id' => 'dataset.'.str_repeat('a', 64).'@fixtures.askmydocs.invalid', 'generated_fixture' => true];
        $this->body($envelope);
        Queue::assertPushed(IngestDocumentJob::class, fn ($job) => ! isset($job->metadata['generated_fixture']));
        Queue::assertNotPushed(AnalyzeEmailJob::class);
        Http::assertNothingSent();
    }

    #[DataProvider('apiProviders')]
    public function test_sync_listener_rejects_invalid_commitments_before_persistence(string $provider): void
    {
        $installation = $this->installation($provider);
        $valid = $this->envelope($installation, [$this->file(0, 'part-a')]);
        foreach (['schema', 'key', 'revision', 'project', 'tenant', 'count', 'slot', 'hash'] as $case) {
            $invalid = $valid;
            match ($case) {
                'schema' => $invalid['schema_version'] = 2,
                'key' => $invalid['metadata']['email_message_key'] = $provider.':'.str_repeat('0', 64),
                'revision' => $invalid['metadata']['email_revision_hash'] = str_repeat('0', 64),
                'project' => $invalid['project_key'] = 'other-project',
                'tenant' => $invalid['tenant_id'] = 'other-tenant',
                'count' => $invalid['metadata']['email_attachment_count'] = 2,
                'slot' => $invalid['manifest'][0]['slot'] = '0',
                'hash' => $invalid['manifest'][0]['hash'] = null,
            };
            try {
                $this->prepare($invalid);
                $this->fail('Invalid '.$case.' must propagate from the synchronous listener.');
            } catch (\RuntimeException|ModelNotFoundException) {
                $this->assertSame(0, EmailTriageMessage::count());
                $this->assertSame([], Storage::disk('kb')->allFiles());
            }
        }
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    #[DataProvider('apiProviders')]
    public function test_same_name_attachments_use_id_slot_receipts_and_complete_without_download_ai(string $provider): void
    {
        $envelope = $this->envelope($this->installation($provider), [$this->file(0, 'part-a'), $this->file(1, 'part-b')]);
        $this->prepare($envelope);
        $this->body($envelope);
        $this->assertSame('awaiting_files', EmailTriageMessage::first()->status);
        $this->attachment($envelope, $envelope['manifest'][0]);
        $message = EmailTriageMessage::first();
        $this->assertSame('saved', $message->manifest[0]['status']);
        $this->assertSame('pending', $message->manifest[1]['status']);
        $this->attachment($envelope, $envelope['manifest'][1]);
        $this->assertSame('waiting', $message->fresh()->status);
        Queue::assertPushed(IngestDocumentJob::class, 2);
        Queue::assertPushed(AnalyzeEmailJob::class, 1);
        Http::assertNothingSent();
        $this->assertSame(self::BODY, app(EmailTriageService::class)->original($message->fresh()));
    }

    #[DataProvider('apiProviders')]
    public function test_completed_replay_keeps_terminal_receipts_and_reuses_the_decision_and_index(string $provider): void
    {
        $envelope = $this->envelope($this->installation($provider), [$this->file(0, 'part-a')]);
        $this->prepare($envelope);
        $this->body($envelope);
        $this->attachment($envelope, $envelope['manifest'][0]);
        Http::fake(['openrouter.ai/*' => Http::response(['id' => 'synthetic-triage', 'model' => config('decisions.models.jev.model'),
            'answers' => ['complaint' => ['type' => 'noul', 'noul' => 0.97]], 'usage' => ['cost' => 0.00001]])]);
        $service = app(EmailTriageService::class);
        $message = EmailTriageMessage::first();
        $service->process($message->evaluation_id, 'test-tenant');
        $terminal = $message->fresh()->manifest;
        $envelope['metadata']['email_folder_ids'] = ['archive-id'];
        $envelope['metadata']['email_folder_names'] = ['Renamed archive'];
        $this->prepare($envelope);
        $this->assertSame($terminal, $message->fresh()->manifest);
        $this->body($envelope);
        $this->attachment($envelope, $envelope['manifest'][0]);
        $service->process($message->evaluation_id, 'test-tenant');
        $this->assertSame(1, EmailTriageEvaluation::count());
        $this->assertSame(['archive-id'], $message->fresh()->metadata['email_folder_ids']);
        Queue::assertPushed(IngestDocumentJob::class, 2); // One file, one body index.
        Http::assertSentCount(1);
        $this->assertSame(self::BODY, Storage::disk('kb')->get('mail/body.md'));
    }

    #[DataProvider('apiProviders')]
    public function test_new_revision_rejects_old_receipts_and_old_projection(string $provider): void
    {
        $old = $this->envelope($this->installation($provider));
        $this->prepare($old);
        $this->body($old);
        $message = EmailTriageMessage::first();
        $evaluation = EmailTriageEvaluation::first();
        $evaluation->forceFill(['status' => 'completed'])->save();
        $projection = ['email_triage' => ['evaluation_id' => $evaluation->id, 'original_hash' => $message->original_hash]];
        $new = $this->envelope(ConnectorInstallation::findOrFail($old['installation_id']), body: self::BODY.' A corrected detail.');
        $this->prepare($new);
        $this->assertNull($message->fresh()->evaluation_id);
        try {
            $this->body($old);
            $this->fail('Obsolete receipt must fail.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('obsolete revision', $error->getMessage());
        }
        try {
            app(EmailTriageService::class)->assertProjection('test-tenant', 'mail', 'mail/body.md', $projection);
            $this->fail('Obsolete job projection must fail.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('stale or invalid', $error->getMessage());
        }
        $this->body($new, self::BODY.' A corrected detail.');
        $this->assertNotSame($evaluation->id, $message->fresh()->evaluation_id);
        $this->assertSame(2, EmailTriageEvaluation::count());
        Http::assertNothingSent();
    }

    #[DataProvider('apiProviders')]
    public function test_failed_dispatch_can_retry_saved_receipt_then_suppresses_further_replay(string $provider): void
    {
        $envelope = $this->envelope($this->installation($provider), [$this->file(0, 'part-a')]);
        $this->prepare($envelope);
        $this->body($envelope);
        Storage::disk('kb')->put('mail/file-0.txt', 'Reference');
        $metadata = $envelope['metadata'] + ['attachment_filename' => 'same-name.txt',
            'attachment_of_message_id' => $envelope['metadata']['email_message_id'], 'email_attachment_id' => 'part-a', 'email_attachment_slot' => 0];
        $service = app(EmailTriageService::class);
        // Receipt saved, but the process terminates before dispatch acceptance.
        $this->assertFalse($service->receive('mail', 'mail/file-0.txt', 'kb', 'File', $metadata, 'test-tenant'));
        $this->assertArrayNotHasKey('ingest_dispatched', EmailTriageMessage::first()->manifest[0]);
        $this->attachment($envelope, $envelope['manifest'][0]);
        $this->attachment($envelope, $envelope['manifest'][0]);
        Queue::assertPushed(IngestDocumentJob::class, 1);
        $this->assertTrue(EmailTriageMessage::first()->manifest[0]['ingest_dispatched']);
    }

    #[DataProvider('apiProviders')]
    public function test_corrupt_body_or_wrong_attachment_slot_never_becomes_ready(string $provider): void
    {
        $envelope = $this->envelope($this->installation($provider), [$this->file(0, 'part-a')]);
        $this->prepare($envelope);
        try {
            $this->body($envelope, 'Uncommitted body');
            $this->fail('Body commitment mismatch must fail.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('prepared commitment', $error->getMessage());
        }
        $this->assertNull(EmailTriageMessage::first()->original_path);
        $this->body($envelope);
        $wrong = $envelope['manifest'][0];
        $wrong['slot'] = 1;
        try {
            $this->attachment($envelope, $wrong);
            $this->fail('Wrong attachment slot must fail.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('durable triage manifest', $error->getMessage());
        }
        $this->assertSame('pending', EmailTriageMessage::first()->manifest[0]['status']);
        Queue::assertNothingPushed();
    }

    #[DataProvider('apiProviders')]
    public function test_ocr_refusal_is_terminal_cleans_up_and_does_not_block_triage(string $provider): void
    {
        $envelope = $this->envelope($this->installation($provider), [$this->file(0, 'part-a', 'image-bytes', 'image/png')]);
        $this->prepare($envelope);
        $this->body($envelope);
        $this->attachment($envelope, $envelope['manifest'][0], 'image-bytes');
        $message = EmailTriageMessage::first();
        $this->assertSame('waiting', $message->status);
        $this->assertSame('ocr_disabled', $message->manifest[0]['status']);
        $this->assertFalse(Storage::disk('kb')->exists('mail/file-0.txt'));
        Queue::assertPushed(AnalyzeEmailJob::class, 1);
        Queue::assertNotPushed(IngestDocumentJob::class);
    }

    #[DataProvider('apiProviders')]
    public function test_mailbox_and_installation_identities_are_isolated(string $provider): void
    {
        $first = $this->installation($provider);
        $second = $this->installation($provider);
        $service = app(EmailTriageService::class);
        $keys = [];
        foreach ([$this->envelope($first), $this->envelope($first, mailbox: 'other-sub'), $this->envelope($second)] as $envelope) {
            $this->prepare($envelope);
            $keys[] = $service->identity($envelope['metadata']);
        }
        $this->assertCount(3, array_unique($keys));
        $this->assertSame(3, EmailTriageMessage::count());
        $this->assertSame(['inbox-id'], EmailConnectorRegistry::folders($this->envelope($first)['metadata']));
    }

    #[DataProvider('apiProviders')]
    public function test_api_projection_preserves_previous_access_and_snapshot_and_reuses_index_retry(string $provider): void
    {
        $installation = $this->installation($provider);
        $envelope = $this->envelope($installation);
        Storage::disk('kb')->put('mail/body.md', self::BODY);
        $old = KnowledgeDocument::create(['tenant_id' => 'test-tenant', 'project_key' => 'mail', 'source_path' => 'mail/body.md',
            'title' => 'Previous email', 'source_type' => 'markdown', 'mime_type' => 'text/markdown', 'status' => 'active',
            'access_scope' => 'restricted', 'document_hash' => hash('sha256', 'old'), 'version_hash' => hash('sha256', 'old'),
            'metadata' => $envelope['metadata']]);
        KnowledgeDocumentAcl::create(['tenant_id' => 'test-tenant', 'knowledge_document_id' => $old->id,
            'subject_type' => 'user', 'subject_id' => 999, 'permission' => 'view', 'effect' => 'deny', 'origin' => 'manual']);
        $this->prepare($envelope);
        $this->body($envelope);
        $service = app(EmailTriageService::class);
        $message = EmailTriageMessage::first();
        Http::fake(['openrouter.ai/*' => Http::response(['id' => 'synthetic-triage', 'model' => config('decisions.models.jev.model'),
            'answers' => ['complaint' => ['type' => 'noul', 'noul' => 0.97]], 'usage' => ['cost' => 0.00001]])]);
        $service->process($message->evaluation_id, 'test-tenant');
        $originalJob = Queue::pushed(IngestDocumentJob::class)->first();
        $originalJob->failed(new \RuntimeException('synthetic index failure'));
        $service->retry($message->fresh());
        $service->process($message->evaluation_id, 'test-tenant');
        Http::assertSentCount(1);
        $retry = Queue::pushed(IngestDocumentJob::class)->last();
        $this->assertNotSame($retry->runKey, $originalJob->runKey);
        config(['kb.embedding_cache.enabled' => false, 'ai.default' => 'openai', 'ai.embeddings_provider' => 'openai']);
        Http::fake(['api.openai.com/*' => function ($request) {
            $data = [];
            foreach ($request['input'] ?? [] as $index => $text) {
                $data[] = ['index' => $index, 'embedding' => [0.1, 0.2, 0.3]];
            }

            return Http::response(['model' => 'text-embedding-3-small', 'data' => $data, 'usage' => ['total_tokens' => count($data)]]);
        }]);
        $this->app->call([$retry, 'handle']);
        $current = KnowledgeDocument::query()->forTenant('test-tenant')->where('status', 'active')->firstOrFail();
        $this->assertSame($provider, $current->metadata['connector']);
        $this->assertSame('restricted', $current->access_scope);
        $this->assertSame($old->source_path, $current->source_path);
        $this->assertSame('archived', $old->fresh()->status);
        $this->assertSame(1, KnowledgeDocumentAcl::query()->forTenant('test-tenant')->where('knowledge_document_id', $current->id)->count());
        $this->assertSame(self::BODY, $service->original($message->fresh()));
        foreach ($current->chunks()->get() as $chunk) {
            $this->assertContains('complaint', $chunk->metadata['search_tags']);
        }
        $this->assertSame('indexed', EmailTriageEvaluation::first()->index_status);
        $indexedHash = $current->document_hash;
        $envelope['metadata']['email_folder_ids'] = ['archive-id'];
        $envelope['metadata']['email_folder_names'] = ['Archive'];
        $source = str_replace('-', '_', $provider);
        $envelope['metadata']['converter_hints'][$source] = ['isRead' => true, 'labelIds' => ['archive-id']];
        $this->prepare($envelope);
        $this->body($envelope);
        $this->assertSame(['archive-id'], $current->fresh()->metadata['email_folder_ids']);
        $this->assertTrue($current->fresh()->metadata['converter_hints'][$source]['isRead']);
        $this->assertContains('complaint', $current->fresh()->metadata['_derived']['search_tags']);
        $this->assertSame($indexedHash, $current->fresh()->document_hash);
        Queue::assertPushed(IngestDocumentJob::class, 2);
        $originalJob->failed(new \RuntimeException('late failed generation'));
        $this->assertSame('indexed', EmailTriageEvaluation::first()->index_status);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'openrouter.ai'));
    }

    #[DataProvider('apiProviders')]
    public function test_scoped_api_tombstone_cannot_delete_another_installation(string $provider): void
    {
        $installation = $this->installation($provider, false);
        $other = $this->installation($provider, false);
        $metadata = $this->envelope($installation)['metadata'];
        $documents = [];
        foreach ([$installation, $other] as $account) {
            $metadata['installation_id'] = (int) $account->id;
            $documents[] = KnowledgeDocument::create(['tenant_id' => 'test-tenant', 'project_key' => 'mail',
                'source_path' => 'mail/'.$account->id.'.md', 'title' => 'Email', 'source_type' => 'markdown', 'mime_type' => 'text/markdown',
                'status' => 'active', 'document_hash' => hash('sha256', 'body'), 'version_hash' => hash('sha256', 'body'), 'metadata' => $metadata]);
        }
        $this->assertTrue(app(HostIngestionBridge::class)->softDeleteByRemoteId($installation, 'email_message_key', $metadata['email_message_key']));
        $this->assertTrue($documents[0]->fresh()->trashed());
        $this->assertFalse($documents[1]->fresh()->trashed());
        $this->assertFalse(app(HostIngestionBridge::class)->softDeleteByRemoteId($installation, 'email_message_key', $metadata['email_message_key']));
    }

    #[DataProvider('apiProviders')]
    public function test_legacy_and_host_default_project_binding_matches_base_connector(string $provider): void
    {
        $installation = $this->installation($provider, false);
        $installation->forceFill(['project_key' => null, 'config_json' => ['project_key' => 'mail']])->save();
        $this->prepare($this->envelope($installation));
        $this->body($this->envelope($installation));
        $installation->forceFill(['config_json' => []])->save();
        config(['kb.ingest.default_project' => 'mail']);
        $this->prepare($this->envelope($installation));
        $this->body($this->envelope($installation));
        Queue::assertPushed(IngestDocumentJob::class, 2);
        $installation->forceFill(['project_key' => 'mail'])->save();
        config(['kb.ingest.default_project' => 'other']);
        $this->assertSame('mail', EmailConnectorRegistry::resolveProject($installation));
    }

    #[DataProvider('apiProviders')]
    public function test_obsolete_ocr_receipt_cannot_remove_the_new_attachment_source(string $provider): void
    {
        $installation = $this->installation($provider);
        $old = $this->envelope($installation, [$this->file(0, 'part-a', 'old-image', 'image/png')]);
        $this->prepare($old);
        $new = $this->envelope($installation, [$this->file(0, 'part-a', 'new-image', 'image/png')]);
        $this->prepare($new);
        Storage::disk('kb')->put('mail/file-0.txt', 'new-image');
        $metadata = $old['metadata'] + ['attachment_filename' => 'same-name.txt',
            'attachment_of_message_id' => $old['metadata']['email_message_id'],
            'email_attachment_id' => 'part-a', 'email_attachment_slot' => 0];
        try {
            app(HostIngestionBridge::class)->dispatchIngestion('mail', 'mail/file-0.txt', 'kb', 'Image', $metadata, 'image/png', 'test-tenant');
            $this->fail('Stale OCR refusal must fail before source cleanup.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('obsolete revision', $error->getMessage());
        }
        $this->assertSame('new-image', Storage::disk('kb')->get('mail/file-0.txt'));
        $this->assertSame('pending', EmailTriageMessage::first()->manifest[0]['status']);
    }

    #[DataProvider('apiProviders')]
    public function test_delayed_attachment_ingest_rejects_a_superseded_revision_before_conversion(string $provider): void
    {
        $installation = $this->installation($provider);
        $old = $this->envelope($installation, [$this->file(0, 'part-a', 'old-file')]);
        $this->prepare($old);
        $this->body($old);
        $this->attachment($old, $old['manifest'][0], 'old-file');
        $job = Queue::pushed(IngestDocumentJob::class)->first();
        $new = $this->envelope($installation, [$this->file(0, 'part-a', 'new-file')]);
        $this->prepare($new);
        Storage::disk('kb')->put('mail/file-0.txt', 'new-file');
        try {
            $this->app->call([$job, 'handle']);
            $this->fail('Old queued attachment must fail before converting new bytes with old metadata.');
        } catch (\RuntimeException $error) {
            $causes = '';
            do {
                $causes .= $error->getMessage()."\n";
                $error = $error->getPrevious();
            } while ($error !== null);
            $this->assertStringContainsString('source revision is obsolete', $causes);
        }
        $this->assertSame(0, KnowledgeDocument::count());
        Http::assertNothingSent();
    }

    #[DataProvider('apiProviders')]
    public function test_explicit_installation_tenant_wins_over_ambient_worker_context(string $provider): void
    {
        $envelope = $this->envelope($this->installation($provider));
        app(TenantContext::class)->set('another-worker-tenant');
        $this->prepare($envelope);
        $this->body($envelope);
        $this->assertSame('test-tenant', EmailTriageMessage::first()->tenant_id);
        $this->assertSame('another-worker-tenant', app(TenantContext::class)->current());
        Queue::assertNotPushed(IngestDocumentJob::class);
    }

    #[DataProvider('apiProviders')]
    public function test_disabling_triage_keeps_new_revision_ingestion_unblocked_by_the_old_manifest(string $provider): void
    {
        $installation = $this->installation($provider);
        $old = $this->envelope($installation);
        $this->prepare($old);
        $this->body($old);
        $disabled = EmailTriageProfile::create(['tenant_id' => 'test-tenant', 'connector_installation_id' => $installation->id,
            'revision' => 2, 'enabled' => false, 'rules' => [], 'model' => config('decisions.models.jev.model')]);
        $installation->forceFill(['config_json' => ['email_triage_profile_id' => $disabled->id]])->save();
        $new = $this->envelope($installation, body: self::BODY.' Changed after disabling.');
        $this->prepare($new);
        $this->body($new, self::BODY.' Changed after disabling.');
        $job = Queue::pushed(IngestDocumentJob::class)->first();
        $this->assertNull(app(EmailTriageService::class)->sourceForIngest('test-tenant', 'mail', $job->relativePath, $job->metadata));
        $this->assertSame($old['metadata']['email_revision_hash'], EmailTriageMessage::first()->metadata['email_revision_hash']);
        Queue::assertPushed(IngestDocumentJob::class, 1);
        Http::assertNothingSent();
    }

    #[DataProvider('apiProviders')]
    public function test_project_rebind_reuses_the_decision_and_regenerates_only_the_scoped_index(string $provider): void
    {
        $installation = $this->installation($provider);
        $envelope = $this->envelope($installation);
        $this->prepare($envelope);
        $this->body($envelope);
        config(['kb.embedding_cache.enabled' => false, 'ai.default' => 'openai', 'ai.embeddings_provider' => 'openai']);
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'openrouter.ai')) {
                return Http::response(['id' => 'synthetic-triage', 'model' => config('decisions.models.jev.model'),
                    'answers' => ['complaint' => ['type' => 'noul', 'noul' => 0.97]], 'usage' => ['cost' => 0.00001]]);
            }
            $data = [];
            foreach ($request['input'] ?? [] as $index => $text) {
                $data[] = ['index' => $index, 'embedding' => [0.1, 0.2, 0.3]];
            }

            return Http::response(['model' => 'text-embedding-3-small', 'data' => $data, 'usage' => ['total_tokens' => count($data)]]);
        });
        $service = app(EmailTriageService::class);
        $message = EmailTriageMessage::first();
        $service->process($message->evaluation_id, 'test-tenant');
        $oldJob = Queue::pushed(IngestDocumentJob::class)->first();
        $this->app->call([$oldJob, 'handle']);
        $oldDocument = KnowledgeDocument::query()->forTenant('test-tenant')->where('project_key', 'mail')->firstOrFail();
        $oldDocument->forceFill(['access_scope' => 'restricted'])->save();
        KnowledgeDocumentAcl::create(['tenant_id' => 'test-tenant', 'knowledge_document_id' => $oldDocument->id,
            'subject_type' => 'user', 'subject_id' => 999, 'permission' => 'view', 'effect' => 'deny', 'origin' => 'manual']);
        $decisionId = $message->evaluation_id;
        $installation->forceFill(['project_key' => 'new-project'])->save();
        $envelope['project_key'] = 'new-project';
        $envelope['metadata']['access_scope'] = 'public';
        $envelope['metadata']['previous_document_id'] = 999999;
        $this->prepare($envelope);
        $this->assertSame('awaiting_files', $message->fresh()->status);
        $this->assertSame($decisionId, $message->fresh()->evaluation_id);
        $this->assertSame('restricted', $message->fresh()->metadata['access_scope']);
        $this->assertSame($oldDocument->id, $message->fresh()->metadata['previous_document_id']);
        $this->assertSame(1, EmailTriageEvaluation::first()->index_generation);
        try {
            $service->sourceForIngest('test-tenant', 'mail', 'mail/body.md', $oldJob->metadata);
            $this->fail('The old project job must not write after rebind.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('invalid project scope', $error->getMessage());
        }
        app(HostIngestionBridge::class)->dispatchIngestion('new-project', 'mail/body.md', 'kb', 'Shipment',
            $envelope['metadata'], 'text/markdown', 'test-tenant');
        $service->process($decisionId, 'test-tenant');
        $job = Queue::pushed(IngestDocumentJob::class)->last();
        $this->assertNotSame($oldJob->runKey, $job->runKey);
        $this->app->call([$job, 'handle']);
        $newDocument = KnowledgeDocument::query()->forTenant('test-tenant')->where('project_key', 'new-project')->firstOrFail();
        $this->assertSame('restricted', $newDocument->access_scope);
        $this->assertSame(1, KnowledgeDocumentAcl::query()->forTenant('test-tenant')->where('knowledge_document_id', $newDocument->id)->count());
        $this->assertSame(1, EmailTriageEvaluation::count());
        $this->assertSame('indexed', EmailTriageEvaluation::first()->index_status);
        $this->assertSame(1, Http::recorded(fn ($request) => str_contains($request->url(), 'openrouter.ai'))->count());
        $this->assertSame(self::BODY, $service->original($message->fresh()));
    }

    public static function attachmentProviders(): array
    {
        return ['Gmail on' => ['gmail', true], 'Gmail off' => ['gmail', false],
            'Microsoft on' => ['microsoft-mail', true], 'Microsoft off' => ['microsoft-mail', false]];
    }

    #[DataProvider('attachmentProviders')]
    public function test_new_attachment_inherits_parent_scope_and_acl_with_triage_on_or_off(string $provider, bool $enabled): void
    {
        $installation = $this->installation($provider, $enabled);
        $envelope = $this->envelope($installation, [$this->file(0, 'part-a')]);
        $parent = KnowledgeDocument::create(['tenant_id' => 'test-tenant', 'project_key' => 'mail', 'source_path' => 'mail/body.md',
            'title' => 'Restricted parent', 'source_type' => 'markdown', 'mime_type' => 'text/markdown', 'status' => 'active',
            'access_scope' => 'restricted', 'document_hash' => hash('sha256', 'previous'), 'version_hash' => hash('sha256', 'previous'),
            'metadata' => $envelope['metadata']]);
        KnowledgeDocumentAcl::create(['tenant_id' => 'test-tenant', 'knowledge_document_id' => $parent->id,
            'subject_type' => 'user', 'subject_id' => 888, 'permission' => 'view', 'effect' => 'deny', 'origin' => 'manual']);
        $this->prepare($envelope);
        $this->body($envelope);
        $envelope['metadata']['access_scope'] = 'public';
        $envelope['metadata']['previous_document_id'] = 999999;
        $envelope['metadata']['email_parent_document_id'] = 999999;
        $this->attachment($envelope, $envelope['manifest'][0]);
        $job = Queue::pushed(IngestDocumentJob::class)->first(fn ($job) => isset($job->metadata['attachment_filename']));
        $this->assertSame($parent->id, $job->metadata['email_parent_document_id']);
        $this->assertSame('restricted', $job->metadata['access_scope']);
        $this->assertArrayNotHasKey('previous_document_id', $job->metadata);
        KnowledgeDocumentAcl::create(['tenant_id' => 'test-tenant', 'knowledge_document_id' => $parent->id,
            'subject_type' => 'user', 'subject_id' => 777, 'permission' => 'view', 'effect' => 'deny', 'origin' => 'manual']);
        config(['kb.embedding_cache.enabled' => false, 'ai.default' => 'openai', 'ai.embeddings_provider' => 'openai']);
        Http::fake(['api.openai.com/*' => function ($request) {
            $data = [];
            foreach ($request['input'] ?? [] as $index => $text) {
                $data[] = ['index' => $index, 'embedding' => [0.1, 0.2, 0.3]];
            }

            return Http::response(['model' => 'text-embedding-3-small', 'data' => $data, 'usage' => ['total_tokens' => count($data)]]);
        }]);
        $this->app->call([$job, 'handle']);
        $attachment = KnowledgeDocument::query()->forTenant('test-tenant')->where('source_path', 'mail/file-0.txt')->firstOrFail();
        $this->assertSame($provider, $attachment->metadata['connector']);
        $this->assertSame('restricted', $attachment->access_scope);
        $this->assertSame('restricted', $attachment->metadata['access_scope']);
        $acl = KnowledgeDocumentAcl::query()->forTenant('test-tenant')->where('knowledge_document_id', $attachment->id)->firstOrFail();
        $this->assertSame(888, (int) $acl->subject_id);
        $this->assertSame('deny', $acl->effect);
        $this->assertSame(2, KnowledgeDocumentAcl::query()->forTenant('test-tenant')->where('knowledge_document_id', $attachment->id)->count());
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'openrouter.ai'));
    }

    #[DataProvider('apiProviders')]
    public function test_remote_prefix_cannot_redirect_body_receipts_or_snapshots(string $provider): void
    {
        $envelope = $this->envelope($this->installation($provider));
        $envelope['metadata']['prefix'] = 'attacker';
        config(['kb.sources.path_prefix' => 'actual']);
        $this->prepare($envelope);
        Storage::disk('kb')->put('actual/mail/body.md', self::BODY);
        Storage::disk('kb')->put('attacker/mail/body.md', 'Unrelated private bytes');
        app(HostIngestionBridge::class)->dispatchIngestion('mail', 'mail/body.md', 'kb', 'Shipment',
            $envelope['metadata'], 'text/markdown', 'test-tenant');
        $message = EmailTriageMessage::first();
        $this->assertSame('actual', $message->metadata['prefix']);
        $this->assertStringStartsWith('actual/.artifacts/email-triage/', $message->original_path);
        $this->assertSame(self::BODY, app(EmailTriageService::class)->original($message));
        $this->assertSame('Unrelated private bytes', Storage::disk('kb')->get('attacker/mail/body.md'));
    }

    #[DataProvider('attachmentProviders')]
    public function test_parent_grants_bound_previous_attachment_access_while_denials_and_manual_tags_survive(string $provider, bool $enabled): void
    {
        $installation = $this->installation($provider, $enabled);
        $oldEnvelope = $this->envelope($installation, [$this->file(0, 'part-a', 'Old reference')]);
        $envelope = $this->envelope($installation, [$this->file(0, 'part-a')]);
        $parent = KnowledgeDocument::create(['tenant_id' => 'test-tenant', 'project_key' => 'mail', 'source_path' => 'mail/body.md',
            'title' => 'Parent', 'source_type' => 'markdown', 'mime_type' => 'text/markdown', 'status' => 'active',
            'access_scope' => 'restricted', 'document_hash' => hash('sha256', 'parent'), 'version_hash' => hash('sha256', 'parent'),
            'metadata' => $envelope['metadata']]);
        $oldMetadata = $oldEnvelope['metadata'] + ['attachment_filename' => 'same-name.txt',
            'attachment_of_message_id' => $envelope['metadata']['email_message_id'], 'email_attachment_id' => 'part-a',
            'email_attachment_slot' => 0, 'email_attachment_hash' => hash('sha256', 'Old reference')];
        $previous = KnowledgeDocument::create(['tenant_id' => 'test-tenant', 'project_key' => 'mail', 'source_path' => 'mail/file-0.txt',
            'title' => 'Previous file', 'source_type' => 'markdown', 'mime_type' => 'text/plain', 'status' => 'active',
            'access_scope' => 'restricted', 'document_hash' => hash('sha256', 'Old reference'), 'version_hash' => hash('sha256', 'Old reference'),
            'metadata' => $oldMetadata]);
        foreach ([[$parent->id, 'group', 111, 'allow'], [$previous->id, 'user', 222, 'allow'], [$previous->id, 'user', 333, 'deny']] as [$id, $type, $subject, $effect]) {
            KnowledgeDocumentAcl::create(['tenant_id' => 'test-tenant', 'knowledge_document_id' => $id,
                'subject_type' => $type, 'subject_id' => $subject, 'permission' => 'view', 'effect' => $effect, 'origin' => 'manual']);
        }
        $tag = KbTag::create(['tenant_id' => 'test-tenant', 'project_key' => 'mail', 'slug' => 'manual', 'label' => 'Manual']);
        $previous->tags()->attach($tag->id);
        $this->prepare($envelope);
        $this->body($envelope);
        $this->attachment($envelope, $envelope['manifest'][0]);
        $job = Queue::pushed(IngestDocumentJob::class)->first(fn ($job) => isset($job->metadata['attachment_filename']));
        config(['kb.embedding_cache.enabled' => false, 'ai.default' => 'openai', 'ai.embeddings_provider' => 'openai']);
        Http::fake(['api.openai.com/*' => function ($request) {
            $data = [];
            foreach ($request['input'] ?? [] as $index => $text) {
                $data[] = ['index' => $index, 'embedding' => [0.1, 0.2, 0.3]];
            }

            return Http::response(['model' => 'text-embedding-3-small', 'data' => $data, 'usage' => ['total_tokens' => count($data)]]);
        }]);
        $this->app->call([$job, 'handle']);
        $current = KnowledgeDocument::query()->forTenant('test-tenant')->where('source_path', 'mail/file-0.txt')->where('status', 'active')->firstOrFail();
        $rules = KnowledgeDocumentAcl::query()->forTenant('test-tenant')->where('knowledge_document_id', $current->id)->get();
        $this->assertTrue($rules->contains(fn ($acl) => $acl->subject_type === 'group' && (int) $acl->subject_id === 111 && $acl->effect === 'allow'));
        $this->assertFalse($rules->contains(fn ($acl) => $acl->subject_type === 'user' && (int) $acl->subject_id === 222));
        $this->assertTrue($rules->contains(fn ($acl) => $acl->subject_type === 'user' && (int) $acl->subject_id === 333 && $acl->effect === 'deny'));
        $this->assertSame([$tag->id], $current->tags()->pluck('kb_tags.id')->all());
        $this->assertSame('archived', $previous->fresh()->status);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'openrouter.ai'));
    }
}
