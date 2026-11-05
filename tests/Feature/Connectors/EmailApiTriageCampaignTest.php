<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Connectors\Imap\Backfill\ImapBackfillClientProviderContract;
use App\Jobs\EmailTriage\AnalyzeEmailJob;
use App\Models\EmailTriageCampaignItem;
use App\Models\EmailTriageMessage;
use App\Models\EmailTriageProfile;
use App\Models\KnowledgeDocument;
use App\Services\EmailTriage\TriageCampaigns;
use App\Services\Kb\Versioning\ConversionArtifactStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class EmailApiTriageCampaignTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('kb');
        Http::preventStrayRequests();
        config(['kb.sources.disk' => 'kb', 'kb.sources.path_prefix' => '', 'email-triage.campaign_batch_size' => 1]);
    }

    public static function providers(): array
    {
        return [['gmail'], ['microsoft-mail']];
    }

    private function installation(string $provider, string $tenant = 'test-tenant'): ConnectorInstallation
    {
        $installation = ConnectorInstallation::create(['tenant_id' => $tenant, 'connector_name' => $provider,
            'label' => uniqid('synthetic-'), 'project_key' => 'mail', 'status' => 'active', 'config_json' => []]);
        $profile = EmailTriageProfile::create(['tenant_id' => $tenant, 'connector_installation_id' => $installation->id,
            'revision' => 1, 'enabled' => true, 'rules' => [], 'model' => config('decisions.models.jev.model')]);
        $installation->forceFill(['config_json' => ['email_triage_profile_id' => $profile->id]])->save();

        return $installation;
    }

    private function document(ConnectorInstallation $installation, string $id = 'CaseSensitiveMessage', array $override = []): KnowledgeDocument
    {
        $body = "# Synthetic historical message\n\nA parcel needs attention.";
        $key = $installation->connector_name.':'.hash('sha256', json_encode([
            (string) $installation->tenant_id, $installation->id, $installation->connector_name, 'stable-mailbox', $id,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $bodyHash = hash('sha256', $body);
        $metadata = array_replace(['connector' => $installation->connector_name, 'installation_id' => $installation->id,
            'email_schema_version' => 1, 'email_provider' => $installation->connector_name, 'email_mailbox_id' => 'stable-mailbox',
            'email_message_id' => $id, 'email_message_key' => $key, 'email_folder_ids' => ['INBOX-ID', 'LABEL-SECOND'],
            'email_folder_names' => ['Inbox', 'Customer service'], 'email_date' => '2026-10-12T12:00:00+00:00',
            'email_attachment_count' => 0, 'email_body_hash' => $bodyHash,
            'email_revision_hash' => hash('sha256', json_encode([$bodyHash, []], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            'disk' => 'kb', 'prefix' => ''], $override);
        $path = 'api-mail/'.$installation->id.'/'.hash('sha256', $id).'.md';
        Storage::disk('kb')->put($path, $body);

        return KnowledgeDocument::create(['tenant_id' => $installation->tenant_id, 'project_key' => 'mail', 'source_path' => $path,
            'title' => 'Synthetic historical message', 'source_type' => $installation->connector_name, 'mime_type' => 'text/markdown',
            'status' => 'active', 'access_scope' => 'restricted', 'document_hash' => hash('sha256', $path),
            'version_hash' => hash('sha256', $path), 'metadata' => $metadata]);
    }

    #[DataProvider('providers')]
    public function test_campaign_selection_uses_native_folder_ids_date_and_isolated_parent_identity(string $provider): void
    {
        $installation = $this->installation($provider);
        $selected = $this->document($installation);
        $this->document($installation, 'outside-date', ['email_date' => '2026-10-10T12:00:00+00:00']);
        $this->document($installation, 'other-folder', ['email_folder_ids' => ['OTHER']]);
        $this->document($installation, 'attachment', ['attachment_filename' => 'same.txt', 'email_attachment_id' => 'A']);
        $this->document($this->installation($provider), 'other-installation');
        $this->document($this->installation($provider, 'foreign-tenant'), 'other-tenant');
        $campaigns = app(TriageCampaigns::class);
        $filters = ['state' => 'all', 'project' => 'mail', 'mailbox' => 'LABEL-SECOND', 'from' => '2026-10-12', 'to' => '2026-10-12'];

        $this->assertSame([$selected->id], $campaigns->documents($installation, $filters, 0)->pluck('id')->all());
        $preview = $campaigns->preview($installation, $filters);
        $this->assertSame(1, $preview['total']);
        $this->assertSame(['checked' => 1, 'available' => 1, 'unavailable' => 0, 'unchecked' => 0], $preview['availability_sample']);
        Http::assertNothingSent();
    }

    #[DataProvider('providers')]
    public function test_historical_same_name_attachments_use_slots_parent_and_revision(string $provider): void
    {
        $installation = $this->installation($provider);
        $parent = $this->document($installation, 'parent', ['email_attachment_count' => 3]);
        foreach ([['Z-native-id', 1, 'second'], ['A-native-id', 0, 'first'], ['old-id', 2, 'obsolete']] as [$id, $slot, $bytes]) {
            $metadata = $parent->metadata + ['attachment_filename' => 'same.txt', 'attachment_of_message_id' => 'parent',
                'email_attachment_id' => $id, 'email_attachment_slot' => $slot];
            if ($id === 'old-id') {
                $metadata['email_revision_hash'] = hash('sha256', 'older revision');
            }
            $path = 'api-mail/'.$installation->id.'/'.$slot.'.txt';
            Storage::disk('kb')->put($path, $bytes);
            KnowledgeDocument::create(['tenant_id' => $installation->tenant_id, 'project_key' => 'mail', 'source_path' => $path,
                'title' => 'same.txt', 'source_type' => $provider, 'mime_type' => 'text/plain', 'status' => 'active',
                'document_hash' => hash('sha256', $path), 'version_hash' => hash('sha256', $path), 'metadata' => $metadata]);
        }
        $files = app(TriageCampaigns::class)->previewAttachments($parent);
        $this->assertCount(3, $files);
        $this->assertSame(['A-native-id', 'Z-native-id'], array_column(array_slice($files, 0, 2), 'id'));
        $this->assertSame([0, 1], array_column(array_slice($files, 0, 2), 'slot'));
        $this->assertSame([hash('sha256', 'first'), hash('sha256', 'second')], array_column(array_slice($files, 0, 2), 'hash'));
        $this->assertSame('not_imported_or_not_preserved', $files[2]['reason']);
        $this->assertSame([], Storage::disk('kb')->allFiles('.artifacts/email-triage'));
        Http::assertNothingSent();
    }

    #[DataProvider('providers')]
    public function test_campaign_keeps_profile_and_cutoff_through_pause_and_disabled_account(string $provider): void
    {
        $installation = $this->installation($provider);
        $document = $this->document($installation);
        $campaigns = app(TriageCampaigns::class);
        $campaign = $campaigns->start($installation, ['state' => 'all']);
        $pinned = $campaign->profile_id;
        $later = $this->document($installation, 'arrived-after-cutoff');
        $replacement = EmailTriageProfile::create(['tenant_id' => $installation->tenant_id, 'connector_installation_id' => $installation->id,
            'revision' => 2, 'enabled' => true, 'rules' => [], 'model' => config('decisions.models.jev.model')]);
        $installation->forceFill(['config_json' => ['email_triage_profile_id' => $replacement->id]])->save();
        $campaign->forceFill(['status' => 'paused'])->save();
        $campaigns->pump($campaign);
        $this->assertSame(0, EmailTriageCampaignItem::count());
        $campaign->forceFill(['status' => 'running'])->save();
        $installation->forceFill(['status' => ConnectorInstallation::STATUS_DISABLED])->save();
        $campaigns->pump($campaign);
        $this->assertSame('paused', $campaign->fresh()->status);
        $installation->forceFill(['status' => ConnectorInstallation::STATUS_ACTIVE])->save();
        $campaign->forceFill(['status' => 'running'])->save();
        $campaigns->pump($campaign);
        $item = EmailTriageCampaignItem::firstOrFail();
        $message = EmailTriageMessage::findOrFail($item->message_id);
        $this->assertSame($document->id, $item->knowledge_document_id);
        $this->assertSame($pinned, $message->profile_id);
        $this->assertSame($document->id, $campaign->fresh()->cutoff_id);
        $this->assertNotSame($later->id, $item->knowledge_document_id);
        $this->assertSame(1, EmailTriageCampaignItem::count());
        Http::assertNothingSent();
    }

    #[DataProvider('providers')]
    public function test_api_accounts_are_rejected_by_the_download_pilot_before_imap_or_ai(string $provider): void
    {
        Storage::fake('local');
        $installation = $this->installation($provider);
        $clients = \Mockery::mock(ImapBackfillClientProviderContract::class);
        $clients->shouldNotReceive('forInstallation');
        $this->app->instance(ImapBackfillClientProviderContract::class, $clients);
        $this->artisan('email-triage:pilot', ['tenant' => 'test-tenant', 'installation' => $installation->id, '--rounds' => 1])
            ->expectsOutput('The download pilot supports IMAP only. For Gmail/Microsoft Mail use connection health, folder discovery or a triage preview of an imported email.')
            ->assertExitCode(1);
        $report = json_decode(Storage::disk('local')->get(Storage::disk('local')->files('email-triage')[0]), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('unsupported', $report['status']);
        $this->assertSame('unsupported_api_download_pilot', $report['error_code']);
        $this->assertSame([], $report['rounds']);
        $this->assertSame(0, EmailTriageMessage::count());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    #[DataProvider('providers')]
    public function test_corrupt_live_source_recovers_only_from_an_artifact_matching_the_acquired_body(string $provider): void
    {
        $installation = $this->installation($provider);
        $document = $this->document($installation);
        $original = Storage::disk('kb')->get($document->source_path);
        $hash = hash('sha256', $original);
        $artifact = app(ConversionArtifactStore::class)->pathFor(
            $document->tenant_id, $document->project_key, $document->source_path, $hash,
        );
        Storage::disk('kb')->put($artifact, $original);
        $document->forceFill(['markdown_path' => $artifact, 'content_hash' => $hash])->save();
        Storage::disk('kb')->put($document->source_path, 'A corrupted live source');
        $campaigns = app(TriageCampaigns::class);
        $this->assertSame($original, $campaigns->body($document));
        $this->assertSame(1, $campaigns->preview($installation, ['state' => 'all'])['availability_sample']['available']);
        // Validating the artifact's own content hash alone does not attest the email body.
        Storage::disk('kb')->put($artifact, 'Another complete but unrelated artifact');
        $document->forceFill(['content_hash' => hash('sha256', 'Another complete but unrelated artifact')])->save();
        $this->assertSame(1, $campaigns->preview($installation, ['state' => 'all'])['availability_sample']['unavailable']);
        Http::assertNothingSent();
    }

    #[DataProvider('providers')]
    public function test_missing_or_corrupt_api_sources_are_unavailable_without_remote_fetch(string $provider): void
    {
        $installation = $this->installation($provider);
        $missing = $this->document($installation, 'missing');
        Storage::disk('kb')->delete($missing->source_path);
        $corrupt = $this->document($installation, 'corrupt');
        Storage::disk('kb')->put($corrupt->source_path, 'Changed after the captured email body hash');
        $campaigns = app(TriageCampaigns::class);
        $preview = $campaigns->preview($installation, ['state' => 'all']);
        $this->assertSame(2, $preview['availability_sample']['unavailable']);
        $campaign = $campaigns->start($installation, ['state' => 'all']);
        $campaigns->pump($campaign);
        $campaigns->pump($campaign);
        $this->assertSame(2, EmailTriageCampaignItem::where('status', 'unavailable')->count());
        $this->assertSame(0, EmailTriageMessage::count());
        Queue::assertNotPushed(AnalyzeEmailJob::class);
        Http::assertNothingSent();
    }
}
