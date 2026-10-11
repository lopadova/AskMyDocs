<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Connectors\HostIngestionBridge;
use App\Connectors\Imap\ImapSyncProgressContext;
use App\Jobs\EmailTriage\AnalyzeEmailJob;
use App\Jobs\IngestDocumentJob;
use App\Models\EmailTriageCampaignItem;
use App\Models\EmailTriageEvaluation;
use App\Models\EmailTriageMessage;
use App\Models\EmailTriageProfile;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeDocumentAcl;
use App\Models\User;
use App\Services\EmailTriage\EmailTriageService;
use App\Services\EmailTriage\TriageCampaigns;
use App\Services\EmailTriage\TriageEngine;
use App\Services\EmailTriage\TriageProfiles;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorImap\Imap\AttachmentPolicy;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapAttachment;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapMessage;
use Padosoft\AskMyDocsConnectorImap\Support\MailMetadata;
use Tests\TestCase;

final class EmailTriageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('kb');
        config(['kb.sources.disk' => 'kb', 'kb.sources.path_prefix' => '',
            'connectors.case_study_email_dataset.require_fixture_index' => false,
            'ai.providers.openrouter.key' => 'test-only-key']);
    }

    private function rule(): array
    {
        return ['key' => 'complaint', 'label' => 'Lamentela', 'type' => 'noul', 'enabled' => true,
            'instructions' => 'Is the sender complaining?', 'threshold' => 0.85,
            'options' => [['key' => 'true', 'label' => 'Sì', 'criterion' => 'A complaint or dissatisfaction', 'text' => 'Lamentela presente', 'tag' => 'lamentela'],
                ['key' => 'false', 'label' => 'No', 'criterion' => 'An ordinary request', 'text' => 'Lamentela assente', 'tag' => null]]];
    }

    private function installation(bool $enabled = true, string $tenant = 'test-tenant'): ConnectorInstallation
    {
        $installation = ConnectorInstallation::create(['tenant_id' => $tenant, 'connector_name' => 'imap',
            'label' => uniqid('mail-'), 'project_key' => 'mail', 'status' => 'active', 'config_json' => []]);
        if ($enabled) {
            $profile = EmailTriageProfile::create(['tenant_id' => $tenant, 'connector_installation_id' => $installation->id,
                'revision' => 1, 'enabled' => true, 'rules' => [$this->rule()], 'model' => config('decisions.models.jev.model')]);
            $installation->forceFill(['config_json' => ['email_triage_profile_id' => $profile->id]])->save();
        }
        return $installation;
    }

    private function email(int $uid = 42, array $files = []): ImapMessage
    {
        return new ImapMessage($uid, 7, 'INBOX', '<mail-'.$uid.'@example.test>', null, [], 'Client', 'client@example.test', [], [],
            now(), 'Shipment issue', ['\\Seen'], [], 'The parcel is late again. I am unhappy.', null, [], $files);
    }

    private function stage(ConnectorInstallation $installation, ImapMessage $email): EmailTriageMessage
    {
        $service = app(EmailTriageService::class);
        $service->register($installation, $email, new AttachmentPolicy(['enabled' => true, 'allowed_extensions' => ['txt', 'png']]));
        $metadata = (new MailMetadata)->build($installation->id, $email);
        $path = 'mail/'.$email->uid.'.md';
        Storage::disk('kb')->put($path, "# Shipment issue\n\n".$email->textBody);
        app(HostIngestionBridge::class)->dispatchIngestion('mail', $path, 'kb', $email->subject, $metadata, 'text/markdown', $installation->tenant_id);
        return EmailTriageMessage::query()->forTenant($installation->tenant_id)->where('identity_hash', $service->identity($metadata))->firstOrFail();
    }

    private function fakeDecision(float $probability = 0.97): void
    {
        Http::fake(['openrouter.ai/*' => Http::response(['id' => 'triage-test', 'model' => config('decisions.models.jev.model'),
            'answers' => ['complaint' => ['type' => 'noul', 'noul' => $probability]], 'usage' => ['input_tokens' => 80, 'cost' => 0.00001]])]);
    }

    public function test_disabled_connectors_keep_the_existing_ingestion_path(): void
    {
        $installation = $this->installation(false);
        $metadata = (new MailMetadata)->build($installation->id, $this->email());
        Storage::disk('kb')->put('mail/email.md', '# Unchanged');
        app(HostIngestionBridge::class)->dispatchIngestion('mail', 'mail/email.md', 'kb', 'Title', $metadata, 'text/markdown', 'test-tenant');
        Queue::assertPushed(IngestDocumentJob::class, 1);
        Queue::assertNotPushed(AnalyzeEmailJob::class);
        $this->assertSame(0, EmailTriageMessage::count());
    }

    public function test_body_waits_for_the_explicit_manifest_but_attachment_ingest_does_not(): void
    {
        $installation = $this->installation();
        $file = new ImapAttachment('intro.txt', 'text/plain', 20, false, 'The return reference');
        $email = $this->email(files: [$file]);
        $message = $this->stage($installation, $email);
        $this->assertSame('awaiting_files', $message->status);
        Queue::assertNothingPushed();
        $metadata = (new MailMetadata)->build($installation->id, $email) + ['attachment_filename' => $file->filename, 'attachment_of_message_id' => $email->messageId];
        Storage::disk('kb')->put('mail/intro.txt', $file->contents);
        app(HostIngestionBridge::class)->dispatchIngestion('mail', 'mail/intro.txt', 'kb', 'intro', $metadata, 'text/plain', 'test-tenant');
        Queue::assertPushed(IngestDocumentJob::class, 1);
        Queue::assertPushed(AnalyzeEmailJob::class, 1);
        $this->assertSame('waiting', $message->fresh()->status);
        $this->assertSame('saved', $message->fresh()->manifest[0]['status']);
    }

    public function test_one_decision_and_one_card_survive_duplicate_jobs_and_preserve_original(): void
    {
        $this->fakeDecision();
        $message = $this->stage($this->installation(), $this->email());
        $service = app(EmailTriageService::class);
        $original = Storage::disk('kb')->get($message->source_path);
        $service->process($message->evaluation_id, 'test-tenant');
        $service->process($message->evaluation_id, 'test-tenant');
        Http::assertSentCount(1);
        $this->assertSame(1, EmailTriageEvaluation::count());
        $job = Queue::pushed(IngestDocumentJob::class)->last();
        $this->assertSame(['INBOX', 'lamentela'], data_get($job->metadata, '_derived.search_tags'));
        $body = $service->sourceForIngest('test-tenant', 'mail', $message->source_path, $job->metadata);
        $this->assertSame(1, substr_count($body, '## Scheda di triage automatica'));
        $this->assertStringContainsString('Lamentela presente', $body);
        $this->assertSame($original, Storage::disk('kb')->get($message->source_path));
        $this->assertSame($original, $service->original($message));
    }

    public function test_uncertain_decisions_abstain_without_a_positive_search_tag(): void
    {
        $this->fakeDecision(0.6);
        $message = $this->stage($this->installation(), $this->email());
        app(EmailTriageService::class)->process($message->evaluation_id, 'test-tenant');
        $evaluation = EmailTriageEvaluation::first();
        $this->assertSame([], $evaluation->tags);
        $this->assertStringContainsString('Non determinabile', $evaluation->card);
        $this->assertSame('completed', $evaluation->status);
    }

    public function test_provider_failure_blocks_only_one_email_and_retries_reuse_a_saved_result(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('unavailable'));
        $installation = $this->installation();
        $message = $this->stage($installation, $this->email());
        try {
            app(EmailTriageService::class)->process($message->evaluation_id, 'test-tenant');
            $this->fail('The unavailable provider must not be silently accepted.');
        } catch (\App\Decisions\DecisionException) {
            $this->assertSame('waiting', $message->fresh()->status);
        }
        Queue::assertNotPushed(IngestDocumentJob::class);
        $this->stage($installation, $this->email(43));
        $this->assertSame(2, EmailTriageMessage::count());
        Queue::assertPushed(AnalyzeEmailJob::class, 2);
    }

    public function test_imap_progress_registers_a_manifest_before_confirming_the_download(): void
    {
        $installation = $this->installation();
        $progress = app(ImapSyncProgressContext::class);
        app(\Padosoft\AskMyDocsConnectorBase\Auth\OAuthCredentialVault::class)->setCredentials($installation->id, accessToken: 'test');
        $progress->begin($installation);
        $progress->observeSearch('INBOX', 7, [42]);
        $progress->observeFetched($this->email());
        $this->assertSame('awaiting_files', EmailTriageMessage::first()->status);
        $progress->finish();
        Queue::assertNotPushed(AnalyzeEmailJob::class);
    }

    public function test_filtered_files_and_refused_images_do_not_leave_the_email_waiting_forever(): void
    {
        $installation = $this->installation();
        $image = new ImapAttachment('scan.png', 'image/png', 8, false, 'image');
        $inline = new ImapAttachment('logo.png', 'image/png', 4, true, 'logo');
        $email = $this->email(files: [$image, $inline]);
        $message = $this->stage($installation, $email);
        $metadata = (new MailMetadata)->build($installation->id, $email) + ['attachment_filename' => 'scan.png', 'attachment_of_message_id' => $email->messageId];
        Storage::disk('kb')->put('mail/scan.png', 'image');
        config(['kb.ocr.enabled' => false]);
        app(HostIngestionBridge::class)->dispatchIngestion('mail', 'mail/scan.png', 'kb', 'scan', $metadata, 'image/png', 'test-tenant');
        $this->assertSame(['ocr_disabled', 'excluded'], array_column($message->fresh()->manifest, 'status'));
        Queue::assertPushed(AnalyzeEmailJob::class, 1);
        Queue::assertNotPushed(IngestDocumentJob::class);
    }

    public function test_body_first_packer_bounds_sanitized_utf8_and_marks_omitted_attachments(): void
    {
        config(['decisions.max_state_bytes' => 4096]);
        $prepared = app(TriageEngine::class)->prepare('START '.str_repeat('è " \\ ', 3000).' END', [
            ['name' => 'too-large.pdf', 'status' => 'extraction_size_limit'],
        ], 'kb');
        $this->assertLessThanOrEqual(4096, \App\Decisions\DecisionState::fromArray($prepared['state'])->bytes);
        $this->assertStringStartsWith('START ', $prepared['state']['email']);
        $this->assertStringEndsWith(' END', $prepared['state']['email']);
        $this->assertTrue($prepared['coverage']['body_truncated']);
        $this->assertTrue($prepared['coverage']['partial']);
    }

    public function test_cross_tenant_preview_configuration_and_jobs_cannot_access_messages(): void
    {
        $message = $this->stage($this->installation(), $this->email());
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        app(EmailTriageService::class)->process($message->evaluation_id, 'other-tenant');
    }

    public function test_preview_is_read_only_and_profile_versions_require_a_ready_worker(): void
    {
        $this->seed(RbacSeeder::class);
        $admin = User::create(['name' => 'Admin', 'email' => 'triage-admin@example.test', 'password' => 'test']);
        $admin->assignRole('super-admin');
        $installation = $this->installation(false);
        $this->actingAs($admin);
        $payload = ['enabled' => true, 'rules' => [$this->rule()]];
        $base = '/api/admin/connectors/'.$installation->id.'/email-triage';
        $this->putJson($base, $payload)->assertUnprocessable();
        config(['queue.default' => 'database', 'queue.connections.email-triage.driver' => 'redis']);
        Cache::put('email-triage:worker-heartbeat', 'available', 180);
        Http::fake(['openrouter.ai/*' => fn ($request) => Http::response(['id' => 'activation-check', 'model' => config('decisions.models.jev.model'),
            'answers' => [array_key_first($request['questions']) => ['type' => 'noul', 'noul' => 0.97]], 'usage' => []])]);
        $first = $this->putJson($base, $payload)->assertOk()->json('data.id');
        config(['email-triage.connection' => 'sync']);
        $this->putJson($base, $payload)->assertUnprocessable();
        config(['email-triage.connection' => 'email-triage']);
        $this->putJson($base, $payload)->assertOk()->assertJsonPath('data.revision', 2);
        $this->assertNotSame($first, $installation->fresh()->config_json['email_triage_profile_id']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['state'] === ['sample' => 'Email triage readiness check']);
        $document = $this->legacyDocument($installation);
        $this->postJson($base.'/preview', ['document_id' => $document->id])->assertOk()->assertJsonPath('data.tags.0', 'lamentela');
        $this->assertSame(0, EmailTriageEvaluation::count());
        Queue::assertNotPushed(IngestDocumentJob::class);
        $other = $this->installation(true, 'other-tenant');
        $this->getJson('/api/admin/connectors/'.$other->id.'/email-triage')->assertNotFound();
    }

    public function test_invalid_decision_credentials_prevent_activation_even_with_a_worker(): void
    {
        $installation = $this->installation(false);
        config(['queue.default' => 'database', 'queue.connections.email-triage.driver' => 'redis']);
        Cache::put('email-triage:worker-heartbeat', 'available', 180);
        Http::fake(['openrouter.ai/*' => Http::response([], 401)]);
        try {
            app(TriageProfiles::class)->save($installation, ['enabled' => true, 'rules' => [$this->rule()]]);
            $this->fail('Invalid provider credentials must prevent activation.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertStringContainsString('decision_http_401', $exception->errors()['enabled'][0]);
        }
        $this->assertSame(0, EmailTriageProfile::count());
        Http::assertSent(fn ($request) => $request['state'] === ['sample' => 'Email triage readiness check']);
    }

    private function legacyDocument(ConnectorInstallation $installation): KnowledgeDocument
    {
        $metadata = (new MailMetadata)->build($installation->id, $this->email());
        $metadata += ['disk' => 'kb', 'prefix' => ''];
        Storage::disk('kb')->put('mail/legacy.md', "# A delivery\n\nThis delay is unacceptable.");
        return KnowledgeDocument::create(['tenant_id' => $installation->tenant_id, 'project_key' => 'mail', 'source_path' => 'mail/legacy.md',
            'title' => 'Legacy delivery', 'source_type' => 'imap', 'mime_type' => 'text/markdown', 'status' => 'active',
            'access_scope' => 'restricted', 'document_hash' => hash('sha256', 'legacy'), 'version_hash' => hash('sha256', 'legacy'), 'metadata' => $metadata]);
    }

    public function test_historical_campaign_stages_one_email_and_reindex_preserves_identity_and_acl(): void
    {
        $installation = $this->installation();
        $old = $this->legacyDocument($installation);
        KnowledgeDocumentAcl::create(['tenant_id' => 'test-tenant', 'knowledge_document_id' => $old->id,
            'subject_type' => 'user', 'subject_id' => 999, 'permission' => 'view', 'effect' => 'deny', 'origin' => 'manual']);
        $campaigns = app(TriageCampaigns::class);
        $preview = $campaigns->preview($installation, ['state' => 'never']);
        $this->assertSame(1, $preview['total']);
        $this->assertSame(1, $preview['availability_sample']['available']);
        $campaign = $campaigns->start($installation, ['state' => 'never']);
        $campaigns->pump($campaign);
        $this->assertSame(1, EmailTriageCampaignItem::count());
        $this->fakeDecision();
        $message = EmailTriageMessage::first();
        app(EmailTriageService::class)->process($message->evaluation_id, 'test-tenant');
        $job = Queue::pushed(IngestDocumentJob::class)->first();
        config(['kb.embedding_cache.enabled' => false, 'ai.default' => 'openai', 'ai.embeddings_provider' => 'openai']);
        Http::fake(['api.openai.com/*' => function ($request) {
            $data = [];
            foreach ($request['input'] ?? [] as $i => $text) {
                $data[] = ['index' => $i, 'embedding' => [0.1, 0.2, 0.3]];
            }
            return Http::response(['model' => 'text-embedding-3-small', 'data' => $data, 'usage' => ['total_tokens' => count($data)]]);
        }]);
        $this->app->call([$job, 'handle']);
        $current = KnowledgeDocument::query()->forTenant('test-tenant')->where('status', 'active')->firstOrFail();
        $this->assertSame($old->source_path, $current->source_path);
        $this->assertSame('restricted', $current->access_scope);
        $this->assertSame('archived', $old->fresh()->status);
        $chunks = $current->chunks()->get();
        $this->assertNotEmpty($chunks);
        foreach ($chunks as $chunk) {
            $this->assertContains('lamentela', $chunk->metadata['search_tags']);
        }
        $this->assertStringContainsString('Classificazione automatica; non fa parte del testo del mittente', $chunks->pluck('chunk_text')->implode("\n"));
        $this->assertSame(1, KnowledgeDocumentAcl::query()->forTenant('test-tenant')->where('knowledge_document_id', $current->id)->count());
        $this->assertNotNull(EmailTriageEvaluation::first()->indexed_at);
        $campaigns->pump($campaign->fresh());
        $this->assertSame('completed', $campaign->fresh()->status);
    }

    public function test_missing_historical_sources_are_reported_without_remote_fetch(): void
    {
        $installation = $this->installation();
        $this->legacyDocument($installation);
        Storage::disk('kb')->delete('mail/legacy.md');
        $campaigns = app(TriageCampaigns::class);
        $campaign = $campaigns->start($installation, ['state' => 'never']);
        $campaigns->pump($campaign);
        $this->assertSame('unavailable', EmailTriageCampaignItem::first()->status);
        Queue::assertNotPushed(AnalyzeEmailJob::class);
    }

    public function test_ten_independent_rounds_remain_idempotent_after_download_and_job_replays(): void
    {
        $this->fakeDecision();
        $installation = $this->installation();
        $service = app(EmailTriageService::class);
        for ($round = 1; $round <= 10; $round++) {
            $email = $this->email(100 + $round);
            $message = $this->stage($installation, $email);
            $service->process($message->evaluation_id, 'test-tenant');
            $this->stage($installation, $email);
            $service->process($message->evaluation_id, 'test-tenant');
            $this->assertSame(1, substr_count(EmailTriageEvaluation::query()->findOrFail($message->evaluation_id)->card, '## Scheda di triage automatica'));
            $this->assertSame("# Shipment issue\n\n".$email->textBody, Storage::disk('kb')->get($message->source_path));
        }
        Http::assertSentCount(10);
        $this->assertSame(10, EmailTriageMessage::count());
        $this->assertSame(10, EmailTriageEvaluation::count());
        Queue::assertPushed(IngestDocumentJob::class, 10);
    }

    public function test_backfill_persistence_uses_the_same_triage_gate(): void
    {
        $installation = $this->installation();
        $importer = app(\App\Connectors\Imap\Backfill\ImapBackfillImporter::class);
        $method = new \ReflectionMethod($importer, 'persistMessage');
        $count = $method->invoke($importer, $installation, [], $this->email(), new AttachmentPolicy(['allowed_extensions' => ['txt']]));
        $this->assertSame(1, $count);
        $this->assertSame('waiting', EmailTriageMessage::first()->status);
        Queue::assertPushed(AnalyzeEmailJob::class, 1);
        Queue::assertNotPushed(IngestDocumentJob::class);
    }

    public function test_reindex_failure_can_retry_without_another_decision_charge(): void
    {
        $this->fakeDecision();
        $message = $this->stage($this->installation(), $this->email());
        $service = app(EmailTriageService::class);
        $service->process($message->evaluation_id, 'test-tenant');
        $job = Queue::pushed(IngestDocumentJob::class)->first();
        $job->failed(new \RuntimeException('embedding unavailable'));
        $this->assertSame('failed', EmailTriageEvaluation::first()->index_status);
        $service->retry($message->fresh());
        $service->process($message->evaluation_id, 'test-tenant');
        Http::assertSentCount(1);
        Queue::assertPushed(IngestDocumentJob::class, 2);
    }

    public function test_manual_index_retry_executes_a_new_flow_and_ignores_late_old_failures(): void
    {
        $this->fakeDecision();
        $message = $this->stage($this->installation(), $this->email());
        $service = app(EmailTriageService::class);
        $service->process($message->evaluation_id, 'test-tenant');
        $originalJob = Queue::pushed(IngestDocumentJob::class)->first();
        // Persist a real failed parse run, then restore the original snapshot.
        $body = Storage::disk('kb')->get($message->original_path);
        Storage::disk('kb')->delete($message->original_path);
        try {
            $this->app->call([$originalJob, 'handle']);
            $this->fail('A missing original must fail ingestion.');
        } catch (\RuntimeException $error) {
            $originalJob->failed($error);
        }
        Storage::disk('kb')->put($message->original_path, $body);
        $service->retry($message->fresh());
        $service->process($message->evaluation_id, 'test-tenant');
        $retryJob = Queue::pushed(IngestDocumentJob::class)->last();
        $this->assertNotSame($originalJob->runKey, $retryJob->runKey);
        $this->assertSame(1, EmailTriageEvaluation::first()->index_generation);
        config(['kb.embedding_cache.enabled' => false, 'ai.default' => 'openai', 'ai.embeddings_provider' => 'openai']);
        Http::fake(['api.openai.com/*' => function ($request) {
            $data = [];
            foreach ($request['input'] ?? [] as $i => $text) {
                $data[] = ['index' => $i, 'embedding' => [0.1, 0.2, 0.3]];
            }
            return Http::response(['model' => 'text-embedding-3-small', 'data' => $data, 'usage' => ['total_tokens' => count($data)]]);
        }]);
        $this->app->call([$retryJob, 'handle']);
        $this->assertSame('indexed', EmailTriageEvaluation::first()->index_status);
        $originalJob->failed(new \RuntimeException('late duplicate failure'));
        $retryJob->failed(new \RuntimeException('duplicate failure in the current generation'));
        $this->assertSame('indexed', EmailTriageEvaluation::first()->index_status);
        $this->assertSame(1, KnowledgeDocument::where('status', 'active')->count());
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'openrouter.ai'));
    }

    public function test_transient_error_retries_three_times_then_requires_an_explicit_retry(): void
    {
        Http::fake(['*' => Http::response([], 429)]);
        $message = $this->stage($this->installation(), $this->email());
        $service = app(EmailTriageService::class);
        foreach ([30, 120, 600, 600] as $index => $delay) {
            $this->freezeTime();
            try {
                $service->process($message->evaluation_id, 'test-tenant');
                $this->fail('HTTP 429 must defer ingestion.');
            } catch (\App\Decisions\DecisionException) {
                $evaluation = EmailTriageEvaluation::first();
                $this->assertSame($index + 1, $evaluation->attempts);
                $this->assertSame('decision_http_429', $evaluation->error_code);
                $this->assertSame(now()->addSeconds($delay)->timestamp, $evaluation->retry_at->timestamp);
            }
        }
        $this->assertSame('failed', $message->fresh()->status);
        Queue::assertNotPushed(IngestDocumentJob::class);
        $service->retry($message->fresh());
        $this->assertSame('waiting', EmailTriageEvaluation::first()->status);
        $this->assertSame(0, EmailTriageEvaluation::first()->attempts);
    }

    public function test_failed_unindexed_email_can_retry_with_the_latest_saved_rules(): void
    {
        $installation = $this->installation();
        $message = $this->stage($installation, $this->email());
        $old = EmailTriageEvaluation::first();
        $old->forceFill(['status' => 'failed'])->save();
        $message->forceFill(['status' => 'failed'])->save();
        $newProfile = EmailTriageProfile::create(['tenant_id' => 'test-tenant', 'connector_installation_id' => $installation->id,
            'revision' => 2, 'enabled' => true, 'rules' => [$this->rule()], 'model' => config('decisions.models.jev.model')]);
        $installation->forceFill(['config_json' => ['email_triage_profile_id' => $newProfile->id]])->save();
        $service = app(EmailTriageService::class);
        $service->retry($message);
        $this->assertSame($newProfile->id, $message->fresh()->profile_id);
        $this->assertNotSame($old->id, $message->fresh()->evaluation_id);
        $this->assertSame('failed', $old->fresh()->status);
        $this->fakeDecision();
        $service->process($message->fresh()->evaluation_id, 'test-tenant');
        $this->assertSame('completed', $message->fresh()->status);
        $this->assertSame(0, KnowledgeDocument::count());
        Http::assertSentCount(1);
    }

    public function test_paused_campaign_and_disabled_installation_do_not_call_the_provider(): void
    {
        $installation = $this->installation();
        $this->legacyDocument($installation);
        $campaigns = app(TriageCampaigns::class);
        $campaign = $campaigns->start($installation, ['state' => 'never']);
        $campaigns->pump($campaign);
        $message = EmailTriageMessage::first();
        $campaign->forceFill(['status' => 'paused'])->save();
        $this->fakeDecision();
        app(EmailTriageService::class)->process($message->evaluation_id, 'test-tenant');
        Http::assertNothingSent();
        $campaign->forceFill(['status' => 'running'])->save();
        $installation->forceFill(['status' => ConnectorInstallation::STATUS_DISABLED])->save();
        app(EmailTriageService::class)->process($message->evaluation_id, 'test-tenant');
        Http::assertNothingSent();
        $installation->forceFill(['status' => ConnectorInstallation::STATUS_ACTIVE])->save();
        $activeProfileId = $installation->config_json['email_triage_profile_id'];
        $disabled = EmailTriageProfile::create(['tenant_id' => 'test-tenant', 'connector_installation_id' => $installation->id,
            'revision' => 2, 'enabled' => false, 'rules' => [$this->rule()], 'model' => config('decisions.models.jev.model')]);
        $installation->forceFill(['config_json' => ['email_triage_profile_id' => $disabled->id]])->save();
        app(EmailTriageService::class)->process($message->evaluation_id, 'test-tenant');
        Http::assertNothingSent();
        $campaigns->pump($campaign);
        $this->assertSame('paused', $campaign->fresh()->status);
        $campaign->forceFill(['status' => 'running'])->save();
        $installation->forceFill(['config_json' => ['email_triage_profile_id' => $activeProfileId]])->save();
        app(EmailTriageService::class)->process($message->evaluation_id, 'test-tenant');
        Http::assertSentCount(1);
    }

    public function test_pilot_completes_ten_synthetic_rounds_even_if_one_fails_without_ingestion(): void
    {
        Storage::fake('local');
        $installation = $this->installation(false);
        $connectorContext = app(\Padosoft\AskMyDocsConnectorBase\Support\TenantContext::class);
        $connectorContext->set('other-tenant');
        $client = \Mockery::mock(\App\Connectors\Imap\Backfill\ImapBackfillClient::class);
        $client->shouldReceive('snapshotMailbox')->once()->with('INBOX')
            ->andReturn(new \App\Connectors\Imap\Backfill\ImapBackfillMailboxSnapshot(7, 109, 10));
        $client->shouldReceive('uidsBetween')->once()->andReturn(range(100, 109));
        $client->shouldReceive('fetchMessages')->times(19)->andReturnUsing(fn ($mailbox, $uids) => [$this->email($uids[0])]);
        $client->shouldReceive('close')->once();
        $provider = \Mockery::mock(\App\Connectors\Imap\Backfill\ImapBackfillClientProviderContract::class);
        $provider->shouldReceive('forInstallation')->once()->andReturnUsing(function () use ($client, $connectorContext) {
            $this->assertSame('test-tenant', $connectorContext->current());
            $this->assertSame('test-tenant', app(\App\Support\TenantContext::class)->current());
            return $client;
        });
        $this->app->instance(\App\Connectors\Imap\Backfill\ImapBackfillClientProviderContract::class, $provider);
        $calls = 0;
        Http::fake(function ($request) use (&$calls) {
            if (++$calls === 1) return Http::response([], 503);
            $answers = [];
            foreach ($request['questions'] as $key => $question) {
                $probabilities = array_fill_keys(array_keys($question['criteria']), 0.0);
                $probabilities[array_key_first($probabilities)] = 1.0;
                $answers[$key] = match ($question['type']) {
                    'noul' => ['type' => 'noul', 'noul' => 0.95],
                    'choice' => ['type' => 'choice', 'choice' => array_key_first($probabilities), 'confidence' => 1.0, 'probabilities' => $probabilities],
                    'score' => ['type' => 'score', 'score' => 0.0, 'confidence' => 1.0, 'probabilities' => $probabilities],
                };
            }
            return Http::response(['id' => 'synthetic-pilot', 'model' => config('decisions.models.jev.model'), 'answers' => $answers, 'usage' => ['cost' => 0.00001]]);
        });
        $this->artisan('email-triage:pilot', ['tenant' => 'test-tenant', 'installation' => $installation->id, '--rounds' => 10])->assertExitCode(1);
        $this->assertSame('other-tenant', $connectorContext->current());
        $path = Storage::disk('local')->files('email-triage')[0];
        $report = json_decode(Storage::disk('local')->get($path), true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(10, $report['rounds']);
        $this->assertSame('decision_http_503', $report['rounds'][0]['error_code']);
        foreach (array_slice($report['rounds'], 1) as $round) {
            $this->assertSame('completed', $round['status']);
            $this->assertTrue($round['body_unchanged']);
            $this->assertTrue($round['flags_unchanged']);
            $this->assertSame(1, $round['card_count']);
        }
        $this->assertSame(0, EmailTriageMessage::count());
        $this->assertSame(0, KnowledgeDocument::count());
        Queue::assertNotPushed(IngestDocumentJob::class);
        Http::assertSentCount(10);
    }
}
