<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Connectors\Imap\Backfill\ImapBackfillClient;
use App\Connectors\Imap\Backfill\ImapBackfillClientProviderContract;
use App\Connectors\Imap\Backfill\ImapBackfillDiscovery;
use App\Connectors\Imap\Backfill\ImapBackfillImporter;
use App\Connectors\Imap\Backfill\ImapBackfillMailboxSnapshot;
use App\Jobs\Imap\ImportImapBackfillWindowJob;
use App\Jobs\Imap\PumpImapBackfillJob;
use App\Models\ImapBackfill;
use App\Models\ImapBackfillWindow;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Padosoft\AskMyDocsConnectorBase\Contracts\ConnectorIngestionContract;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapAttachment;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapMessage;
use Padosoft\AskMyDocsConnectorImap\Imap\MailboxState;
use RuntimeException;
use Tests\TestCase;

final class ImapBackfillAlgorithmsTest extends TestCase
{
    use RefreshDatabase;

    public function test_discovery_segments_date_boundaries_and_persists_uid_snapshot(): void
    {
        config()->set('connectors.imap.backfill.absolute_start', '2025-12-01');
        $installation = $this->installation();
        $backfill = $this->backfill($installation, ImapBackfill::STATUS_DISCOVERING, [
            'cutoff_at' => Carbon::parse('2026-03-20 12:00:00'),
        ]);
        $client = new AlgorithmFakeImapClient;
        $client->mailboxNames = ['INBOX'];
        $client->snapshot = new ImapBackfillMailboxSnapshot(uidValidity: 77, maxUid: 20, messageCount: 2);
        $client->betweenUidValues = [10, 20];
        $client->singleMessages[10] = $this->message(10, Carbon::parse('2026-01-15'));

        (new ImapBackfillDiscovery($this->provider($client)))->discover($installation, $backfill);

        $windows = ImapBackfillWindow::query()->orderBy('window_start')->get();
        $this->assertSame(4, $windows->count());
        $this->assertSame(
            [
                ['2025-12-01', '2026-01-01'],
                ['2026-01-01', '2026-02-01'],
                ['2026-02-01', '2026-03-01'],
                ['2026-03-01', '2026-03-21'],
            ],
            $windows->map(fn (ImapBackfillWindow $window): array => [
                $window->window_start->toDateString(),
                $window->window_end->toDateString(),
            ])->all(),
        );
        $this->assertSame([77], $windows->pluck('snapshot_uid_validity')->unique()->values()->all());
        $this->assertSame([20], $windows->pluck('snapshot_max_uid')->unique()->values()->all());
        $this->assertSame(2, $backfill->fresh()->total_messages);
        $this->assertSame(ImapBackfill::STATUS_RUNNING, $backfill->fresh()->status);
        $this->assertSame(500, $client->requestedLimit);
        $this->assertTrue($client->closed);
    }

    public function test_import_requests_only_batch_plus_one_and_advances_the_checkpoint(): void
    {
        Queue::fake();
        Storage::fake('local');
        $installation = $this->installation();
        $backfill = $this->backfill($installation, ImapBackfill::STATUS_RUNNING, [
            'batch_size' => 2,
            'total_messages' => 3,
            'total_windows' => 1,
            'settings_json' => ['skip_auto_generated' => false, 'attachments' => ['enabled' => false]],
        ]);
        $window = $this->window($installation, $backfill, [
            'status' => ImapBackfillWindow::STATUS_QUEUED,
            'snapshot_uid_validity' => 77,
            'snapshot_max_uid' => 103,
        ]);
        $client = new AlgorithmFakeImapClient;
        $client->state = new MailboxState(uidValidity: 77, lastUid: 103);
        $client->betweenUidValues = [101, 102, 103];
        $client->bulkMessages = [
            101 => $this->message(101, Carbon::parse('2026-01-10')),
            102 => $this->message(102, Carbon::parse('2026-01-11')),
        ];
        $ingestion = new RecordingConnectorIngestion;
        $importer = new ImapBackfillImporter($this->provider($client), $ingestion);

        (new ImportImapBackfillWindowJob($window->id, $this->tenantId()))->handle($importer);

        $freshWindow = $window->fresh();
        $this->assertSame(3, $client->requestedLimit, 'batch_size + 1 is the bounded hasMore probe');
        $this->assertSame(102, $freshWindow->last_uid);
        $this->assertSame(2, $freshWindow->processed_messages);
        $this->assertSame(ImapBackfillWindow::STATUS_PENDING, $freshWindow->status);
        $this->assertSame(2, $backfill->fresh()->processed_messages);
        $this->assertCount(2, $ingestion->dispatched);
        Queue::assertPushed(PumpImapBackfillJob::class, 1);
    }

    public function test_import_rejects_a_changed_uidvalidity_snapshot(): void
    {
        Storage::fake('local');
        $installation = $this->installation();
        $backfill = $this->backfill($installation, ImapBackfill::STATUS_RUNNING);
        $window = $this->window($installation, $backfill, [
            'snapshot_uid_validity' => 77,
            'snapshot_max_uid' => 10,
        ]);
        $client = new AlgorithmFakeImapClient;
        $client->state = new MailboxState(uidValidity: 88, lastUid: 10);
        $importer = new ImapBackfillImporter($this->provider($client), new RecordingConnectorIngestion);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('UIDVALIDITY changed');
        $importer->importBatch($installation, $backfill, $window);
    }

    public function test_import_rejects_a_gap_in_the_bulk_fetch_prefix(): void
    {
        Storage::fake('local');
        $installation = $this->installation();
        $backfill = $this->backfill($installation, ImapBackfill::STATUS_RUNNING, ['batch_size' => 2]);
        $window = $this->window($installation, $backfill, [
            'snapshot_uid_validity' => 77,
            'snapshot_max_uid' => 102,
        ]);
        $client = new AlgorithmFakeImapClient;
        $client->state = new MailboxState(uidValidity: 77, lastUid: 102);
        $client->betweenUidValues = [101, 102];
        $client->bulkMessages = [101 => $this->message(101, Carbon::parse('2026-01-10'))];
        $importer = new ImapBackfillImporter($this->provider($client), new RecordingConnectorIngestion);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('bulk fetch did not return UID 102');
        $importer->importBatch($installation, $backfill, $window);
    }

    public function test_import_keeps_slug_colliding_mailboxes_in_distinct_paths(): void
    {
        Storage::fake('local');
        $installation = $this->installation();
        $backfill = $this->backfill($installation, ImapBackfill::STATUS_RUNNING, [
            'batch_size' => 1,
            'settings_json' => ['skip_auto_generated' => false, 'attachments' => ['enabled' => false]],
        ]);
        $client = new AlgorithmFakeImapClient;
        $client->state = new MailboxState(uidValidity: 77, lastUid: 1);
        $client->betweenUidValues = [1];
        $ingestion = new RecordingConnectorIngestion;
        $importer = new ImapBackfillImporter($this->provider($client), $ingestion);

        foreach (['Support/EMEA', 'SupportEMEA'] as $mailbox) {
            $window = $this->window($installation, $backfill, [
                'mailbox' => $mailbox,
                'snapshot_uid_validity' => 77,
                'snapshot_max_uid' => 1,
            ]);
            $client->bulkMessages = [1 => $this->message(1, Carbon::parse('2026-01-10'), $mailbox)];
            $importer->importBatch($installation, $backfill, $window);
        }

        $paths = array_column($ingestion->dispatched, 'relativePath');
        $this->assertCount(2, array_unique($paths));
        $this->assertStringContainsString('/supportemea-', $paths[0]);
        $this->assertStringContainsString('/supportemea-', $paths[1]);
        $this->assertNotSame($paths[0], $paths[1]);
    }

    public function test_import_keeps_duplicate_attachment_filenames_in_distinct_paths(): void
    {
        Storage::fake('local');
        $installation = $this->installation();
        $backfill = $this->backfill($installation, ImapBackfill::STATUS_RUNNING, [
            'batch_size' => 1,
            'settings_json' => [
                'skip_auto_generated' => false,
                'attachments' => [
                    'enabled' => true,
                    'allowed_extensions' => ['pdf'],
                    'max_size_mb' => 1,
                    'max_per_email' => 20,
                    'skip_inline' => true,
                ],
            ],
        ]);
        $window = $this->window($installation, $backfill, [
            'snapshot_uid_validity' => 77,
            'snapshot_max_uid' => 1,
        ]);
        $client = new AlgorithmFakeImapClient;
        $client->state = new MailboxState(uidValidity: 77, lastUid: 1);
        $client->betweenUidValues = [1];
        $client->bulkMessages = [1 => $this->message(1, Carbon::parse('2026-01-10'), attachments: [
            new ImapAttachment('report.pdf', 'application/pdf', 5, false, 'first'),
            new ImapAttachment('report.pdf', 'application/pdf', 6, false, 'second'),
        ])];
        $ingestion = new RecordingConnectorIngestion;

        (new ImapBackfillImporter($this->provider($client), $ingestion))
            ->importBatch($installation, $backfill, $window);

        $paths = array_column($ingestion->dispatched, 'relativePath');
        $this->assertCount(3, $paths);
        $this->assertStringEndsWith('/01-report.pdf', $paths[1]);
        $this->assertStringEndsWith('/02-report.pdf', $paths[2]);
        $this->assertNotSame($paths[1], $paths[2]);
    }

    private function provider(AlgorithmFakeImapClient $client): ImapBackfillClientProviderContract
    {
        return new class($client) implements ImapBackfillClientProviderContract
        {
            public function __construct(private readonly AlgorithmFakeImapClient $client) {}

            public function forInstallation(ConnectorInstallation $installation): ImapBackfillClient
            {
                return $this->client;
            }
        };
    }

    /** @param array<string,mixed> $overrides */
    private function backfill(ConnectorInstallation $installation, string $status, array $overrides = []): ImapBackfill
    {
        return ImapBackfill::create(array_merge([
            'tenant_id' => $this->tenantId(),
            'connector_installation_id' => $installation->id,
            'status' => $status,
            'settings_json' => [],
            'batch_size' => 100,
            'cutoff_at' => now(),
        ], $overrides));
    }

    /** @param array<string,mixed> $overrides */
    private function window(
        ConnectorInstallation $installation,
        ImapBackfill $backfill,
        array $overrides = [],
    ): ImapBackfillWindow {
        return ImapBackfillWindow::create(array_merge([
            'tenant_id' => $this->tenantId(),
            'imap_backfill_id' => $backfill->id,
            'connector_installation_id' => $installation->id,
            'mailbox' => 'INBOX',
            'window_start' => '2026-01-01',
            'window_end' => '2026-02-01',
            'status' => ImapBackfillWindow::STATUS_PENDING,
        ], $overrides));
    }

    private function installation(): ConnectorInstallation
    {
        return ConnectorInstallation::create([
            'tenant_id' => $this->tenantId(),
            'connector_name' => 'imap',
            'label' => 'algorithm-test',
            'project_key' => 'default',
            'config_json' => [],
            'status' => ConnectorInstallation::STATUS_ACTIVE,
        ]);
    }

    /** @param list<ImapAttachment> $attachments */
    private function message(
        int $uid,
        Carbon $date,
        string $mailbox = 'INBOX',
        array $attachments = [],
    ): ImapMessage
    {
        return new ImapMessage(
            uid: $uid,
            uidValidity: 77,
            mailbox: $mailbox,
            messageId: "<{$uid}@example.test>",
            inReplyTo: null,
            references: [],
            fromName: 'Sender',
            fromEmail: 'sender@example.test',
            to: [['name' => 'Recipient', 'email' => 'recipient@example.test']],
            cc: [],
            date: $date,
            subject: "Message {$uid}",
            flags: [],
            labels: [],
            textBody: "Body {$uid}",
            htmlBody: null,
            rawHeaders: [],
            attachments: $attachments,
        );
    }

    private function tenantId(): string
    {
        return app(TenantContext::class)->current();
    }
}

final class AlgorithmFakeImapClient implements ImapBackfillClient
{
    /** @var list<string> */
    public array $mailboxNames = ['INBOX'];
    public MailboxState $state;
    public ImapBackfillMailboxSnapshot $snapshot;
    /** @var list<int> */
    public array $betweenUidValues = [];
    /** @var array<int,ImapMessage> */
    public array $singleMessages = [];
    /** @var array<int,ImapMessage> */
    public array $bulkMessages = [];
    public ?int $requestedLimit = null;
    public bool $closed = false;

    public function __construct()
    {
        $this->state = new MailboxState(uidValidity: 1, lastUid: 0);
        $this->snapshot = new ImapBackfillMailboxSnapshot(uidValidity: 1, maxUid: 0, messageCount: 0);
    }

    public function mailboxes(): array { return $this->mailboxNames; }
    public function selectMailbox(string $mailbox): MailboxState { return $this->state; }
    public function snapshotMailbox(string $mailbox): ImapBackfillMailboxSnapshot { return $this->snapshot; }

    public function uidsBetween(
        string $mailbox,
        Carbon $start,
        Carbon $end,
        int $afterUid = 0,
        ?int $throughUid = null,
        ?int $limit = null,
    ): array {
        $this->requestedLimit = $limit;
        return $limit === null ? $this->betweenUidValues : array_slice($this->betweenUidValues, 0, $limit);
    }

    public function fetchMessage(string $mailbox, int $uid): ImapMessage
    {
        return $this->singleMessages[$uid] ?? throw new RuntimeException("Missing fake UID {$uid}");
    }

    public function fetchMessages(string $mailbox, array $uids): array
    {
        return array_values(array_intersect_key($this->bulkMessages, array_flip($uids)));
    }

    public function close(): void { $this->closed = true; }
}

final class RecordingConnectorIngestion implements ConnectorIngestionContract
{
    /** @var list<array<string,mixed>> */
    public array $dispatched = [];

    public function dispatchIngestion(
        string $projectKey,
        string $relativePath,
        string $disk,
        string $title,
        array $metadata,
        string $mimeType,
        string $tenantId,
    ): void {
        $this->dispatched[] = compact('projectKey', 'relativePath', 'disk', 'title', 'metadata', 'mimeType', 'tenantId');
    }

    public function resolveKbSourcePath(string $relativePath): array
    {
        return ['relative' => $relativePath, 'absolute' => $relativePath, 'disk' => 'local'];
    }

    public function redactContent(string $content): string { return $content; }
    public function emitAudit(string $connectorKey, string $eventType, ?int $installationId = null, ?array $metadata = null): void {}
    public function softDeleteByRemoteId(ConnectorInstallation $installation, string $metadataKey, string $remoteId): bool { return false; }
}
