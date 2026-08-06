<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Connectors\Imap\ImapSyncProgressContext;
use App\Connectors\Imap\ProgressTrackingImapClientFactory;
use App\Connectors\Imap\ProgressTrackingIngestionBridge;
use App\Connectors\SerializedConnectorSyncJob;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Padosoft\AskMyDocsConnectorBase\Auth\OAuthCredentialVault;
use Padosoft\AskMyDocsConnectorBase\ConnectorInterface;
use Padosoft\AskMyDocsConnectorBase\ConnectorRegistry;
use Padosoft\AskMyDocsConnectorBase\Contracts\ConnectorIngestionContract;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;
use Padosoft\AskMyDocsConnectorBase\SyncResult;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapAttachment;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientFactoryInterface;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientInterface;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapMessage;
use Padosoft\AskMyDocsConnectorImap\Imap\MailboxState;
use Padosoft\AskMyDocsConnectorImap\ImapConnector;
use Tests\TestCase;

final class ImapSyncProgressTest extends TestCase
{
    use RefreshDatabase;

    public function test_truncated_sync_checkpoints_and_next_run_resumes_after_the_cap(): void
    {
        Storage::fake('local');

        $tenantContext = $this->app->make(TenantContext::class);
        $tenantContext->set('default');
        $vault = $this->app->make(OAuthCredentialVault::class);
        $installation = $this->installation(maxMessages: 2);
        $vault->setCredentials(
            $installation->id,
            accessToken: 'secret',
            extra: ['auth_mode' => 'basic'],
        );

        $messages = [$this->message(1), $this->message(2), $this->message(3)];

        // Checkpoint every 2 messages so the package's stale finally write
        // lands exactly after a periodic checkpoint. finish() must still
        // re-assert the safe state after that overwrite.
        $firstProgress = new ImapSyncProgressContext($vault, $tenantContext, 2);
        $firstIngestion = new RecordingIngestionContract;
        $firstConnector = $this->connector(
            new ArrayImapClient(['INBOX' => $messages]),
            $vault,
            $tenantContext,
            $firstProgress,
            $firstIngestion,
        );

        $firstProgress->begin($installation);
        try {
            $firstResult = $firstConnector->syncFull($installation->id);
        } finally {
            $this->assertTrue(
                $firstProgress->hasUnconfirmedWork(),
                'the searched UID left behind by the cap must mark the run incomplete',
            );
            $firstProgress->finish();
        }

        $this->assertSame([1, 2], $firstIngestion->imapUids);
        $this->assertSame(
            ['sync truncated at max_messages_per_sync=2'],
            $firstResult->errors,
        );
        $this->assertSame(
            2,
            $vault->getExtra($installation->id)['mailboxes_state']['INBOX']['last_uid'],
            'the cap path must persist the last fully-ingested UID',
        );

        $secondProgress = new ImapSyncProgressContext($vault, $tenantContext);
        $secondIngestion = new RecordingIngestionContract;
        $secondConnector = $this->connector(
            new ArrayImapClient(['INBOX' => $messages]),
            $vault,
            $tenantContext,
            $secondProgress,
            $secondIngestion,
        );

        $secondProgress->begin($installation);
        try {
            $secondResult = $secondConnector->syncFull($installation->id);
        } finally {
            $this->assertFalse(
                $secondProgress->hasUnconfirmedWork(),
                'a fully confirmed continuation must clear the incomplete signal',
            );
            $secondProgress->finish();
        }

        $this->assertSame([3], $secondIngestion->imapUids);
        $this->assertSame([], $secondResult->errors);
        $this->assertSame(
            3,
            $vault->getExtra($installation->id)['mailboxes_state']['INBOX']['last_uid'],
        );
    }

    public function test_failed_attachment_does_not_advance_watermark_past_the_message(): void
    {
        $tenantContext = $this->app->make(TenantContext::class);
        $tenantContext->set('default');
        $vault = $this->app->make(OAuthCredentialVault::class);
        $installation = $this->installation(maxMessages: 50, attachments: true);
        $vault->setCredentials(
            $installation->id,
            accessToken: 'secret',
            extra: ['auth_mode' => 'basic'],
        );

        $attachment = new ImapAttachment(
            filename: 'invoice.pdf',
            mimeType: 'application/pdf',
            sizeBytes: 100,
            isInline: false,
            contents: '%PDF',
        );
        $first = $this->message(1, [$attachment]);
        $second = $this->message(2);
        $progress = new ImapSyncProgressContext($vault, $tenantContext);

        $progress->begin($installation);
        $progress->observeSearch('INBOX', 1, [1, 2]);
        $progress->observeFetched($first);
        $progress->recordSuccessfulDispatch(
            $this->metadata($first, $installation->id),
            'default',
        );
        // The attachment dispatch never arrives. Seeing uid=2 seals uid=1 as
        // failed and blocks the contiguous watermark before it.
        $progress->observeFetched($second);
        $progress->recordSuccessfulDispatch(
            $this->metadata($second, $installation->id),
            'default',
        );
        $this->assertTrue(
            $progress->hasUnconfirmedWork(),
            'a missing attachment acknowledgement must mark the run incomplete',
        );
        $progress->finish();

        $this->assertSame(
            0,
            $vault->getExtra($installation->id)['mailboxes_state']['INBOX']['last_uid'],
            'a later success must not skip an earlier message with a failed attachment',
        );
    }

    public function test_progress_callbacks_cannot_write_another_tenants_mailbox_state(): void
    {
        $tenantContext = $this->app->make(TenantContext::class);
        $vault = $this->app->make(OAuthCredentialVault::class);

        $tenantContext->set('tenant-a');
        $tenantA = ConnectorInstallation::query()->create([
            'tenant_id' => 'tenant-a',
            'connector_name' => 'imap',
            'label' => 'a',
            'config_json' => [
                'connection' => ['host' => 'a.test', 'username' => 'a@test'],
                'attachments' => ['enabled' => false],
            ],
            'status' => ConnectorInstallation::STATUS_ACTIVE,
            'created_by' => 1,
        ]);
        $vault->setCredentials($tenantA->id, accessToken: 'a');

        $tenantContext->set('tenant-b');
        $tenantB = ConnectorInstallation::query()->create([
            'tenant_id' => 'tenant-b',
            'connector_name' => 'imap',
            'label' => 'b',
            'config_json' => [
                'connection' => ['host' => 'b.test', 'username' => 'b@test'],
                'attachments' => ['enabled' => false],
            ],
            'status' => ConnectorInstallation::STATUS_ACTIVE,
            'created_by' => 1,
        ]);
        $vault->setCredentials($tenantB->id, accessToken: 'b');

        $tenantContext->set('tenant-a');
        $message = $this->message(1);
        $progress = new ImapSyncProgressContext($vault, $tenantContext);
        $progress->begin($tenantA);
        $progress->observeSearch('INBOX', 1, [1]);
        $progress->observeFetched($message);

        // A callback carrying another tenant is ignored even though the
        // installation id and UID metadata otherwise match this session.
        $progress->recordSuccessfulDispatch(
            $this->metadata($message, $tenantA->id),
            'tenant-b',
        );
        $progress->finish();

        $this->assertSame(
            0,
            $vault->getExtra($tenantA->id)['mailboxes_state']['INBOX']['last_uid'],
        );

        $tenantContext->set('tenant-b');
        $this->assertArrayNotHasKey(
            'mailboxes_state',
            $vault->getExtra($tenantB->id),
            'tenant A progress must never mutate tenant B credentials',
        );
    }

    public function test_truncated_job_keeps_the_previous_sync_timestamp_for_backfill(): void
    {
        $tenantContext = $this->app->make(TenantContext::class);
        $tenantContext->set('operator-tenant');
        $previous = Carbon::parse('2026-01-02 03:04:05');
        $installation = ConnectorInstallation::query()->create([
            'tenant_id' => 'tenant-a',
            'connector_name' => 'imap',
            'label' => 'history',
            'config_json' => [
                'connection' => [
                    'host' => 'imap.example.test',
                    'username' => 'history@example.test',
                ],
            ],
            'status' => ConnectorInstallation::STATUS_ACTIVE,
            'last_sync_at' => $previous,
            'created_by' => 1,
        ]);

        $connector = Mockery::mock(ConnectorInterface::class);
        $connector->shouldReceive('syncIncremental')
            ->once()
            ->with($installation->id, Mockery::on(
                static fn ($since) => $since instanceof Carbon && $since->equalTo($previous),
            ))
            ->andReturn(new SyncResult(
                documentsAdded: 2,
                documentsUpdated: 0,
                documentsRemoved: 0,
                errors: ['sync truncated at max_messages_per_sync=2'],
                completedAt: Carbon::parse('2026-01-03 00:00:00'),
            ));

        $registry = Mockery::mock(ConnectorRegistry::class);
        $registry->shouldReceive('get')->with('imap')->andReturn($connector);

        $job = new SerializedConnectorSyncJob($installation->id, 'tenant-a');
        $job->handle(
            $registry,
            $tenantContext,
            $this->app->make(ImapSyncProgressContext::class),
        );

        $installation->refresh();
        $this->assertTrue(
            $installation->last_sync_at->equalTo($previous),
            'a partial historical run must not move the date filter past unsynced mail',
        );
        $this->assertSame('operator-tenant', $tenantContext->current());
    }

    public function test_reconcile_search_preserves_a_completed_timestamp_and_mailbox_watermark(): void
    {
        Storage::fake('local');

        $tenantContext = $this->app->make(TenantContext::class);
        $tenantContext->set('default');
        $vault = $this->app->make(OAuthCredentialVault::class);
        $previous = Carbon::parse('2026-02-10 09:00:00');
        $completedAt = Carbon::parse('2026-02-11 10:00:00');
        $installation = $this->installation(
            maxMessages: 50,
            reconcileDeletions: true,
            lastSyncAt: $previous,
        );
        $vault->setCredentials(
            $installation->id,
            accessToken: 'secret',
            extra: ['auth_mode' => 'basic'],
        );

        $client = new ArrayImapClient([
            'INBOX' => [$this->message(1), $this->message(2)],
        ]);
        $progress = new ImapSyncProgressContext($vault, $tenantContext);
        $recording = new RecordingIngestionContract;
        $connector = $this->connector(
            $client,
            $vault,
            $tenantContext,
            $progress,
            $recording,
        );

        $registry = Mockery::mock(ConnectorRegistry::class);
        $registry->shouldReceive('get')->with('imap')->andReturn($connector);

        Carbon::setTestNow($completedAt);
        try {
            (new SerializedConnectorSyncJob($installation->id, 'default'))->handle(
                $registry,
                $tenantContext,
                $progress,
            );
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame([1, 2], $recording->imapUids);
        $this->assertCount(
            2,
            $client->searches,
            'reconcile_deletions must execute its second, unfiltered UID search',
        );
        $this->assertTrue($client->searches[0]['since']?->equalTo($previous) ?? false);
        $this->assertNull($client->searches[1]['since']);
        $this->assertNull($client->searches[1]['since_uid']);

        $installation->refresh();
        $this->assertTrue(
            $installation->last_sync_at?->equalTo($completedAt) ?? false,
            'a completed ingest must keep the new sync timestamp despite the reconcile search',
        );
        $this->assertSame(
            2,
            $vault->getExtra($installation->id)['mailboxes_state']['INBOX']['last_uid'],
            'the reconcile search must not reopen already confirmed ingestion work',
        );
        $this->assertFalse($progress->isActive());
    }

    public function test_job_restores_timestamp_and_tenant_when_an_attachment_is_unconfirmed_and_finish_throws(): void
    {
        $tenantContext = $this->app->make(TenantContext::class);
        $tenantContext->set('tenant-a');
        $previous = Carbon::parse('2026-02-03 04:05:06');
        $installation = ConnectorInstallation::query()->create([
            'tenant_id' => 'tenant-a',
            'connector_name' => 'imap',
            'label' => 'attachment-watermark',
            'config_json' => [
                'connection' => [
                    'host' => 'imap.example.test',
                    'username' => 'attachment-watermark@example.test',
                ],
                'attachments' => [
                    'enabled' => true,
                    'allowed_extensions' => ['pdf'],
                    'max_size_mb' => 25,
                    'max_per_email' => 20,
                    'skip_inline' => false,
                ],
            ],
            'status' => ConnectorInstallation::STATUS_ACTIVE,
            'last_sync_at' => $previous,
            'created_by' => 1,
        ]);

        $vault = new class($tenantContext) extends OAuthCredentialVault
        {
            public bool $failProgressFlush = false;

            public function setExtraKey(int $installationId, string $key, mixed $value): void
            {
                if ($this->failProgressFlush) {
                    throw new \RuntimeException('Injected progress finish failure.');
                }

                parent::setExtraKey($installationId, $key, $value);
            }
        };
        $vault->setCredentials(
            $installation->id,
            accessToken: 'secret',
            extra: ['auth_mode' => 'basic'],
        );

        $attachment = new ImapAttachment(
            filename: 'invoice.pdf',
            mimeType: 'application/pdf',
            sizeBytes: 100,
            isInline: false,
            contents: '%PDF',
        );
        $message = $this->message(1, [$attachment]);
        $progress = new ImapSyncProgressContext($vault, $tenantContext);
        $completedAt = Carbon::parse('2026-02-04 00:00:00');

        $connector = Mockery::mock(ConnectorInterface::class);
        $connector->shouldReceive('syncIncremental')
            ->once()
            ->with($installation->id, Mockery::on(
                static fn ($since) => $since instanceof Carbon && $since->equalTo($previous),
            ))
            ->andReturnUsing(function () use (
                $progress,
                $message,
                $installation,
                $completedAt,
            ): SyncResult {
                $progress->observeSearch('INBOX', 1, [1]);
                $progress->observeFetched($message);

                // Confirm only the body. The accepted PDF dispatch never
                // arrives, so this UID must remain behind the safe watermark.
                $progress->recordSuccessfulDispatch(
                    $this->metadata($message, $installation->id),
                    'tenant-a',
                );
                $this->assertTrue($progress->hasUnconfirmedWork());

                return new SyncResult(
                    documentsAdded: 1,
                    documentsUpdated: 0,
                    documentsRemoved: 0,
                    errors: ['INBOX uid 1: injected attachment dispatch failure'],
                    completedAt: $completedAt,
                );
            });

        $registry = Mockery::mock(ConnectorRegistry::class);
        $registry->shouldReceive('get')->with('imap')->andReturn($connector);

        $vault->failProgressFlush = true;
        $tenantContext->set('operator-tenant');

        try {
            (new SerializedConnectorSyncJob($installation->id, 'tenant-a'))->handle(
                $registry,
                $tenantContext,
                $progress,
            );
            $this->fail('The injected progress finish failure must propagate.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected progress finish failure.', $exception->getMessage());
        }

        $installation->refresh();
        $this->assertTrue(
            $installation->last_sync_at->equalTo($previous),
            'an unconfirmed attachment must not advance the date watermark even when finish fails',
        );
        $this->assertSame(
            'operator-tenant',
            $tenantContext->current(),
            'the queue worker tenant must be restored after the finish failure',
        );
        $this->assertFalse($progress->isActive());
    }

    /**
     * @param  list<ImapAttachment>  $attachments
     */
    private function message(int $uid, array $attachments = []): ImapMessage
    {
        return new ImapMessage(
            uid: $uid,
            uidValidity: 1,
            mailbox: 'INBOX',
            messageId: "<message-{$uid}@example.test>",
            inReplyTo: null,
            references: [],
            fromName: 'Example',
            fromEmail: 'sender@example.test',
            to: [['name' => 'Support', 'email' => 'support@example.test']],
            cc: [],
            date: Carbon::parse('2025-01-01')->addMinutes($uid),
            subject: "Message {$uid}",
            flags: [],
            labels: [],
            textBody: "Body {$uid}",
            htmlBody: null,
            rawHeaders: [],
            attachments: $attachments,
        );
    }

    private function installation(
        int $maxMessages,
        bool $attachments = false,
        bool $reconcileDeletions = false,
        ?Carbon $lastSyncAt = null,
    ): ConnectorInstallation {
        return ConnectorInstallation::query()->create([
            'tenant_id' => 'default',
            'connector_name' => 'imap',
            'label' => 'progress',
            'project_key' => 'imap-progress',
            'config_json' => [
                'auth_mode' => 'basic',
                'connection' => [
                    'host' => 'imap.example.test',
                    'username' => 'progress@example.test',
                ],
                'date_window_days' => 0,
                'reconcile_deletions' => $reconcileDeletions,
                'limits' => ['max_messages_per_sync' => $maxMessages],
                'attachments' => [
                    'enabled' => $attachments,
                    'allowed_extensions' => ['pdf'],
                    'max_size_mb' => 25,
                    'max_per_email' => 20,
                    'skip_inline' => false,
                ],
            ],
            'status' => ConnectorInstallation::STATUS_ACTIVE,
            'last_sync_at' => $lastSyncAt,
            'created_by' => 1,
        ]);
    }

    private function connector(
        ImapClientInterface $client,
        OAuthCredentialVault $vault,
        TenantContext $tenantContext,
        ImapSyncProgressContext $progress,
        RecordingIngestionContract $recording,
    ): ImapConnector {
        $factory = new class($client) implements ImapClientFactoryInterface
        {
            public function __construct(private readonly ImapClientInterface $client) {}

            public function make(array $connection, string $secret, string $authMode): ImapClientInterface
            {
                return $this->client;
            }
        };

        return new ImapConnector(
            $vault,
            $tenantContext,
            new ProgressTrackingIngestionBridge($recording, $progress),
            new ProgressTrackingImapClientFactory($factory, $progress),
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function metadata(ImapMessage $message, int $installationId): array
    {
        return [
            'connector' => 'imap',
            'installation_id' => $installationId,
            'imap_uid' => (string) $message->uid,
            'imap_doc_key' => $message->mailbox.':'.$message->uidValidity.':'.$message->uid,
            'imap_mailbox' => $message->mailbox,
        ];
    }
}

/**
 * @internal
 */
final class ArrayImapClient implements ImapClientInterface
{
    /**
     * @var list<array{mailbox:string,since:?Carbon,since_uid:?int}>
     */
    public array $searches = [];

    /**
     * @param  array<string,list<ImapMessage>>  $messages
     */
    public function __construct(private readonly array $messages) {}

    public function listMailboxes(): array
    {
        return array_keys($this->messages);
    }

    public function selectMailbox(string $name): MailboxState
    {
        $lastUid = 0;
        foreach ($this->messages[$name] ?? [] as $message) {
            $lastUid = max($lastUid, $message->uid);
        }

        return new MailboxState(1, $lastUid);
    }

    public function searchUids(string $mailbox, ?Carbon $since, ?int $sinceUid): array
    {
        $this->searches[] = [
            'mailbox' => $mailbox,
            'since' => $since?->copy(),
            'since_uid' => $sinceUid,
        ];

        $uids = [];
        foreach ($this->messages[$mailbox] ?? [] as $message) {
            if ($sinceUid === null || $message->uid > $sinceUid) {
                $uids[] = $message->uid;
            }
        }

        return $uids;
    }

    public function fetchMessage(string $mailbox, int $uid): ImapMessage
    {
        foreach ($this->messages[$mailbox] ?? [] as $message) {
            if ($message->uid === $uid) {
                return $message;
            }
        }

        throw new \RuntimeException("Missing test message {$mailbox}:{$uid}.");
    }

    public function ping(): bool
    {
        return true;
    }

    public function close(): void {}
}

/**
 * @internal
 */
final class RecordingIngestionContract implements ConnectorIngestionContract
{
    /** @var list<int> */
    public array $imapUids = [];

    public function dispatchIngestion(
        string $projectKey,
        string $relativePath,
        string $disk,
        string $title,
        array $metadata,
        string $mimeType,
        string $tenantId,
    ): void {
        $this->imapUids[] = (int) $metadata['imap_uid'];
    }

    public function resolveKbSourcePath(string $relativePath): array
    {
        return [
            'relative' => $relativePath,
            'absolute' => $relativePath,
            'disk' => 'local',
        ];
    }

    public function redactContent(string $content): string
    {
        return $content;
    }

    public function emitAudit(
        string $connectorKey,
        string $eventType,
        ?int $installationId = null,
        ?array $metadata = null,
    ): void {}

    public function softDeleteByRemoteId(
        ConnectorInstallation $installation,
        string $metadataKey,
        string $remoteId,
    ): bool {
        return false;
    }
}
