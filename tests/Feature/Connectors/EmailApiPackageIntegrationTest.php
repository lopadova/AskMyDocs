<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Connectors\HostIngestionBridge;
use App\Jobs\EmailTriage\AnalyzeEmailJob;
use App\Jobs\IngestDocumentJob;
use App\Models\EmailTriageMessage;
use App\Models\EmailTriageProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Padosoft\AskMyDocsConnectorBase\Auth\OAuthCredentialVault;
use Padosoft\AskMyDocsConnectorBase\ConnectorRegistry;
use Padosoft\AskMyDocsConnectorBase\Contracts\ConnectorIngestionContract;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Installed production packages must acquire email through the real host bridge. */
final class EmailApiPackageIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        foreach (['Padosoft\\AskMyDocsConnectorGmail\\GmailServiceProvider',
            'Padosoft\\AskMyDocsConnectorMicrosoftMail\\MicrosoftMailServiceProvider'] as $provider) {
            if (class_exists($provider)) {
                $app->register($provider);
            }
        }
    }

    public static function providers(): array
    {
        return ['Gmail/plain' => ['gmail', false], 'Gmail/triage' => ['gmail', true],
            'Microsoft/plain' => ['microsoft-mail', false], 'Microsoft/triage' => ['microsoft-mail', true]];
    }

    #[DataProvider('providers')]
    public function test_installed_package_discovery_sync_and_triage_through_real_bridge(string $provider, bool $triage): void
    {
        $class = $provider === 'gmail' ? 'Padosoft\\AskMyDocsConnectorGmail\\GmailConnector'
            : 'Padosoft\\AskMyDocsConnectorMicrosoftMail\\MicrosoftMailConnector';
        $this->assertTrue(class_exists($class), 'Install the required email packages from composer.lock before running the host integration suite.');
        Queue::fake();
        Storage::fake('kb');
        Storage::fake('local');
        Http::preventStrayRequests();
        config(['kb.sources.disk' => 'kb', 'kb.sources.path_prefix' => '', 'kb.ocr.enabled' => false,
            'connector-gmail.http.retry_max_delay_seconds' => 0]);
        app(TenantContext::class)->set('test-tenant');
        $installation = ConnectorInstallation::create(['tenant_id' => 'test-tenant', 'connector_name' => $provider,
            'label' => 'local-fixture', 'project_key' => 'mail', 'status' => 'active',
            'config_json' => $provider === 'gmail' ? [] : ['folders' => ['include' => ['inbox-id']]]]);
        if ($triage) {
            $profile = EmailTriageProfile::create(['tenant_id' => 'test-tenant', 'connector_installation_id' => $installation->id,
                'revision' => 1, 'enabled' => true, 'model' => config('decisions.models.jev.model'),
                'rules' => [['key' => 'request', 'label' => 'Request', 'type' => 'noul', 'enabled' => true,
                    'instructions' => 'Does the sender request assistance?', 'threshold' => 0.85,
                    'options' => [['key' => 'true', 'label' => 'Yes', 'criterion' => 'Assistance requested', 'text' => 'Request', 'tag' => 'request'],
                        ['key' => 'false', 'label' => 'No', 'criterion' => 'No assistance requested', 'text' => 'Ordinary email', 'tag' => null]]]]]);
            $installation->forceFill(['config_json' => ($installation->config_json ?? []) + ['email_triage_profile_id' => $profile->id]])->save();
        }
        $extra = $provider === 'gmail' ? ['gmail_mailbox_id' => 'synthetic-google-sub',
            'gmail_granted_scopes' => ['openid', 'https://www.googleapis.com/auth/gmail.readonly']]
            : ['mailbox_id' => 'synthetic-directory:synthetic-user', 'mailbox_path' => '/me', 'mailbox_target_id' => null,
                'microsoft_directory_id' => 'synthetic-directory', 'microsoft_account_id' => 'synthetic-user',
                'scope' => 'offline_access Mail.Read User.Read openid'];
        app(OAuthCredentialVault::class)->setCredentials($installation->id, 'synthetic-access', 'synthetic-refresh', now()->addHour(), $extra);
        $registry = app(ConnectorRegistry::class);
        $this->assertTrue($registry->has($provider));
        $connector = $registry->get($provider);
        $this->assertInstanceOf(HostIngestionBridge::class, app(ConnectorIngestionContract::class));
        $this->fakeMailbox($provider);
        $this->assertNotEmpty($connector->listAvailableFolders($installation->id));
        $this->assertSame('healthy', $connector->health($installation->id)->state);
        $result = $connector->syncFull($installation->id);
        $this->assertFalse($result->hasErrors(), json_encode($result->errors));
        $this->assertSame(1, $result->documentsAdded);
        Queue::assertPushed(IngestDocumentJob::class, $triage ? 1 : 2);
        Queue::assertPushed(IngestDocumentJob::class, fn ($job) => ($job->metadata['connector'] ?? null) === $provider
            && ($job->metadata['email_schema_version'] ?? null) === 1 && isset($job->metadata['email_attachment_slot']));
        if ($triage) {
            $message = EmailTriageMessage::query()->forTenant('test-tenant')->sole();
            $this->assertNotNull($message->original_hash);
            $this->assertSame('saved', $message->manifest[0]['status']);
            $this->assertNotNull($message->evaluation_id);
        } else {
            $this->assertSame(0, EmailTriageMessage::count());
        }
        Queue::assertPushed(AnalyzeEmailJob::class, $triage ? 1 : 0); // Queue fake never executes an AI worker.
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'openrouter') || str_contains($request->url(), 'openai'));
    }

    private function fakeMailbox(string $provider): void
    {
        Http::fake(function ($request) use ($provider) {
            $path = rawurldecode((string) parse_url($request->url(), PHP_URL_PATH));
            if ($provider === 'gmail') {
                if (str_ends_with($path, '/labels')) {
                    return Http::response(['labels' => [['id' => 'INBOX', 'name' => 'Inbox']]]);
                }
                if (str_ends_with($path, '/profile')) {
                    return Http::response(['emailAddress' => 'synthetic@example.test', 'historyId' => '100']);
                }
                if (str_ends_with($path, '/history')) {
                    return Http::response(['historyId' => '200', 'history' => []]);
                }
                if (str_ends_with($path, '/messages')) {
                    return Http::response(['messages' => [['id' => 'NativeID']]]);
                }
                if (str_ends_with($path, '/messages/NativeID')) {
                    return Http::response(['id' => 'NativeID', 'threadId' => 'thread', 'historyId' => '100', 'internalDate' => '1791936000000',
                        'labelIds' => ['INBOX'], 'payload' => ['mimeType' => 'multipart/mixed',
                            'headers' => [['name' => 'Subject', 'value' => 'Synthetic request'], ['name' => 'From', 'value' => 'sender@example.test']],
                            'parts' => [['partId' => '0', 'mimeType' => 'text/plain', 'body' => ['data' => $this->encode('Please help with this request.')]],
                                ['partId' => '1', 'filename' => 'reference.txt', 'mimeType' => 'text/plain', 'body' => ['size' => 9, 'data' => $this->encode('Reference')]]]]]);
                }
            } else {
                $this->assertStringContainsString('IdType="ImmutableId"', implode(',', $request->header('Prefer')));
                if (str_ends_with($path, '/mailFolders/deleteditems')) {
                    return Http::response(['id' => 'deleted-id']);
                }
                if (str_ends_with($path, '/mailFolders/junkemail')) {
                    return Http::response(['id' => 'junk-id']);
                }
                if (str_ends_with($path, '/mailFolders/inbox')) {
                    return Http::response(['id' => 'inbox-id']);
                }
                if (str_ends_with($path, '/mailFolders')) {
                    return Http::response(['value' => [['id' => 'inbox-id', 'displayName' => 'Inbox', 'childFolderCount' => 0]]]);
                }
                if (str_ends_with($path, '/messages/delta')) {
                    return Http::response(['value' => [['id' => 'NativeID']], '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/me/mailFolders/inbox-id/messages/delta?$deltatoken=committed']);
                }
                if (str_ends_with($path, '/messages/NativeID')) {
                    return Http::response(['id' => 'NativeID', 'subject' => 'Synthetic request', 'parentFolderId' => 'inbox-id',
                        'receivedDateTime' => '2026-10-14T10:00:00Z', 'body' => ['contentType' => 'text', 'content' => 'Please help with this request.'],
                        'from' => ['emailAddress' => ['address' => 'sender@example.test']], 'toRecipients' => [],
                        'internetMessageHeaders' => [['name' => 'Message-ID', 'value' => '<synthetic@example.test>']], 'isRead' => false, 'hasAttachments' => true]);
                }
                if (str_ends_with($path, '/attachments')) {
                    return Http::response(['value' => [['@odata.type' => '#microsoft.graph.fileAttachment', 'id' => 'AttachmentID',
                        'name' => 'reference.txt', 'contentType' => 'text/plain', 'size' => 9, 'isInline' => false, 'contentBytes' => base64_encode('Reference')]]]);
                }
            }
            throw new \RuntimeException('Unexpected email API fixture route.');
        });
    }

    private function encode(string $text): string
    {
        return rtrim(strtr(base64_encode($text), '+/', '-_'), '=');
    }
}
