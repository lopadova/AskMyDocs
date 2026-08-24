<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Connectors\HostIngestionBridge;
use App\Jobs\IngestDocumentJob;
use App\Models\KbCanonicalAudit;
use App\Models\KnowledgeDocument;
use App\Services\Demo\EmailDataset\EmailDatasetReader;
use App\Services\Demo\EmailDataset\FixtureMetadataIndex;
use App\Services\Kb\DocumentDeleter;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Padosoft\AskMyDocsConnectorBase\Contracts\ConnectorIngestionContract;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * v4.6 — Coverage for the IoC bridge between standalone connector
 * packages and the AskMyDocs host (R30 tenant scoping, R26 PII
 * redaction opt-in, audit emission, soft-delete-by-remote-id).
 */
final class HostIngestionBridgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Most bridge tests isolate Message-ID parsing. The dedicated fixture
        // index integration test below enables the production-strict lookup.
        config()->set('connectors.case_study_email_dataset.require_fixture_index', false);
    }

    public function test_bridge_is_bound_as_singleton_via_contract(): void
    {
        $resolved = $this->app->make(ConnectorIngestionContract::class);

        $this->assertInstanceOf(HostIngestionBridge::class, $resolved);
    }

    public function test_dispatch_ingestion_queues_ingest_job_with_passed_tenant_id(): void
    {
        Queue::fake();

        /** @var HostIngestionBridge $bridge */
        $bridge = $this->app->make(ConnectorIngestionContract::class);

        $bridge->dispatchIngestion(
            projectKey: 'connector-notion',
            relativePath: 'notion/page-abc.md',
            disk: 'kb',
            title: 'Page ABC',
            metadata: ['notion_page_id' => 'abc-123'],
            mimeType: 'text/markdown',
            tenantId: 'acme',
        );

        Queue::assertPushed(IngestDocumentJob::class, function (IngestDocumentJob $job): bool {
            return $job->projectKey === 'connector-notion'
                && $job->relativePath === 'notion/page-abc.md'
                && $job->disk === 'kb'
                && $job->title === 'Page ABC'
                && $job->mimeType === 'text/markdown'
                && $job->tenantId === 'acme'
                && $job->metadata === ['notion_page_id' => 'abc-123'];
        });
    }

    public function test_dispatch_ingestion_derives_generated_fixture_metadata_from_reserved_message_id(): void
    {
        Queue::fake();

        /** @var HostIngestionBridge $bridge */
        $bridge = $this->app->make(ConnectorIngestionContract::class);

        $fixtureId = str_repeat('a', 64);

        $bridge->dispatchIngestion(
            projectKey: 'connector-email-rotta',
            relativePath: 'email/rotta/message.md',
            disk: 'kb',
            title: 'Generated fixture',
            metadata: [
                'imap_message_id' => "<large-v2.{$fixtureId}@fixtures.askmydocs.invalid>",
                'imap_mailbox' => 'INBOX',
                'generated_fixture' => false,
                'dataset_version' => 'spoofed-version',
                'fixture_id' => str_repeat('b', 64),
            ],
            mimeType: 'text/markdown',
            tenantId: 'rotta',
        );

        Queue::assertPushed(IngestDocumentJob::class, function (IngestDocumentJob $job) use ($fixtureId): bool {
            return $job->projectKey === 'connector-email-rotta'
                && $job->tenantId === 'rotta'
                && $job->metadata === [
                    'imap_message_id' => "<large-v2.{$fixtureId}@fixtures.askmydocs.invalid>",
                    'imap_mailbox' => 'INBOX',
                    'generated_fixture' => true,
                    'dataset_version' => 'large-v2',
                    'fixture_id' => $fixtureId,
                ];
        });
    }

    public function test_dispatch_ingestion_accepts_reserved_message_id_without_angle_brackets(): void
    {
        Queue::fake();

        /** @var HostIngestionBridge $bridge */
        $bridge = $this->app->make(ConnectorIngestionContract::class);

        $fixtureId = str_repeat('c', 64);

        $bridge->dispatchIngestion(
            projectKey: 'connector-email-prometeo',
            relativePath: 'email/prometeo/message.md',
            disk: 'kb',
            title: 'Generated fixture',
            metadata: [
                'imap_message_id' => "demo-v2.{$fixtureId}@fixtures.askmydocs.invalid",
                'imap_uid' => 42,
            ],
            mimeType: 'text/markdown',
            tenantId: 'prometeo',
        );

        Queue::assertPushed(IngestDocumentJob::class, function (IngestDocumentJob $job) use ($fixtureId): bool {
            return $job->metadata['generated_fixture'] === true
                && $job->metadata['dataset_version'] === 'demo-v2'
                && $job->metadata['fixture_id'] === $fixtureId
                && $job->metadata['imap_uid'] === 42;
        });
    }

    public function test_imap_dispatch_requires_a_persisted_source_before_queueing_or_acknowledging(): void
    {
        Queue::fake();
        Storage::fake('kb');
        config()->set('kb.sources.disk', 'kb');
        config()->set('kb.sources.path_prefix', '');
        config()->set('filesystems.disks.kb.throw', true);

        /** @var HostIngestionBridge $bridge */
        $bridge = $this->app->make(ConnectorIngestionContract::class);

        try {
            $bridge->dispatchIngestion(
                projectKey: 'connector-email',
                relativePath: 'connector-email/connectors/imap/installation-12/inbox/99.md',
                disk: 'kb',
                title: 'Missing source',
                metadata: [
                    'connector' => 'imap',
                    'installation_id' => 12,
                    'imap_uid' => '99',
                    'imap_doc_key' => 'INBOX:1:99',
                    'imap_mailbox' => 'INBOX',
                    'imap_message_id' => '<ordinary@example.test>',
                ],
                mimeType: 'text/markdown',
                tenantId: 'default',
            );
            $this->fail('A missing IMAP source must fail before dispatch.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('was not persisted', $exception->getMessage());
        }

        Queue::assertNothingPushed();
    }

    public function test_generated_imap_fixture_uses_a_stable_path_across_new_transport_uids(): void
    {
        Queue::fake();
        Storage::fake('kb');
        config()->set('kb.sources.disk', 'kb');
        config()->set('kb.sources.path_prefix', '');
        config()->set('filesystems.disks.kb.throw', true);

        /** @var HostIngestionBridge $bridge */
        $bridge = $this->app->make(ConnectorIngestionContract::class);

        $fixtureId = str_repeat('e', 64);
        $messageId = "<large-v2.{$fixtureId}@fixtures.askmydocs.invalid>";
        $first = 'connector-email/connectors/imap/installation-12/inbox/99.md';
        $second = 'connector-email/connectors/imap/installation-12/inbox/177.md';
        Storage::disk('kb')->put($first, '# fixture');
        Storage::disk('kb')->put($second, '# fixture');

        foreach ([[$first, '99'], [$second, '177']] as [$path, $uid]) {
            $bridge->dispatchIngestion(
                projectKey: 'connector-email',
                relativePath: $path,
                disk: 'kb',
                title: 'Generated fixture',
                metadata: [
                    'connector' => 'imap',
                    'installation_id' => 12,
                    'imap_uid' => $uid,
                    'imap_doc_key' => "INBOX:1:{$uid}",
                    'imap_mailbox' => 'INBOX',
                    'imap_message_id' => $messageId,
                ],
                mimeType: 'text/markdown',
                tenantId: 'default',
            );
        }

        $stable = 'connector-email/connectors/imap/installation-12/inbox'
            ."/datasets/large-v2/{$fixtureId}.md";
        Storage::disk('kb')->assertExists($stable);
        Storage::disk('kb')->assertMissing($first);
        Storage::disk('kb')->assertMissing($second);

        Queue::assertPushed(IngestDocumentJob::class, 2);
        Queue::assertPushed(IngestDocumentJob::class, function (IngestDocumentJob $job) use (
            $stable,
            $fixtureId,
        ): bool {
            return $job->relativePath === $stable
                && $job->metadata['generated_fixture'] === true
                && $job->metadata['fixture_id'] === $fixtureId
                && $job->metadata['imap_doc_key'] === "fixture:large-v2:{$fixtureId}"
                && str_starts_with(
                    (string) $job->metadata['imap_transport_doc_key'],
                    'INBOX:1:',
                );
        });
    }

    public function test_generated_imap_fixture_restores_the_exact_soft_deleted_projection(): void
    {
        Queue::fake();
        Storage::fake('kb');
        config()->set('kb.sources.disk', 'kb');
        config()->set('kb.sources.path_prefix', '');
        config()->set('filesystems.disks.kb.throw', true);

        $fixtureId = str_repeat('f', 64);
        $stable = 'connector-email/connectors/imap/installation-12/inbox'
            ."/datasets/large-v2/{$fixtureId}.md";
        $document = KnowledgeDocument::query()->create([
            'tenant_id' => 'default',
            'project_key' => 'connector-email',
            'source_type' => 'markdown',
            'title' => 'Rolled back fixture',
            'source_path' => $stable,
            'mime_type' => 'text/markdown',
            'language' => 'it',
            'access_scope' => 'internal',
            'status' => 'active',
            'document_hash' => hash('sha256', '# fixture'),
            'version_hash' => hash('sha256', '# fixture'),
            'metadata' => [
                'generated_fixture' => true,
                'dataset_version' => 'large-v2',
                'fixture_id' => $fixtureId,
            ],
            'indexed_at' => now(),
        ]);
        $document->delete();
        $this->assertTrue($document->trashed());

        $transient = 'connector-email/connectors/imap/installation-12/inbox/244.md';
        Storage::disk('kb')->put($transient, '# fixture');

        /** @var HostIngestionBridge $bridge */
        $bridge = $this->app->make(ConnectorIngestionContract::class);
        $bridge->dispatchIngestion(
            projectKey: 'connector-email',
            relativePath: $transient,
            disk: 'kb',
            title: 'Generated fixture',
            metadata: [
                'connector' => 'imap',
                'installation_id' => 12,
                'imap_uid' => '244',
                'imap_doc_key' => 'INBOX:1:244',
                'imap_mailbox' => 'INBOX',
                'imap_message_id' => "<large-v2.{$fixtureId}@fixtures.askmydocs.invalid>",
            ],
            mimeType: 'text/markdown',
            tenantId: 'default',
        );

        $this->assertFalse(
            KnowledgeDocument::withTrashed()->findOrFail($document->id)->trashed(),
        );
        Queue::assertPushed(IngestDocumentJob::class);
    }

    public function test_generated_imap_fixture_propagates_sharded_index_metadata_to_the_job(): void
    {
        Queue::fake();
        Storage::fake('kb');
        config()->set('kb.sources.disk', 'kb');
        config()->set('kb.sources.path_prefix', '');
        config()->set('filesystems.disks.kb.throw', true);

        $root = sys_get_temp_dir().'/askmydocs-host-fixture-index-'.bin2hex(random_bytes(8));
        try {
            $this->artisan('demo:generate-case-study-emails', [
                '--profile' => 'gold',
                '--seed' => '818',
                '--mailbox' => ['rotta-logistics-1'],
                '--output' => $root,
            ])->assertSuccessful();

            $directories = glob($root.'/*', GLOB_ONLYDIR);
            $this->assertIsArray($directories);
            $this->assertCount(1, $directories);
            $datasetDirectory = $directories[0];
            $datasetVersion = basename($datasetDirectory);

            /** @var EmailDatasetReader $reader */
            $reader = $this->app->make(EmailDatasetReader::class);
            $record = null;
            foreach ($reader->recordsForMailbox($datasetDirectory, 'rotta-logistics-1') as $candidate) {
                $candidateBody = str_replace(
                    ["\r\n", "\r"],
                    "\n",
                    (string) $candidate['body_text'],
                );
                if (! str_contains($candidateBody, "\n")) {
                    continue;
                }

                $record = $candidate;
                break;
            }
            $this->assertIsArray($record);

            config()->set('connectors.case_study_email_dataset.root', $root);
            config()->set('connectors.case_study_email_dataset.require_fixture_index', true);

            $transient = 'rotta-logistics/connectors/imap/installation-21/inbox/313.md';
            $transportBody = str_replace(
                "\n",
                "\r\n",
                str_replace(
                    ["\r\n", "\r"],
                    "\n",
                    trim((string) $record['body_text']),
                ),
            );
            $this->assertStringContainsString("\r\n", $transportBody);
            $committedMarkdown = '# '.$record['subject']
                ."\n\n---\n\n".$transportBody."\n";
            Storage::disk('kb')->put($transient, $committedMarkdown);

            /** @var HostIngestionBridge $bridge */
            $bridge = $this->app->make(ConnectorIngestionContract::class);
            $bridge->dispatchIngestion(
                projectKey: 'rotta-logistics',
                relativePath: $transient,
                disk: 'kb',
                title: (string) $record['subject'],
                metadata: [
                    'connector' => 'imap',
                    'installation_id' => 21,
                    'imap_uid' => '313',
                    'imap_doc_key' => 'rotta-logistics-1:1:313',
                    'imap_mailbox' => 'rotta-logistics-1',
                    'imap_message_id' => (string) $record['message_id'],
                ],
                mimeType: 'text/markdown',
                tenantId: 'rotta-logistics',
            );

            Queue::assertPushed(
                IngestDocumentJob::class,
                function (IngestDocumentJob $job) use ($record, $datasetVersion): bool {
                    foreach ([
                        'company_key',
                        'mailbox_key',
                        'scenario_type',
                        'topic',
                        'message_type',
                        'thread_id',
                        'fact_ids',
                        'canonical_sources',
                        'truth_state',
                        'canary_ids',
                    ] as $field) {
                        if (($job->metadata[$field] ?? null) !== $record[$field]) {
                            return false;
                        }
                    }

                    return $job->metadata['dataset_version'] === $datasetVersion
                        && $job->metadata['fixture_id'] === $record['fixture_id']
                        && $job->metadata['content_sha256'] === FixtureMetadataIndex::contentChecksum(
                            (string) $record['subject'],
                            (string) $record['body_text'],
                        );
                },
            );

            $tampered = 'rotta-logistics/connectors/imap/installation-21/inbox/314.md';
            Storage::disk('kb')->put(
                $tampered,
                '# '.$record['subject']."\n\n---\n\ncontenuto alterato\n",
            );
            try {
                $bridge->dispatchIngestion(
                    projectKey: 'rotta-logistics',
                    relativePath: $tampered,
                    disk: 'kb',
                    title: (string) $record['subject'],
                    metadata: [
                        'connector' => 'imap',
                        'installation_id' => 21,
                        'imap_uid' => '314',
                        'imap_doc_key' => 'rotta-logistics-1:1:314',
                        'imap_mailbox' => 'rotta-logistics-1',
                        'imap_message_id' => (string) $record['message_id'],
                    ],
                    mimeType: 'text/markdown',
                    tenantId: 'rotta-logistics',
                );
                $this->fail('Tampered bytes with a committed fixture Message-ID were accepted.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('content does not match', $exception->getMessage());
            }

            $stable = 'rotta-logistics/connectors/imap/installation-21/rotta-logistics-1'
                ."/datasets/{$datasetVersion}/{$record['fixture_id']}.md";
            Storage::disk('kb')->assertExists($stable);
            $this->assertSame($committedMarkdown, Storage::disk('kb')->get($stable));
            Queue::assertPushed(IngestDocumentJob::class, 1);
        } finally {
            if (is_dir($root)) {
                $this->assertTrue((new Filesystem)->deleteDirectory($root));
            }
        }
    }

    public function test_imap_dispatch_rejects_a_non_strict_kb_disk(): void
    {
        Queue::fake();
        Storage::fake('kb');
        config()->set('kb.sources.disk', 'kb');
        config()->set('kb.sources.path_prefix', '');
        config()->set('filesystems.disks.kb.throw', false);
        Storage::disk('kb')->put('imap/message.md', '# persisted but non-strict');

        /** @var HostIngestionBridge $bridge */
        $bridge = $this->app->make(ConnectorIngestionContract::class);

        try {
            $bridge->dispatchIngestion(
                projectKey: 'connector-email',
                relativePath: 'imap/message.md',
                disk: 'kb',
                title: 'Unsafe storage contract',
                metadata: [
                    'connector' => 'imap',
                    'installation_id' => 1,
                    'imap_mailbox' => 'INBOX',
                    'imap_message_id' => '<ordinary@example.test>',
                ],
                mimeType: 'text/markdown',
                tenantId: 'default',
            );
            $this->fail('A non-strict KB disk must be rejected for IMAP ingestion.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('throw=true', $exception->getMessage());
        }

        Queue::assertNothingPushed();
    }

    #[DataProvider('untrustedFixtureMessageIdProvider')]
    public function test_dispatch_ingestion_does_not_trust_invalid_message_ids_or_spoofed_metadata(mixed $messageId): void
    {
        Queue::fake();

        /** @var HostIngestionBridge $bridge */
        $bridge = $this->app->make(ConnectorIngestionContract::class);

        $metadata = [
            'imap_message_id' => $messageId,
            'imap_mailbox' => 'INBOX',
            'generated_fixture' => true,
            'dataset_version' => 'spoofed',
            'fixture_id' => str_repeat('d', 64),
        ];

        $bridge->dispatchIngestion(
            projectKey: 'connector-email',
            relativePath: 'email/ordinary.md',
            disk: 'kb',
            title: 'Ordinary message',
            metadata: $metadata,
            mimeType: 'text/markdown',
            tenantId: 'acme',
        );

        Queue::assertPushed(IngestDocumentJob::class, function (IngestDocumentJob $job) use ($messageId): bool {
            return $job->metadata['imap_message_id'] === $messageId
                && $job->metadata['imap_mailbox'] === 'INBOX'
                && ! array_key_exists('generated_fixture', $job->metadata)
                && ! array_key_exists('dataset_version', $job->metadata)
                && ! array_key_exists('fixture_id', $job->metadata);
        });
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function untrustedFixtureMessageIdProvider(): array
    {
        $fixtureId = str_repeat('a', 64);

        return [
            'ordinary provider message id' => ['message@example.com'],
            'custom header-like value' => ["large-v2.{$fixtureId}@example.com"],
            'uppercase dataset version' => ["Large-v2.{$fixtureId}@fixtures.askmydocs.invalid"],
            'uppercase fixture hex' => ['large-v2.'.str_repeat('A', 64).'@fixtures.askmydocs.invalid'],
            'fixture id too short' => ['large-v2.'.str_repeat('a', 63).'@fixtures.askmydocs.invalid'],
            'fixture id too long' => ['large-v2.'.str_repeat('a', 65).'@fixtures.askmydocs.invalid'],
            'missing closing bracket' => ["<large-v2.{$fixtureId}@fixtures.askmydocs.invalid"],
            'missing opening bracket' => ["large-v2.{$fixtureId}@fixtures.askmydocs.invalid>"],
            'trailing content' => ["large-v2.{$fixtureId}@fixtures.askmydocs.invalid.evil"],
            'non string' => [42],
            'missing message id' => [null],
        ];
    }

    public function test_resolve_kb_source_path_normalises_and_applies_prefix(): void
    {
        config()->set('kb.sources.disk', 'kb');
        config()->set('kb.sources.path_prefix', 'docs');

        /** @var HostIngestionBridge $bridge */
        $bridge = $this->app->make(ConnectorIngestionContract::class);

        $result = $bridge->resolveKbSourcePath('//notion//page.md');

        $this->assertSame([
            'relative' => 'notion/page.md',
            'absolute' => 'docs/notion/page.md',
            'disk' => 'kb',
        ], $result);
    }

    public function test_resolve_kb_source_path_rejects_traversal(): void
    {
        /** @var HostIngestionBridge $bridge */
        $bridge = $this->app->make(ConnectorIngestionContract::class);

        $this->expectException(\InvalidArgumentException::class);

        $bridge->resolveKbSourcePath('../outside/kb.md');
    }

    public function test_redact_content_is_no_op_when_disabled(): void
    {
        config()->set('kb.pii_redactor.enabled', false);
        config()->set('kb.pii_redactor.redact_before_ingest', true);

        /** @var HostIngestionBridge $bridge */
        $bridge = $this->app->make(ConnectorIngestionContract::class);

        $this->assertSame(
            'My email is user@example.com',
            $bridge->redactContent('My email is user@example.com'),
        );
    }

    public function test_redact_content_is_no_op_when_per_boundary_flag_off(): void
    {
        config()->set('kb.pii_redactor.enabled', true);
        config()->set('kb.pii_redactor.redact_before_ingest', false);

        /** @var HostIngestionBridge $bridge */
        $bridge = $this->app->make(ConnectorIngestionContract::class);

        $this->assertSame(
            'My email is user@example.com',
            $bridge->redactContent('My email is user@example.com'),
        );
    }

    public function test_redact_content_masks_by_default(): void
    {
        // R43 — default ingest_strategy is the pre-v8.23 one-way mask.
        config()->set('pii-redactor.enabled', true); // package engine (RedactorEngine no-ops when off)
        config()->set('kb.pii_redactor.enabled', true);
        config()->set('kb.pii_redactor.redact_before_ingest', true);

        /** @var HostIngestionBridge $bridge */
        $bridge = $this->app->make(ConnectorIngestionContract::class);
        $out = $bridge->redactContent('My email is user@example.com');

        $this->assertStringNotContainsString('user@example.com', $out);
        $this->assertStringNotContainsString('[tok:', $out, 'default strategy must mask, not tokenise');
    }

    public function test_redact_content_tokenises_reversibly_when_configured(): void
    {
        // v8.23 — tokenise puts a reversible surrogate in the content while the
        // original lives in the per-tenant vault (recoverable on demand).
        config()->set('pii-redactor.enabled', true);
        config()->set('kb.pii_redactor.enabled', true);
        config()->set('kb.pii_redactor.redact_before_ingest', true);
        config()->set('kb.pii_redactor.ingest_strategy', 'tokenise');
        config()->set('pii-redactor.salt', 'test-salt');

        /** @var HostIngestionBridge $bridge */
        $bridge = $this->app->make(ConnectorIngestionContract::class);
        $out = $bridge->redactContent('My email is user@example.com');

        $this->assertStringNotContainsString('user@example.com', $out);
        $this->assertMatchesRegularExpression('/\[tok:email:[0-9a-f]+\]/', $out);

        // The original is recoverable from the shared (singleton) vault.
        $restored = $this->app
            ->make(\Padosoft\PiiRedactor\Strategies\RedactionStrategyFactory::class)
            ->make('tokenise')
            ->detokeniseString($out);
        $this->assertStringContainsString('user@example.com', $restored);
    }

    public function test_tokenise_is_tenant_isolated(): void
    {
        // Core v8.23 contract: the same PII yields a DIFFERENT token per tenant,
        // and a token minted under tenant A cannot be detokenised under tenant B
        // (the TenantResolver binding + per-tenant vault, R30).
        config()->set('pii-redactor.enabled', true);
        config()->set('kb.pii_redactor.enabled', true);
        config()->set('kb.pii_redactor.redact_before_ingest', true);
        config()->set('kb.pii_redactor.ingest_strategy', 'tokenise');
        config()->set('pii-redactor.salt', 'test-salt');

        /** @var TenantContext $ctx */
        $ctx = $this->app->make(TenantContext::class);
        /** @var HostIngestionBridge $bridge */
        $bridge = $this->app->make(ConnectorIngestionContract::class);

        $ctx->set('tenant-a');
        $outA = $bridge->redactContent('My email is user@example.com');

        $ctx->set('tenant-b');
        $outB = $bridge->redactContent('My email is user@example.com');

        // Same PII → different token per tenant (no cross-tenant correlation).
        $this->assertNotSame($outA, $outB);

        // Under tenant B, tenant A's token does NOT resolve — stays tokenised.
        $factory = $this->app->make(\Padosoft\PiiRedactor\Strategies\RedactionStrategyFactory::class);
        $underB = $factory->make('tokenise')->detokeniseString($outA);
        $this->assertStringNotContainsString('user@example.com', $underB);

        // Back under tenant A, it resolves.
        $ctx->set('tenant-a');
        $underA = $factory->make('tokenise')->detokeniseString($outA);
        $this->assertStringContainsString('user@example.com', $underA);
    }

    public function test_redact_content_is_no_op_and_does_not_throw_when_package_engine_off(): void
    {
        // Host boundary flags ON but the package engine OFF: redaction is a
        // no-op, so even a typo'd strategy must NOT throw (the strict-strategy
        // guard is reserved for when redaction actually runs).
        config()->set('pii-redactor.enabled', false);
        config()->set('kb.pii_redactor.enabled', true);
        config()->set('kb.pii_redactor.redact_before_ingest', true);
        config()->set('kb.pii_redactor.ingest_strategy', 'tokenize'); // typo, but engine off

        /** @var HostIngestionBridge $bridge */
        $bridge = $this->app->make(ConnectorIngestionContract::class);

        $this->assertSame(
            'My email is user@example.com',
            $bridge->redactContent('My email is user@example.com'),
        );
    }

    public function test_redact_content_throws_on_unknown_ingest_strategy(): void
    {
        // R14 — unknown strategy value must throw, never silently degrade to mask.
        config()->set('pii-redactor.enabled', true); // engine ON → redaction active → strict strategy applies
        config()->set('kb.pii_redactor.enabled', true);
        config()->set('kb.pii_redactor.redact_before_ingest', true);
        config()->set('kb.pii_redactor.ingest_strategy', 'tokenize'); // common typo — missing trailing 's'

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/tokenize/');

        /** @var HostIngestionBridge $bridge */
        $bridge = $this->app->make(ConnectorIngestionContract::class);
        $bridge->redactContent('My email is user@example.com');
    }

    public function test_emit_audit_writes_to_canonical_audit_with_namespaced_event(): void
    {
        /** @var TenantContext $ctx */
        $ctx = $this->app->make(TenantContext::class);
        $ctx->set('acme');

        /** @var HostIngestionBridge $bridge */
        $bridge = $this->app->make(ConnectorIngestionContract::class);

        $bridge->emitAudit(
            connectorKey: 'notion',
            eventType: 'sync_completed',
            installationId: 42,
            metadata: ['pages' => 7],
        );

        $audit = KbCanonicalAudit::query()
            ->where('event_type', 'connector_sync_completed')
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame('acme', $audit->tenant_id);
        $this->assertSame('connector:notion', $audit->actor);
        $this->assertSame('notion', $audit->metadata_json['connector_key']);
        $this->assertSame(42, $audit->metadata_json['installation_id']);
        $this->assertSame(['pages' => 7], $audit->metadata_json['metadata']);
    }

    public function test_emit_audit_does_not_double_namespace_when_caller_passes_namespaced_event(): void
    {
        /** @var HostIngestionBridge $bridge */
        $bridge = $this->app->make(ConnectorIngestionContract::class);

        $bridge->emitAudit(
            connectorKey: 'google-drive',
            eventType: 'connector_installed',
            installationId: 7,
            metadata: null,
        );

        $this->assertTrue(
            KbCanonicalAudit::query()
                ->where('event_type', 'connector_installed')
                ->exists(),
        );
        $this->assertFalse(
            KbCanonicalAudit::query()
                ->where('event_type', 'connector_connector_installed')
                ->exists(),
        );
    }

    public function test_soft_delete_by_remote_id_routes_through_document_deleter(): void
    {
        /** @var TenantContext $ctx */
        $ctx = $this->app->make(TenantContext::class);
        $ctx->set('acme');

        $installation = ConnectorInstallation::create([
            'tenant_id' => 'acme',
            'connector_name' => 'notion',
            'status' => ConnectorInstallation::STATUS_ACTIVE,
            'config_json' => [],
        ]);

        $doc = KnowledgeDocument::create([
            'tenant_id' => 'acme',
            'project_key' => 'connector-notion',
            'source_type' => 'markdown',
            'title' => 'Page A',
            'source_path' => 'notion/page-a.md',
            'language' => 'en',
            'access_scope' => 'internal',
            'status' => 'active',
            'document_hash' => str_repeat('a', 64),
            'version_hash' => str_repeat('a', 64),
            'is_canonical' => false,
            'metadata' => ['notion_page_id' => 'page-a-uuid'],
        ]);

        /** @var HostIngestionBridge $bridge */
        $bridge = $this->app->make(ConnectorIngestionContract::class);

        $acted = $bridge->softDeleteByRemoteId(
            installation: $installation,
            metadataKey: 'notion_page_id',
            remoteId: 'page-a-uuid',
        );

        $this->assertTrue($acted);
        $this->assertSoftDeleted($doc->fresh());
    }

    public function test_soft_delete_by_remote_id_returns_false_when_no_match(): void
    {
        $installation = ConnectorInstallation::create([
            'tenant_id' => 'acme',
            'connector_name' => 'notion',
            'status' => ConnectorInstallation::STATUS_ACTIVE,
            'config_json' => [],
        ]);

        /** @var HostIngestionBridge $bridge */
        $bridge = $this->app->make(ConnectorIngestionContract::class);

        $acted = $bridge->softDeleteByRemoteId(
            installation: $installation,
            metadataKey: 'notion_page_id',
            remoteId: 'no-such-uuid',
        );

        $this->assertFalse($acted);
    }

    public function test_soft_delete_by_remote_id_is_tenant_scoped(): void
    {
        // Tenant A owns the installation.
        $installation = ConnectorInstallation::create([
            'tenant_id' => 'tenant-a',
            'connector_name' => 'notion',
            'status' => ConnectorInstallation::STATUS_ACTIVE,
            'config_json' => [],
        ]);

        // Tenant B happens to own a document with the SAME remote-id.
        // (Two different Notion workspaces COULD legitimately collide on
        // a page-id if Notion ever recycled UUIDs — and even if it never
        // does, the tenant boundary is the only safe scope per R30.)
        $tenantBDoc = KnowledgeDocument::create([
            'tenant_id' => 'tenant-b',
            'project_key' => 'connector-notion',
            'source_type' => 'markdown',
            'title' => 'Tenant B page',
            'source_path' => 'notion/page-shared.md',
            'language' => 'en',
            'access_scope' => 'internal',
            'status' => 'active',
            'document_hash' => str_repeat('b', 64),
            'version_hash' => str_repeat('b', 64),
            'is_canonical' => false,
            'metadata' => ['notion_page_id' => 'shared-uuid'],
        ]);

        /** @var HostIngestionBridge $bridge */
        $bridge = $this->app->make(ConnectorIngestionContract::class);

        $acted = $bridge->softDeleteByRemoteId(
            installation: $installation,
            metadataKey: 'notion_page_id',
            remoteId: 'shared-uuid',
        );

        $this->assertFalse(
            $acted,
            'softDeleteByRemoteId must NEVER cross tenant boundaries (R30).',
        );

        // Tenant B's doc must remain untouched.
        $this->assertNotSoftDeleted('knowledge_documents', [
            'id' => $tenantBDoc->id,
            'tenant_id' => 'tenant-b',
        ]);
    }

    public function test_soft_delete_by_remote_id_is_idempotent_on_already_trashed_rows(): void
    {
        $installation = ConnectorInstallation::create([
            'tenant_id' => 'acme',
            'connector_name' => 'notion',
            'status' => ConnectorInstallation::STATUS_ACTIVE,
            'config_json' => [],
        ]);

        $doc = KnowledgeDocument::create([
            'tenant_id' => 'acme',
            'project_key' => 'connector-notion',
            'source_type' => 'markdown',
            'title' => 'Already trashed',
            'source_path' => 'notion/trashed.md',
            'language' => 'en',
            'access_scope' => 'internal',
            'status' => 'active',
            'document_hash' => str_repeat('c', 64),
            'version_hash' => str_repeat('c', 64),
            'is_canonical' => false,
            'metadata' => ['notion_page_id' => 'trashed-uuid'],
        ]);

        // Pre-soft-delete the row to simulate a prior sweep that
        // already acted on it.
        (new DocumentDeleter)->delete($doc, force: false);
        $doc->refresh();

        /** @var HostIngestionBridge $bridge */
        $bridge = $this->app->make(ConnectorIngestionContract::class);

        $acted = $bridge->softDeleteByRemoteId(
            installation: $installation,
            metadataKey: 'notion_page_id',
            remoteId: 'trashed-uuid',
        );

        // Idempotent: no work performed on already-trashed rows.
        $this->assertFalse($acted);
    }
}
