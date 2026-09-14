<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\Versioning;

use App\Ai\EmbeddingsResponse;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Services\Kb\DocumentIngestor;
use App\Services\Kb\EmbeddingCacheService;
use App\Services\Kb\Pipeline\SourceDocument;
use App\Services\Kb\Versioning\ArtifactPublishFailedException;
use App\Services\Kb\Versioning\ConversionArtifactStore;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\Fixtures\Pdf\PdfFixtureBuilder;
use Tests\TestCase;

/**
 * v8.36 / ADR 0030 §2-§4 — the artifact write on the one core both ingest
 * paths share: flag OFF and ON (R43), the retention modes, the path
 * contract (tenant/project safe segments), temp-then-move, the failure
 * branch, and the server-derived version actor.
 */
final class ConversionArtifactsIngestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->reset();
        Storage::fake('kb');
        config(['kb.sources.disk' => 'kb', 'kb.sources.path_prefix' => '', 'kb.source_retention.mode' => 'full_copy']);

        $cache = Mockery::mock(EmbeddingCacheService::class);
        $cache->shouldReceive('generate')->andReturnUsing(
            fn (array $texts) => new EmbeddingsResponse(
                embeddings: array_map(fn () => array_fill(0, 8, 0.0), $texts),
                provider: 'fake',
                model: 'fake-8',
            ),
        );
        $this->app->instance(EmbeddingCacheService::class, $cache);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }

    private function ingestMarkdown(string $markdown, string $path = 'docs/note.md', array $metadata = [], string $project = 'eng'): KnowledgeDocument
    {
        return app(DocumentIngestor::class)->ingestMarkdown($project, $path, 'Note', $markdown, $metadata);
    }

    public function test_off_no_artifact_is_written_and_the_columns_default(): void
    {
        config(['kb.conversion_artifacts.enabled' => false]);

        $doc = $this->ingestMarkdown("# Off\n\nBody.");

        $this->assertNull($doc->markdown_path);
        $this->assertNull($doc->content_hash);
        $this->assertSame('system:ingest', $doc->version_actor);
        $this->assertSame([], Storage::disk('kb')->allFiles('.artifacts'));
    }

    public function test_on_full_copy_stores_the_exact_markdown_at_the_tenant_project_path(): void
    {
        config(['kb.conversion_artifacts.enabled' => true]);
        $markdown = "# On\n\nThe exact string the chunker received.";

        $doc = $this->ingestMarkdown($markdown);

        $tenant = app(TenantContext::class)->current();
        $expected = '.artifacts/'.$tenant.'/eng/docs/note.md.versions/'.hash('sha256', $markdown).'.md';
        $this->assertSame($expected, $doc->markdown_path);
        $this->assertSame(hash('sha256', $markdown), $doc->content_hash);
        $this->assertSame($doc->document_hash, $doc->content_hash);
        $this->assertSame($markdown, Storage::disk('kb')->get($expected));
        // temp-then-move: no temp left behind
        $this->assertSame([$expected], Storage::disk('kb')->allFiles('.artifacts'));
    }

    public function test_on_the_flow_persist_path_stores_the_artifact_too(): void
    {
        config(['kb.conversion_artifacts.enabled' => true]);
        $markdown = "# Flow\n\nPersisted through persistDrafts().";

        $doc = app(DocumentIngestor::class)->persistDrafts(
            projectKey: 'eng',
            sourcePath: 'docs/flow.md',
            title: 'Flow',
            mimeType: 'text/markdown',
            sourceType: 'markdown',
            markdown: $markdown,
            chunkDrafts: [new \App\Services\Kb\Pipeline\ChunkDraft(text: 'Persisted through persistDrafts().', order: 0, headingPath: 'Flow', metadata: [])],
            metadata: ['disk' => 'kb', 'prefix' => ''],
            embeddingResponse: new EmbeddingsResponse(embeddings: [array_fill(0, 8, 0.0)], provider: 'fake', model: 'fake-8'),
            canonical: null,
        );

        $this->assertNotNull($doc->markdown_path);
        $this->assertSame($markdown, Storage::disk('kb')->get((string) $doc->markdown_path));
    }

    public function test_reference_only_retention_stores_nothing_even_with_the_flag_on(): void
    {
        config(['kb.conversion_artifacts.enabled' => true, 'kb.source_retention.mode' => 'reference_only']);

        $doc = $this->ingestMarkdown("# Ref\n\nBody.");

        $this->assertNull($doc->markdown_path);
        $this->assertNull($doc->content_hash);
        $this->assertSame([], Storage::disk('kb')->allFiles('.artifacts'));
    }

    /** ADR 0030 §3 — a `reference_only` sibling stored nothing that could stand in for the shared original: it blocks a later `markdown_only` version's drop. */
    public function test_a_reference_only_sibling_blocks_the_markdown_only_drop_of_the_shared_original(): void
    {
        config(['kb.conversion_artifacts.enabled' => true, 'kb.source_retention.mode' => 'reference_only']);
        $bytes = PdfFixtureBuilder::buildThreePageSample();
        Storage::disk('kb')->put('reports/shared.pdf', $bytes);
        $first = app(DocumentIngestor::class)->ingest('eng', new SourceDocument(
            sourcePath: 'reports/shared.pdf', mimeType: 'application/pdf', bytes: $bytes,
            externalUrl: null, externalId: null, connectorType: 'local', metadata: ['disk' => 'kb', 'prefix' => ''],
        ), 'Shared v1');
        $this->assertNull($first->markdown_path);
        $this->assertSame('reference_only', $first->fresh()->metadata['source_retention']);

        // The knob moves; a NEW version of the same source (different bytes) is ingested under markdown_only …
        config(['kb.source_retention.mode' => 'markdown_only']);
        $revised = PdfFixtureBuilder::buildSinglePage('A revised second version of the shared source.');
        Storage::disk('kb')->put('reports/shared.pdf', $revised);
        $second = app(DocumentIngestor::class)->ingest('eng', new SourceDocument(
            sourcePath: 'reports/shared.pdf', mimeType: 'application/pdf', bytes: $revised,
            externalUrl: null, externalId: null, connectorType: 'local', metadata: ['disk' => 'kb', 'prefix' => ''],
        ), 'Shared v2');
        $this->assertNotSame($first->id, $second->id, 'a new version');
        $this->assertNotNull($second->markdown_path);
        $this->assertSame('markdown_only', $second->fresh()->metadata['source_retention']);

        // … and the shared original stays: the reference_only version has nothing else to be re-run from.
        Storage::disk('kb')->assertExists('reports/shared.pdf');
        $this->assertArrayNotHasKey('source_dropped', $second->fresh()->metadata);
    }

    public function test_markdown_only_retention_drops_the_original_binary_after_the_artifact_commit(): void
    {
        config(['kb.conversion_artifacts.enabled' => true, 'kb.source_retention.mode' => 'markdown_only']);
        $bytes = PdfFixtureBuilder::buildThreePageSample();
        Storage::disk('kb')->put('reports/q1.pdf', $bytes);

        $doc = app(DocumentIngestor::class)->ingest('eng', new SourceDocument(
            sourcePath: 'reports/q1.pdf',
            mimeType: 'application/pdf',
            bytes: $bytes,
            externalUrl: null,
            externalId: null,
            connectorType: 'local',
            metadata: ['disk' => 'kb', 'prefix' => ''],
        ), 'Q1');

        $this->assertNotNull($doc->markdown_path);
        Storage::disk('kb')->assertExists((string) $doc->markdown_path);
        Storage::disk('kb')->assertMissing('reports/q1.pdf');
    }

    /**
     * ADR 0030 §3 — the original goes only once every referencing row's
     * artifact is PRESENT on disk: a pointer whose file never landed (a
     * publish that failed after commit) does not stand in for it.
     */
    public function test_markdown_only_keeps_the_original_while_a_referencing_row_points_at_a_missing_artifact(): void
    {
        config(['kb.conversion_artifacts.enabled' => true, 'kb.source_retention.mode' => 'markdown_only']);
        $bytes = PdfFixtureBuilder::buildThreePageSample();
        Storage::disk('kb')->put('reports/q2.pdf', $bytes);
        // An archived sibling whose artifact pointer is dangling.
        KnowledgeDocument::create([
            'project_key' => 'eng', 'source_type' => 'pdf', 'title' => 'Q2 old', 'source_path' => 'reports/q2.pdf',
            'mime_type' => 'application/pdf', 'language' => 'en', 'access_scope' => 'internal', 'status' => 'archived',
            'document_hash' => hash('sha256', 'old'), 'version_hash' => hash('sha256', 'old'),
            'metadata' => ['disk' => 'kb', 'prefix' => ''], 'indexed_at' => now(),
            'markdown_path' => '.artifacts/x/eng/reports/q2.pdf.versions/deadbeef.md',
        ]);

        $doc = app(DocumentIngestor::class)->ingest('eng', new SourceDocument(
            sourcePath: 'reports/q2.pdf', mimeType: 'application/pdf', bytes: $bytes,
            externalUrl: null, externalId: null, connectorType: 'local', metadata: ['disk' => 'kb', 'prefix' => ''],
        ), 'Q2');

        Storage::disk('kb')->assertExists((string) $doc->markdown_path);
        Storage::disk('kb')->assertExists('reports/q2.pdf'); // kept: the sibling's artifact is not on disk
        $this->assertArrayNotHasKey('source_dropped', $doc->fresh()->metadata ?? []);
    }

    /**
     * ADR 0030 §3 — a shared original is dropped only if EVERY referencing
     * row was ingested under a mode that does not require it: a full_copy
     * row (or a pre-stamp row, which counts as full_copy) blocks the drop
     * even when its artifact is present; markdown_only siblings with an
     * artifact on disk do not.
     */
    public function test_markdown_only_never_drops_an_original_a_full_copy_row_still_requires(): void
    {
        config(['kb.conversion_artifacts.enabled' => true, 'kb.source_retention.mode' => 'full_copy']);
        $bytes = PdfFixtureBuilder::buildThreePageSample();
        Storage::disk('kb')->put('reports/q3.pdf', $bytes);
        $source = fn (): SourceDocument => new SourceDocument(
            sourcePath: 'reports/q3.pdf', mimeType: 'application/pdf', bytes: $bytes,
            externalUrl: null, externalId: null, connectorType: 'local', metadata: ['disk' => 'kb', 'prefix' => ''],
        );
        $full = app(DocumentIngestor::class)->ingest('eng', $source(), 'Q3');
        $this->assertSame('full_copy', $full->metadata['source_retention']);
        Storage::disk('kb')->assertExists((string) $full->markdown_path);

        // Same bytes re-ingested under markdown_only by another tenant sharing the key.
        config(['kb.source_retention.mode' => 'markdown_only']);
        $tenants = app(TenantContext::class);
        $home = $tenants->current();
        $tenants->set('other-tenant');
        try {
            $theirs = app(DocumentIngestor::class)->ingest('eng', $source(), 'Q3');
        } finally {
            $tenants->set($home);
        }
        $this->assertSame('markdown_only', $theirs->metadata['source_retention']);
        Storage::disk('kb')->assertExists('reports/q3.pdf'); // the full_copy row still requires it

        // Once the full_copy row is gone, the markdown_only rows let it go.
        $full->forceDelete();
        $tenants->set('third-tenant');
        try {
            app(DocumentIngestor::class)->ingest('eng', $source(), 'Q3');
        } finally {
            $tenants->set($home);
        }
        Storage::disk('kb')->assertMissing('reports/q3.pdf');
    }

    /**
     * ADR 0030 §3 — the drop is gated on the ROW's persisted contract, never
     * on the configured mode of the day: a `full_copy` version re-embedded
     * (a trusted replay carrying its own metadata, as ReembedDocumentJob
     * does) after `KB_SOURCE_RETENTION` moved to `markdown_only` keeps its
     * original; only a version BORN under `markdown_only` lets it go.
     */
    public function test_markdown_only_drop_is_gated_on_the_rows_persisted_contract_not_the_configured_mode(): void
    {
        config(['kb.conversion_artifacts.enabled' => true, 'kb.source_retention.mode' => 'full_copy']);
        $bytes = PdfFixtureBuilder::buildThreePageSample();
        Storage::disk('kb')->put('reports/q5.pdf', $bytes);
        $source = fn (array $metadata): SourceDocument => new SourceDocument(
            sourcePath: 'reports/q5.pdf', mimeType: 'application/pdf', bytes: $bytes,
            externalUrl: null, externalId: null, connectorType: 'local', metadata: $metadata,
        );
        $full = app(DocumentIngestor::class)->ingest('eng', $source(['disk' => 'kb', 'prefix' => '']), 'Q5');
        $this->assertSame('full_copy', $full->metadata['source_retention']);

        config(['kb.source_retention.mode' => 'markdown_only']);
        app(DocumentIngestor::class)->ingest('eng', $source($full->fresh()->metadata), 'Q5', forceReembed: true);

        Storage::disk('kb')->assertExists('reports/q5.pdf');
        $this->assertSame('full_copy', $full->fresh()->metadata['source_retention']);

        // A version born under markdown_only (a different path, nothing else references it) drops its original.
        Storage::disk('kb')->put('reports/q6.pdf', $bytes);
        $born = app(DocumentIngestor::class)->ingest('eng', new SourceDocument(
            sourcePath: 'reports/q6.pdf', mimeType: 'application/pdf', bytes: $bytes,
            externalUrl: null, externalId: null, connectorType: 'local', metadata: ['disk' => 'kb', 'prefix' => ''],
        ), 'Q6');
        $this->assertSame('markdown_only', $born->metadata['source_retention']);
        Storage::disk('kb')->assertMissing('reports/q6.pdf');
    }

    /**
     * A row that predates the stamp counts as `full_copy` when it is REPLACED
     * (a trusted replay such as ReembedDocumentJob carries its stamp-less
     * metadata): a replay after the knob moved to `markdown_only` never drops
     * the original the row was ingested to keep, and stamps it `full_copy`.
     */
    public function test_a_replay_of_a_pre_stamp_row_keeps_its_original_and_stamps_it_full_copy(): void
    {
        config(['kb.conversion_artifacts.enabled' => true, 'kb.source_retention.mode' => 'full_copy']);
        $bytes = PdfFixtureBuilder::buildThreePageSample();
        Storage::disk('kb')->put('reports/q7.pdf', $bytes);
        $source = fn (array $metadata): SourceDocument => new SourceDocument(
            sourcePath: 'reports/q7.pdf', mimeType: 'application/pdf', bytes: $bytes,
            externalUrl: null, externalId: null, connectorType: 'local', metadata: $metadata,
        );
        $legacy = app(DocumentIngestor::class)->ingest('eng', $source(['disk' => 'kb', 'prefix' => '']), 'Q7');
        $legacy->update(['metadata' => ['disk' => 'kb', 'prefix' => '']]); // pre-v8.36: no stamp

        config(['kb.source_retention.mode' => 'markdown_only']);
        app(DocumentIngestor::class)->ingest('eng', $source($legacy->fresh()->metadata), 'Q7', forceReembed: true);

        Storage::disk('kb')->assertExists('reports/q7.pdf');
        $this->assertSame('full_copy', $legacy->fresh()->metadata['source_retention']);
    }

    /** R43 — with the artifacts flag OFF the retention knob is inert, and the row is stamped with what actually happened: `full_copy`. */
    public function test_off_the_row_is_stamped_full_copy_whatever_the_retention_knob_says(): void
    {
        config(['kb.conversion_artifacts.enabled' => false, 'kb.source_retention.mode' => 'reference_only']);

        $doc = $this->ingestMarkdown("# Off

Inert knob.", 'docs/inert.md');

        $this->assertSame('full_copy', $doc->fresh()->metadata['source_retention']);
        $this->assertNull($doc->markdown_path);
    }

    /**
     * ADR 0030 §3 — a sibling's artifact stands in for the original only when
     * its bytes VERIFY (hash to the row's `content_hash`): a corrupt sibling
     * artifact blocks the drop, so the last valid representation is never
     * deleted.
     */
    public function test_markdown_only_keeps_the_original_while_a_referencing_rows_artifact_is_corrupt(): void
    {
        config(['kb.conversion_artifacts.enabled' => true, 'kb.source_retention.mode' => 'markdown_only']);
        $bytes = PdfFixtureBuilder::buildThreePageSample();
        Storage::disk('kb')->put('reports/q8.pdf', $bytes);
        $store = app(ConversionArtifactStore::class);
        $tenant = app(TenantContext::class)->current();
        $siblingPath = $store->pathFor($tenant, 'eng', 'reports/q8.pdf', str_repeat('a', 64));
        Storage::disk('kb')->put($siblingPath, 'not the recorded bytes');
        KnowledgeDocument::create([
            'project_key' => 'eng', 'source_type' => 'pdf', 'title' => 'Q8 old', 'source_path' => 'reports/q8.pdf',
            'mime_type' => 'application/pdf', 'language' => 'en', 'access_scope' => 'internal', 'status' => 'archived',
            'document_hash' => str_repeat('a', 64), 'version_hash' => str_repeat('a', 64), 'content_hash' => str_repeat('a', 64),
            'metadata' => ['disk' => 'kb', 'prefix' => '', 'source_retention' => 'markdown_only'], 'indexed_at' => now(),
            'markdown_path' => $siblingPath,
        ]);

        $doc = app(DocumentIngestor::class)->ingest('eng', new SourceDocument(
            sourcePath: 'reports/q8.pdf', mimeType: 'application/pdf', bytes: $bytes,
            externalUrl: null, externalId: null, connectorType: 'local', metadata: ['disk' => 'kb', 'prefix' => ''],
        ), 'Q8');

        Storage::disk('kb')->assertExists((string) $doc->markdown_path);
        Storage::disk('kb')->assertExists('reports/q8.pdf'); // kept: the sibling's artifact does not verify
    }

    /** A legacy pointer without `content_hash` becomes `verified` on the next identical re-ingest: the hash is recorded once the bytes are known to be the version's. */
    public function test_an_identical_re_ingest_records_the_missing_content_hash_of_a_legacy_pointer(): void
    {
        config(['kb.conversion_artifacts.enabled' => true]);
        $markdown = "# Legacy pointer\n\nStored before the hash was recorded.";
        $doc = $this->ingestMarkdown($markdown, 'docs/legacy-pointer.md');
        $doc->update(['content_hash' => null]);

        $again = $this->ingestMarkdown($markdown, 'docs/legacy-pointer.md');

        $this->assertSame($doc->id, $again->id);
        $this->assertSame(hash('sha256', $markdown), $doc->fresh()->content_hash);
    }

    /**
     * ADR 0030 §3 / R21 — the `markdown_only` drop and the row commits of the
     * same storage key share one lock. A single process cannot interleave two
     * real transactions on SQLite, so the PERSIST side is exercised against a
     * lock HELD by "someone else": it fails loudly instead of committing past
     * a drop in progress, and commits (then drops) once the key is free. The
     * drop side is exercised by the test right after this one.
     */
    public function test_a_persist_under_a_held_storage_key_lock_fails_loudly_and_commits_once_the_key_is_free(): void
    {
        config(['kb.conversion_artifacts.enabled' => true, 'kb.source_retention.mode' => 'markdown_only', 'kb.conversion_artifacts.source_lock_wait_seconds' => 0]);
        $bytes = PdfFixtureBuilder::buildThreePageSample();
        Storage::disk('kb')->put('reports/q9.pdf', $bytes);
        $held = \Illuminate\Support\Facades\Cache::lock('kb:source:kb:'.sha1('reports/q9.pdf'), 60);
        $this->assertTrue($held->get());
        try {
            // The persist itself must not wait on the lock: with wait 0 it fails loudly …
            try {
                app(DocumentIngestor::class)->ingest('eng', new SourceDocument(
                    sourcePath: 'reports/q9.pdf', mimeType: 'application/pdf', bytes: $bytes,
                    externalUrl: null, externalId: null, connectorType: 'local', metadata: ['disk' => 'kb', 'prefix' => ''],
                ), 'Q9');
                $this->fail('a persist under a held storage-key lock must fail loudly');
            } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
                // expected: the temp is discarded, nothing committed
            }
            $this->assertSame(0, KnowledgeDocument::withoutGlobalScopes()->where('source_path', 'reports/q9.pdf')->count());
            $this->assertSame([], array_filter(Storage::disk('kb')->allFiles('.artifacts'), static fn (string $f): bool => str_ends_with($f, '.tmp')), 'no temp left behind');
        } finally {
            $held->release();
        }

        // … and once the key is free the same ingest commits and drops the original.
        $doc = app(DocumentIngestor::class)->ingest('eng', new SourceDocument(
            sourcePath: 'reports/q9.pdf', mimeType: 'application/pdf', bytes: $bytes,
            externalUrl: null, externalId: null, connectorType: 'local', metadata: ['disk' => 'kb', 'prefix' => ''],
        ), 'Q9');
        Storage::disk('kb')->assertExists((string) $doc->markdown_path);
        Storage::disk('kb')->assertMissing('reports/q9.pdf');
    }

    /**
     * ADR 0030 §3 / R21 — the DROP side against a lock held by someone else:
     * the row commit (first take of the key) goes through, the drop (second
     * take) cannot get the key and keeps the original — the conservative
     * direction — while the version and its artifact are committed as usual.
     * The persist has already released its lock when the drop asks, so the
     * "held" lock is injected on the second take of the same key only.
     */
    public function test_markdown_only_drop_keeps_the_original_when_the_storage_key_is_locked_by_a_concurrent_writer(): void
    {
        config(['kb.conversion_artifacts.enabled' => true, 'kb.source_retention.mode' => 'markdown_only', 'kb.conversion_artifacts.source_lock_wait_seconds' => 0]);
        $bytes = PdfFixtureBuilder::buildThreePageSample();
        Storage::disk('kb')->put('reports/q11.pdf', $bytes);
        $key = 'kb:source:kb:'.sha1('reports/q11.pdf');
        $store = \Illuminate\Support\Facades\Cache::store();
        $takes = 0;
        $heldByAnotherWriter = new class implements \Illuminate\Contracts\Cache\Lock
        {
            public function get($callback = null)
            {
                return false;
            }

            public function block($seconds, $callback = null)
            {
                throw new \Illuminate\Contracts\Cache\LockTimeoutException;
            }

            public function release()
            {
                return false;
            }

            public function owner()
            {
                return 'another-writer';
            }

            public function forceRelease()
            {
            }
        };
        \Illuminate\Support\Facades\Cache::partialMock()
            ->shouldReceive('lock')
            ->andReturnUsing(function (string $name, int $seconds = 0, $owner = null) use ($store, $key, &$takes, $heldByAnotherWriter) {
                if ($name !== $key) {
                    return $store->lock($name, $seconds, $owner);
                }
                $takes++;

                return $takes === 1 ? $store->lock($name, $seconds, $owner) : $heldByAnotherWriter;
            });

        $doc = app(DocumentIngestor::class)->ingest('eng', new SourceDocument(
            sourcePath: 'reports/q11.pdf', mimeType: 'application/pdf', bytes: $bytes,
            externalUrl: null, externalId: null, connectorType: 'local', metadata: ['disk' => 'kb', 'prefix' => ''],
        ), 'Q11');

        $this->assertSame(2, $takes, 'the persist takes the key once, the drop asks for it once more');
        $this->assertSame(1, KnowledgeDocument::withoutGlobalScopes()->where('source_path', 'reports/q11.pdf')->count(), 'the version is committed');
        Storage::disk('kb')->assertExists((string) $doc->markdown_path);
        Storage::disk('kb')->assertExists('reports/q11.pdf'); // a drop that cannot take the key keeps the original
        $this->assertSame('markdown_only', $doc->fresh()->metadata['source_retention'], 'the contract is recorded; the next identical ingest retries the drop');
    }

    /**
     * A storage-key lock whose TTL lapsed while its holder still ran: the
     * store now reports another owner. `acquire()` succeeds (the holder took
     * it), the owner comparison fails (someone else has it now).
     */
    private function lapsedStorageKeyLock(string $name, int $seconds): \Illuminate\Cache\Lock
    {
        return new class($name, $seconds) extends \Illuminate\Cache\Lock
        {
            public function acquire()
            {
                return true;
            }

            public function release()
            {
                return true;
            }

            public function forceRelease()
            {
            }

            protected function getCurrentOwner()
            {
                return 'another-writer';
            }
        };
    }

    /**
     * ADR 0030 §3 — the row commit asserts, inside the transaction, that the
     * storage-key lock is still its own: a lock that lapsed mid-commit rolls
     * the row back (LockLostException, the job retries) instead of landing a
     * row between a concurrent `markdown_only` drop's scan and its delete.
     * Nothing is committed, the temp is discarded, the original is untouched.
     */
    public function test_a_row_commit_whose_storage_key_lock_lapsed_rolls_back_and_leaves_nothing(): void
    {
        config(['kb.conversion_artifacts.enabled' => true, 'kb.source_retention.mode' => 'markdown_only', 'kb.conversion_artifacts.source_lock_wait_seconds' => 0]);
        $bytes = PdfFixtureBuilder::buildThreePageSample();
        Storage::disk('kb')->put('reports/q15.pdf', $bytes);
        $key = 'kb:source:kb:'.sha1('reports/q15.pdf');
        $store = \Illuminate\Support\Facades\Cache::store();
        \Illuminate\Support\Facades\Cache::partialMock()
            ->shouldReceive('lock')
            ->andReturnUsing(fn (string $name, int $seconds = 0, $owner = null) => $name === $key
                ? $this->lapsedStorageKeyLock($name, $seconds)
                : $store->lock($name, $seconds, $owner));

        $thrown = null;
        try {
            app(DocumentIngestor::class)->ingest('eng', new SourceDocument(
                sourcePath: 'reports/q15.pdf', mimeType: 'application/pdf', bytes: $bytes,
                externalUrl: null, externalId: null, connectorType: 'local', metadata: ['disk' => 'kb', 'prefix' => ''],
            ), 'Q15');
        } catch (\App\Support\Kb\LockLostException $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(\App\Support\Kb\LockLostException::class, $thrown, 'a commit past a lapsed storage-key lock must be refused');
        $this->assertStringContainsString('row commit', $thrown->getMessage());
        $this->assertSame(0, KnowledgeDocument::withoutGlobalScopes()->where('source_path', 'reports/q15.pdf')->count(), 'the transaction rolled back');
        $this->assertSame(0, KnowledgeChunk::withoutGlobalScopes()->where('project_key', 'eng')->count());
        $this->assertSame([], Storage::disk('kb')->allFiles('.artifacts'), 'the temp is discarded, nothing published');
        Storage::disk('kb')->assertExists('reports/q15.pdf');
    }

    /**
     * ADR 0030 §3 — the DROP asserts the storage-key lock right before the
     * delete: a lock that lapsed during the reference scan refuses the drop —
     * the original stays (the conservative direction), the version and its
     * artifact are committed as usual, and the refusal is logged. The persist
     * (first take of the key) is real; the lapsed lock is injected on the
     * drop's take only.
     */
    public function test_markdown_only_drop_keeps_the_original_when_the_storage_key_lock_lapses_during_the_scan(): void
    {
        config(['kb.conversion_artifacts.enabled' => true, 'kb.source_retention.mode' => 'markdown_only', 'kb.conversion_artifacts.source_lock_wait_seconds' => 0]);
        $bytes = PdfFixtureBuilder::buildThreePageSample();
        Storage::disk('kb')->put('reports/q16.pdf', $bytes);
        $key = 'kb:source:kb:'.sha1('reports/q16.pdf');
        $store = \Illuminate\Support\Facades\Cache::store();
        $takes = 0;
        \Illuminate\Support\Facades\Cache::partialMock()
            ->shouldReceive('lock')
            ->andReturnUsing(function (string $name, int $seconds = 0, $owner = null) use ($store, $key, &$takes) {
                if ($name !== $key) {
                    return $store->lock($name, $seconds, $owner);
                }
                $takes++;

                return $takes === 1 ? $store->lock($name, $seconds, $owner) : $this->lapsedStorageKeyLock($name, $seconds);
            });
        \Illuminate\Support\Facades\Log::spy();

        $doc = app(DocumentIngestor::class)->ingest('eng', new SourceDocument(
            sourcePath: 'reports/q16.pdf', mimeType: 'application/pdf', bytes: $bytes,
            externalUrl: null, externalId: null, connectorType: 'local', metadata: ['disk' => 'kb', 'prefix' => ''],
        ), 'Q16');

        $this->assertSame(2, $takes, 'the persist takes the key once, the drop asks for it once more');
        $this->assertSame(1, KnowledgeDocument::withoutGlobalScopes()->where('source_path', 'reports/q16.pdf')->count(), 'the version is committed');
        Storage::disk('kb')->assertExists((string) $doc->markdown_path);
        Storage::disk('kb')->assertExists('reports/q16.pdf'); // a drop whose lock lapsed keeps the original
        $this->assertSame('markdown_only', $doc->fresh()->metadata['source_retention'], 'the contract is recorded; the next identical ingest retries the drop');
        $this->assertArrayNotHasKey('source_dropped', $doc->fresh()->metadata ?? []);
        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->once()->withArgs(static fn (string $message): bool => str_contains($message, 'the storage key lock lapsed during the reference scan'));
    }

    /** ADR 0030 §3 — the retention contract is finalized on the identical re-ingest too: an original re-uploaded after a `markdown_only` drop is dropped again and the rows stamped. */
    public function test_an_identical_re_ingest_after_a_re_upload_drops_the_original_again(): void
    {
        config(['kb.conversion_artifacts.enabled' => true, 'kb.source_retention.mode' => 'markdown_only']);
        $bytes = PdfFixtureBuilder::buildThreePageSample();
        Storage::disk('kb')->put('reports/q13.pdf', $bytes);
        $source = new SourceDocument(
            sourcePath: 'reports/q13.pdf', mimeType: 'application/pdf', bytes: $bytes,
            externalUrl: null, externalId: null, connectorType: 'local', metadata: ['disk' => 'kb', 'prefix' => ''],
        );
        $doc = app(DocumentIngestor::class)->ingest('eng', $source, 'Q13');
        Storage::disk('kb')->assertMissing('reports/q13.pdf');
        $this->assertTrue($doc->fresh()->metadata['source_dropped']);

        // The operator re-uploads the same binary (a sync, a copy back) …
        Storage::disk('kb')->put('reports/q13.pdf', $bytes);
        KnowledgeDocument::withoutGlobalScopes()->whereKey($doc->id)->update(['metadata' => array_diff_key($doc->fresh()->metadata, ['source_dropped' => true])]);

        // … and the identical re-ingest (same version, artifact verified) applies the row's contract again.
        $again = app(DocumentIngestor::class)->ingest('eng', $source, 'Q13');

        $this->assertSame($doc->id, $again->id);
        Storage::disk('kb')->assertMissing('reports/q13.pdf');
        $this->assertTrue($again->fresh()->metadata['source_dropped']);
        Storage::disk('kb')->assertExists((string) $again->markdown_path);
    }

    /** R30 — an integrity write that bypasses the tenant scope is still bound to the row's own tenant: a row that is no longer the one read is not written. */
    public function test_unscoped_integrity_writes_carry_the_rows_own_tenant(): void
    {
        config(['kb.conversion_artifacts.enabled' => true]);
        $doc = $this->ingestMarkdown("# Tenant\n\nBound.", 'docs/tenant-bound.md');
        $this->assertSame(1, $doc->updateUnscopedWithinOwnTenant(['content_hash' => str_repeat('a', 64)]));
        $this->assertSame(str_repeat('a', 64), $doc->fresh()->content_hash);

        \Illuminate\Support\Facades\DB::table('knowledge_documents')->where('id', $doc->id)->update(['tenant_id' => 'someone-else']);

        $this->assertSame(0, $doc->updateUnscopedWithinOwnTenant(['content_hash' => str_repeat('b', 64)]), 'the write is bound to the tenant the row was read under');
        $this->assertSame(str_repeat('a', 64), KnowledgeDocument::withoutGlobalScopes()->whereKey($doc->id)->value('content_hash'));
    }

    /** A failed pointerless publish leaves the pointer as the repairable `missing` state (never rolled back), THROWS so the job retries, and the next identical re-ingest repairs it. */
    public function test_a_failed_pointerless_publish_keeps_the_pointer_as_missing_throws_and_the_next_re_ingest_repairs_it(): void
    {
        config(['kb.conversion_artifacts.enabled' => false]);
        $markdown = "# Pointerless\n\nRepaired forward.";
        $doc = $this->ingestMarkdown($markdown, 'docs/pointerless-missing.md');
        $this->assertNull($doc->markdown_path);
        config(['kb.conversion_artifacts.enabled' => true]);
        $healthy = Storage::disk('kb');
        $root = $healthy->path('');
        $adapter = new \Tests\Fixtures\Storage\WriteRefusingAdapter(new \League\Flysystem\Local\LocalFilesystemAdapter($root), static fn (string $path): bool => str_ends_with($path, '.tmp'));
        Storage::set('kb', new \Illuminate\Filesystem\FilesystemAdapter(new \League\Flysystem\Filesystem($adapter), $adapter, ['root' => $root]));

        $thrown = null;
        try {
            $this->ingestMarkdown($markdown, 'docs/pointerless-missing.md');
        } catch (ArtifactPublishFailedException $e) {
            $thrown = $e;
        }
        $this->assertInstanceOf(ArtifactPublishFailedException::class, $thrown, 'a refused publish is a failed ingest, never a silently degraded version (R14)');
        $this->assertSame((int) $doc->id, $thrown->documentId);

        $again = $doc->fresh();
        $pointer = (string) $again->markdown_path;
        $this->assertNotSame('', $pointer, 'the pointer stays: the version\'s bytes, not there yet');
        $this->assertSame($thrown->markdownPath, $pointer);
        $this->assertSame(hash('sha256', $markdown), $again->content_hash);
        $this->assertSame('missing', app(\App\Services\Kb\Versioning\DocumentVersionService::class)->artifactStateFor($again));
        $this->assertSame(1, KnowledgeDocument::withoutGlobalScopes()->where('source_path', 'docs/pointerless-missing.md')->count(), 'no second version: the row was already there');

        Storage::set('kb', $healthy);
        $repaired = $this->ingestMarkdown($markdown, 'docs/pointerless-missing.md');
        $this->assertSame($doc->id, $repaired->id);
        Storage::disk('kb')->assertExists($pointer);
        $this->assertSame('verified', app(\App\Services\Kb\Versioning\DocumentVersionService::class)->artifactStateFor($repaired->fresh()));
    }

    /** Two identical re-ingests can repair the same pointerless version at once: the pointer an attempt keeps on failure resolves to the other worker's verified publication. */
    public function test_a_failed_pointerless_publish_keeps_the_pointer_and_a_concurrent_repairs_verified_artifact_stands(): void
    {
        config(['kb.conversion_artifacts.enabled' => true]);
        $markdown = "# Pointerless\n\nRepaired by two workers.";
        $doc = $this->ingestMarkdown($markdown, 'docs/pointerless-race.md');
        $final = (string) $doc->markdown_path;
        Storage::disk('kb')->assertExists($final);
        // The row predates the artifacts (no pointer) while the other worker's publication is already on disk …
        KnowledgeDocument::withoutGlobalScopes()->whereKey($doc->id)->update(['markdown_path' => null, 'content_hash' => null]);
        // … and THIS worker's temp write is refused (a full disk, a lost mount); everything already there still reads.
        $root = Storage::disk('kb')->path('');
        $adapter = new \Tests\Fixtures\Storage\WriteRefusingAdapter(new \League\Flysystem\Local\LocalFilesystemAdapter($root), static fn (string $path): bool => str_ends_with($path, '.tmp'));
        Storage::set('kb', new \Illuminate\Filesystem\FilesystemAdapter(new \League\Flysystem\Filesystem($adapter), $adapter, ['root' => $root]));

        $again = $this->ingestMarkdown($markdown, 'docs/pointerless-race.md');

        $this->assertSame($doc->id, $again->id);
        $this->assertSame($final, $again->fresh()->markdown_path, 'the pointer stays on the verified artifact');
        $this->assertSame(hash('sha256', $markdown), $again->fresh()->content_hash);
        Storage::disk('kb')->assertExists($final);
    }

    /**
     * A fresh ingest whose post-commit publish fails (the move is refused)
     * keeps the committed row and its pointer (state `missing`, never
     * rolled back) and THROWS `ArtifactPublishFailedException`, so the
     * ingest job's `$tries` retry — an identical re-ingest — takes the
     * repair path and publishes once the disk is back. The attempt's temp
     * is discarded; nothing of it is left for the sweep.
     */
    public function test_a_fresh_ingest_whose_publish_fails_after_commit_throws_keeps_the_pointer_and_the_retry_repairs_it(): void
    {
        config(['kb.conversion_artifacts.enabled' => true]);
        $markdown = "# Refused move\n\nRetry repairs.";
        $healthy = Storage::disk('kb');
        $root = $healthy->path('');
        // The temp lands (the write is before the transaction); the MOVE onto the final `.md` is refused.
        $adapter = new \Tests\Fixtures\Storage\WriteRefusingAdapter(new \League\Flysystem\Local\LocalFilesystemAdapter($root), static fn (string $path): bool => str_contains($path, '.versions/') && str_ends_with($path, '.md'));
        Storage::set('kb', new \Illuminate\Filesystem\FilesystemAdapter(new \League\Flysystem\Filesystem($adapter), $adapter, ['root' => $root]));

        $thrown = null;
        try {
            $this->ingestMarkdown($markdown, 'docs/refused-move.md');
        } catch (ArtifactPublishFailedException $e) {
            $thrown = $e;
        }
        $this->assertInstanceOf(ArtifactPublishFailedException::class, $thrown);

        $doc = KnowledgeDocument::withoutGlobalScopes()->where('source_path', 'docs/refused-move.md')->first();
        $this->assertNotNull($doc, 'the row committed before the publish and stays');
        $this->assertSame((int) $doc->id, $thrown->documentId);
        $this->assertSame('active', $doc->status);
        $this->assertSame($thrown->markdownPath, (string) $doc->markdown_path);
        $this->assertSame(hash('sha256', $markdown), $doc->content_hash);
        $this->assertSame('missing', app(\App\Services\Kb\Versioning\DocumentVersionService::class)->artifactStateFor($doc));
        $this->assertSame([], array_values(array_filter(Storage::disk('kb')->allFiles(), static fn (string $f): bool => str_ends_with($f, ConversionArtifactStore::TMP_SUFFIX))), 'the failed attempt discards its temp');

        Storage::set('kb', $healthy);
        $repaired = $this->ingestMarkdown($markdown, 'docs/refused-move.md');
        $this->assertSame((int) $doc->id, (int) $repaired->id, 'the retry is an identical re-ingest: same version, no new row');
        Storage::disk('kb')->assertExists((string) $doc->markdown_path);
        $this->assertSame('verified', app(\App\Services\Kb\Versioning\DocumentVersionService::class)->artifactStateFor($repaired->fresh()));
    }

    /** A canonical version whose artifact publish fails still gets its graph projection: the indexer is dispatched before the publish, so the throw withholds nothing from a row that exists. */
    public function test_a_canonical_version_is_indexed_even_when_its_artifact_publish_fails(): void
    {
        config(['kb.conversion_artifacts.enabled' => true]);
        \Illuminate\Support\Facades\Queue::fake();
        $markdown = <<<'MD'
---
id: DEC-2026-0777
slug: dec-artifact-refused
type: decision
status: accepted
---

# Refused artifact

Still a decision.
MD;
        $healthy = Storage::disk('kb');
        $root = $healthy->path('');
        $adapter = new \Tests\Fixtures\Storage\WriteRefusingAdapter(new \League\Flysystem\Local\LocalFilesystemAdapter($root), static fn (string $path): bool => str_contains($path, '.versions/') && str_ends_with($path, '.md'));
        Storage::set('kb', new \Illuminate\Filesystem\FilesystemAdapter(new \League\Flysystem\Filesystem($adapter), $adapter, ['root' => $root]));

        try {
            $this->ingestMarkdown($markdown, 'decisions/dec-artifact-refused.md');
            $this->fail('the refused publish must throw');
        } catch (ArtifactPublishFailedException) {
            // expected
        } finally {
            Storage::set('kb', $healthy);
        }

        $doc = KnowledgeDocument::withoutGlobalScopes()->where('source_path', 'decisions/dec-artifact-refused.md')->first();
        $this->assertNotNull($doc);
        $this->assertTrue((bool) $doc->is_canonical);
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\CanonicalIndexerJob::class, static fn (\App\Jobs\CanonicalIndexerJob $job): bool => (int) $job->documentId === (int) $doc->id);
    }

    /** A canonical indexer dispatch that throws (the queue connection down) propagates, keeps the committed row, and discards this attempt's staged temp — nothing leased is left for the sweep. */
    public function test_a_failing_indexer_dispatch_propagates_and_discards_the_staged_temp(): void
    {
        config(['kb.conversion_artifacts.enabled' => true]);
        \Illuminate\Support\Facades\Queue::shouldReceive('connection')->andThrow(new \RuntimeException('queue connection down'));
        $markdown = <<<'MD'
---
id: DEC-2026-0779
slug: dec-indexer-down
type: decision
status: accepted
---

# Indexer down

Still a decision.
MD;

        try {
            $this->ingestMarkdown($markdown, 'decisions/dec-indexer-down.md');
            $this->fail('the failing dispatch must propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('queue connection down', $e->getMessage());
        }

        $doc = KnowledgeDocument::withoutGlobalScopes()->where('source_path', 'decisions/dec-indexer-down.md')->first();
        $this->assertNotNull($doc, 'the row committed before the dispatch and stays');
        $this->assertNotNull($doc->markdown_path, 'the pointer is kept (state: missing) — repaired forward');
        $temps = array_values(array_filter(Storage::disk('kb')->allFiles(), static fn (string $f): bool => str_ends_with($f, ConversionArtifactStore::TMP_SUFFIX)));
        $this->assertSame([], $temps, 'the staged temp of the failed attempt is discarded');
        $this->assertFalse(Storage::disk('kb')->exists((string) $doc->markdown_path), 'nothing was published');
    }

    /**
     * A forced re-embed (ReembedDocumentJob) whose artifact publish is
     * refused is DONE — row, chunks and embeddings are committed — so the
     * job logs the missing artifact instead of failing: a retry would not
     * take the same-hash repair path (`forceReembed` replaces the chunk set
     * again) and would end in `failed_jobs` reporting a failure that is not one.
     */
    public function test_a_forced_reembed_whose_publish_fails_completes_and_logs_the_missing_artifact(): void
    {
        config(['kb.conversion_artifacts.enabled' => true]);
        $markdown = "# Reembed\n\nRefused publish, done anyway.";
        Storage::disk('kb')->put('docs/reembed-refused.md', $markdown);
        $doc = $this->ingestMarkdown($markdown, 'docs/reembed-refused.md', ['disk' => 'kb', 'prefix' => '']);
        $pointer = (string) $doc->markdown_path;
        Storage::disk('kb')->delete($pointer); // so the re-embed's publish has to move, and the move is refused
        $healthy = Storage::disk('kb');
        $root = $healthy->path('');
        $adapter = new \Tests\Fixtures\Storage\WriteRefusingAdapter(new \League\Flysystem\Local\LocalFilesystemAdapter($root), static fn (string $path): bool => str_contains($path, '.versions/') && str_ends_with($path, '.md'));
        Storage::set('kb', new \Illuminate\Filesystem\FilesystemAdapter(new \League\Flysystem\Filesystem($adapter), $adapter, ['root' => $root]));
        \Illuminate\Support\Facades\Log::spy();

        try {
            (new \App\Jobs\ReembedDocumentJob((int) $doc->id, app(TenantContext::class)->current()))->handle(app(TenantContext::class), app(DocumentIngestor::class));
        } finally {
            Storage::set('kb', $healthy);
        }

        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->once()->withArgs(static fn (string $message): bool => str_contains($message, 're-embedded but its conversion artifact could not be published'));
        $fresh = $doc->fresh();
        $this->assertSame('active', $fresh->status);
        $this->assertSame($pointer, (string) $fresh->markdown_path, 'the pointer is kept');
        $this->assertGreaterThan(0, $fresh->chunks()->count(), 'the re-embed is done');
        $this->assertSame('missing', app(\App\Services\Kb\Versioning\DocumentVersionService::class)->artifactStateFor($fresh));
    }

    /** A source that reads as zero bytes is a missing source to the re-embed: the stored artifact is re-chunked, never an empty replay that replaces the valid chunks. */
    public function test_a_forced_reembed_treats_a_zero_byte_source_as_missing_and_re_chunks_the_artifact(): void
    {
        config(['kb.conversion_artifacts.enabled' => true]);
        $markdown = "# Zero bytes\n\nThe artifact stands in.";
        Storage::disk('kb')->put('docs/zero.md', $markdown);
        $doc = $this->ingestMarkdown($markdown, 'docs/zero.md', ['disk' => 'kb', 'prefix' => '']);
        $chunksBefore = $doc->chunks()->count();
        $this->assertGreaterThan(0, $chunksBefore);
        // Discriminator (R16): with the chunk set gone, only the artifact
        // branch restores it — the "source missing → skip" branch would leave zero.
        $doc->chunks()->delete();
        Storage::disk('kb')->put('docs/zero.md', ''); // the source went zero bytes (a truncated object)

        (new \App\Jobs\ReembedDocumentJob((int) $doc->id, app(TenantContext::class)->current()))->handle(app(TenantContext::class), app(DocumentIngestor::class));

        $fresh = $doc->fresh();
        $this->assertSame('active', $fresh->status);
        $this->assertSame($chunksBefore, $fresh->chunks()->count(), 're-chunked from the artifact, not from the empty read');
        $this->assertSame(hash('sha256', $markdown), $fresh->document_hash, 'no empty version replaced the valid one');
        $this->assertSame(1, KnowledgeDocument::withoutGlobalScopes()->where('source_path', 'docs/zero.md')->count());
    }

    /** The artifact branch of the re-embed (original dropped, the stored artifact re-chunked) gets the same outcome: done, logged, never a failed job. */
    public function test_a_forced_reembed_from_the_artifact_whose_publish_fails_completes_and_logs_the_missing_artifact(): void
    {
        config(['kb.conversion_artifacts.enabled' => true]);
        $markdown = "# Reembed from artifact\n\nRefused publish, done anyway.";
        $doc = $this->ingestMarkdown($markdown, 'docs/reembed-artifact-refused.md', ['disk' => 'kb', 'prefix' => '']);
        $pointer = (string) $doc->markdown_path;
        Storage::disk('kb')->assertExists($pointer);
        // No source on disk: the job re-chunks the stored artifact (the `markdown_only` shape).
        Storage::disk('kb')->delete('docs/reembed-artifact-refused.md');
        $healthy = Storage::disk('kb');
        $root = $healthy->path('');
        // The job's read of the artifact (the first) must succeed; every LATER read of the final path fails (the disk
        // went away between the read and the publish), and the move onto it is refused — so the post-commit publish
        // can neither verify the file in place nor move the temp over it.
        $reads = 0;
        $adapter = new \Tests\Fixtures\Storage\WriteRefusingAdapter(
            new \League\Flysystem\Local\LocalFilesystemAdapter($root),
            static fn (string $path): bool => $path === $pointer,
            static function (string $path, string $operation) use ($pointer, &$reads): bool {
                return $path === $pointer && $operation === 'read' && ++$reads > 1;
            },
        );
        Storage::set('kb', new \Illuminate\Filesystem\FilesystemAdapter(new \League\Flysystem\Filesystem($adapter), $adapter, ['root' => $root]));
        \Illuminate\Support\Facades\Log::spy();

        try {
            (new \App\Jobs\ReembedDocumentJob((int) $doc->id, app(TenantContext::class)->current()))->handle(app(TenantContext::class), app(DocumentIngestor::class));
        } finally {
            Storage::set('kb', $healthy);
        }

        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->once()->withArgs(static fn (string $message): bool => str_contains($message, 're-embedded but its conversion artifact could not be published'));
        $fresh = $doc->fresh();
        $this->assertSame('active', $fresh->status);
        $this->assertSame($pointer, (string) $fresh->markdown_path, 'the pointer is kept');
        $this->assertGreaterThan(0, $fresh->chunks()->count(), 'the re-embed is done');
    }

    /** SEC-PATH-001 — a temp path outside the artifact root is refused by publish() and discardTemp() before any storage operation. */
    public function test_publish_and_discard_refuse_a_temp_path_outside_the_artifact_root(): void
    {
        config(['kb.conversion_artifacts.enabled' => true]);
        $store = app(ConversionArtifactStore::class);
        $final = $store->pathFor(app(TenantContext::class)->current(), 'eng', 'docs/x.md', str_repeat('a', 64));
        Storage::disk('kb')->put('docs/stray.tmp', 'not ours');

        try {
            $store->publish('kb', 'docs/stray.tmp', $final);
            $this->fail('a temp outside the artifact root must be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not under an artifact root', $e->getMessage());
        }
        Storage::disk('kb')->assertExists('docs/stray.tmp');
        Storage::disk('kb')->assertMissing($final);

        $store->discardTemp('kb', 'docs/stray.tmp');
        Storage::disk('kb')->assertExists('docs/stray.tmp'); // a discard never deletes outside the artifact root
    }

    /** ADR 0030 §8 — a row hard-deleted between its commit and its publish has nothing to stand in for: the temp is discarded, never published as an orphan. */
    public function test_publish_for_a_row_that_no_longer_points_at_the_path_discards_the_temp(): void
    {
        config(['kb.conversion_artifacts.enabled' => true]);
        $store = app(ConversionArtifactStore::class);
        $final = $store->pathFor(app(TenantContext::class)->current(), 'eng', 'docs/gone.md', str_repeat('b', 64));
        $tmp = $store->writeTemp('kb', $final, 'bytes of a deleted row');

        $published = app(DocumentIngestor::class)->publishArtifactForRow('kb', $tmp, $final, 999999, app(TenantContext::class)->current());

        $this->assertFalse($published);
        Storage::disk('kb')->assertMissing($final);
        Storage::disk('kb')->assertMissing($tmp);
        $this->assertFalse(ConversionArtifactStore::tempLeaseHeld('kb', $tmp));
    }

    /**
     * ADR 0030 §3 — the artifact path lock has a TTL and no renewal: a section
     * that outlives it refuses its irreversible step (LockLostException)
     * instead of running under another holder.
     */
    public function test_a_path_lock_lost_during_the_section_refuses_the_step(): void
    {
        config(['kb.conversion_artifacts.enabled' => true]);
        $store = app(ConversionArtifactStore::class);
        $path = $store->pathFor(app(TenantContext::class)->current(), 'eng', 'docs/lost.md', str_repeat('c', 64));

        try {
            $store->underPathLock('kb', $path, static function (\App\Support\Kb\HeldLock $held) use ($path): void {
                \Illuminate\Support\Facades\Cache::lock('kb:artifact:kb:'.sha1($path))->forceRelease(); // the TTL lapsed mid-section
                $held->assertHeld('the step');
            });
            $this->fail('a lapsed path lock must refuse the step');
        } catch (\App\Support\Kb\LockLostException $e) {
            $this->assertStringContainsString('artifact path lock lost', $e->getMessage());
        }
    }

    /** A temp write that THROWS (an adapter raising UnableToWriteFile) gives its lease back and leaves no partial temp behind. */
    public function test_a_temp_write_that_throws_releases_the_lease_and_leaves_nothing(): void
    {
        config(['kb.conversion_artifacts.enabled' => true]);
        $store = app(ConversionArtifactStore::class);
        $final = $store->pathFor(app(TenantContext::class)->current(), 'eng', 'docs/refused-write.md', str_repeat('d', 64));
        $healthy = Storage::disk('kb');
        $root = $healthy->path('');
        $adapter = new \Tests\Fixtures\Storage\WriteRefusingAdapter(new \League\Flysystem\Local\LocalFilesystemAdapter($root), static fn (string $path): bool => str_ends_with($path, '.tmp'));
        // `throw => true`: the disk rethrows UnableToWriteFile instead of answering `false` — the branch under test.
        Storage::set('kb', new \Illuminate\Filesystem\FilesystemAdapter(new \League\Flysystem\Filesystem($adapter), $adapter, ['root' => $root, 'throw' => true]));
        $thrown = null;
        try {
            $store->writeTemp('kb', $final, 'refused');
        } catch (\Throwable $e) {
            $thrown = $e;
        } finally {
            Storage::set('kb', $healthy);
        }
        $this->assertNotNull($thrown, 'the refused write must propagate');
        $this->assertStringContainsString('write refused', $thrown->getMessage(), 'the adapter\'s own exception propagates, after the lease is given back');
        // The adapter recorded the temp path it refused: its lease — taken BEFORE the write — must be gone.
        $this->assertNotNull($adapter->lastRefusedPath, 'the adapter refused the temp write');
        $this->assertFalse(ConversionArtifactStore::tempLeaseHeld('kb', $adapter->lastRefusedPath), 'the lease is given back when the write throws');
        Storage::disk('kb')->assertMissing($adapter->lastRefusedPath);
    }

    /** R30 — the post-commit re-check is bound to the tenant the caller names: a caller naming another tenant never publishes bytes for this row, and the refusal is logged for what it is. */
    public function test_publish_for_a_row_of_another_tenant_discards_the_temp(): void
    {
        config(['kb.conversion_artifacts.enabled' => true]);
        $doc = $this->ingestMarkdown("# Mine\n\nBody.", 'docs/mine.md', ['disk' => 'kb', 'prefix' => '']);
        $final = (string) $doc->markdown_path;
        Storage::disk('kb')->delete($final);
        $store = app(ConversionArtifactStore::class);
        $tmp = $store->writeTemp('kb', $final, "# Mine\n\nBody.");
        \Illuminate\Support\Facades\Log::spy();

        $published = app(DocumentIngestor::class)->publishArtifactForRow('kb', $tmp, $final, (int) $doc->id, 'other-tenant');

        $this->assertFalse($published);
        // The re-check is SCOPED to the named tenant in SQL (R30): another
        // tenant's row is simply not this caller's row and reads as absent.
        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->once()->withArgs(static fn (string $message): bool => str_contains($message, 'no row of this tenant carries that id'));
        Storage::disk('kb')->assertMissing($final);
        Storage::disk('kb')->assertMissing($tmp);
    }

    /**
     * An identical re-ingest that finds the artifact missing behind an
     * existing pointer repairs it — and when THAT publish is refused, the
     * failure is thrown too (the pointer kept), so a connector sync or the
     * ingest job sees it and the next attempt repairs.
     */
    public function test_a_failed_repair_of_an_existing_pointer_throws_keeps_the_pointer_and_the_next_re_ingest_repairs_it(): void
    {
        config(['kb.conversion_artifacts.enabled' => true]);
        $markdown = "# Repair refused\n\nThen repaired.";
        $doc = $this->ingestMarkdown($markdown, 'docs/repair-refused.md');
        $pointer = (string) $doc->markdown_path;
        Storage::disk('kb')->assertExists($pointer);
        Storage::disk('kb')->delete($pointer); // the artifact went missing behind the pointer
        $healthy = Storage::disk('kb');
        $root = $healthy->path('');
        $adapter = new \Tests\Fixtures\Storage\WriteRefusingAdapter(new \League\Flysystem\Local\LocalFilesystemAdapter($root), static fn (string $path): bool => str_ends_with($path, '.tmp'));
        Storage::set('kb', new \Illuminate\Filesystem\FilesystemAdapter(new \League\Flysystem\Filesystem($adapter), $adapter, ['root' => $root]));

        $thrown = null;
        try {
            $this->ingestMarkdown($markdown, 'docs/repair-refused.md');
        } catch (ArtifactPublishFailedException $e) {
            $thrown = $e;
        }
        $this->assertInstanceOf(ArtifactPublishFailedException::class, $thrown);
        $this->assertSame($pointer, $thrown->markdownPath);
        $this->assertSame($pointer, (string) $doc->fresh()->markdown_path, 'the pointer is kept');
        $this->assertSame('missing', app(\App\Services\Kb\Versioning\DocumentVersionService::class)->artifactStateFor($doc->fresh()));

        Storage::set('kb', $healthy);
        $repaired = $this->ingestMarkdown($markdown, 'docs/repair-refused.md');
        $this->assertSame((int) $doc->id, (int) $repaired->id);
        Storage::disk('kb')->assertExists($pointer);
        $this->assertSame('verified', app(\App\Services\Kb\Versioning\DocumentVersionService::class)->artifactStateFor($repaired->fresh()));
    }

    /**
     * ADR 0029 §8 / ADR 0030 §4 — `converter_hints` are untrusted (they ride
     * the request and connector metadata): a hint is a namespaced bag that
     * can add to what the chunker reads but never rewrite how the text was
     * obtained, nor a key the chunkers read as the converter's own. A forged
     * `provenance=ocr` must not record a plain document as machine-read
     * (`system:ocr` actor, OCR generation tier); a forged `filename` must not
     * reach the chunks' citations.
     */
    public function test_converter_hints_cannot_forge_the_ocr_provenance(): void
    {
        $doc = app(DocumentIngestor::class)->ingest('eng', new SourceDocument(
            sourcePath: 'docs/forged.md', mimeType: 'text/markdown', bytes: "# Forged\n\nA plain Markdown document.",
            externalUrl: null, externalId: null, connectorType: 'local',
            metadata: ['disk' => 'kb', 'prefix' => '', 'converter_hints' => [
                'provenance' => 'ocr',
                'ocr' => ['driver' => 'forged', 'remote' => false],
                'extraction_strategy' => 'ocr',
                'source_type' => 'image',
                'filename' => 'evil.md', // a scalar on a key every chunker reads as the converter's own
                'notion' => ['page' => 'p-1'], // a legitimate chunker hint rides along
            ]],
        ), 'Forged');

        $converter = $doc->fresh()->metadata['converter'];
        $this->assertNotSame('ocr', $converter['provenance'] ?? null, 'a hint never sets the provenance');
        $this->assertArrayNotHasKey('ocr', $converter);
        $this->assertNotSame('ocr', $converter['extraction_strategy'] ?? null);
        $this->assertSame('markdown', $converter['source_type']);
        $this->assertSame('p-1', $converter['notion']['page'], 'legitimate hints still reach the chunker surface');
        $this->assertSame('forged.md', $converter['filename'], 'a scalar hint never lands on a key the chunkers read');
        $this->assertSame('forged.md', $doc->chunks()->first()->metadata['filename'] ?? 'forged.md');
        $this->assertSame('system:ingest', $doc->version_actor);
        $this->assertNotSame('ocr', $doc->fresh()->metadata['generation_source'] ?? null);
    }

    /**
     * ADR 0030 §3 — a pre-v8.36 row that never recorded its disk is an
     * AMBIGUOUS reference (it may live on any disk its path matches): it
     * blocks the `markdown_only` drop on every disk, the same fail-closed
     * rule the deleter applies — never mapped to the configured disk and
     * skipped when the ingest names another.
     */
    public function test_a_legacy_row_without_a_recorded_disk_blocks_the_drop_on_every_disk(): void
    {
        config(['kb.conversion_artifacts.enabled' => true, 'kb.source_retention.mode' => 'markdown_only', 'kb.sources.disk' => 'kb-configured']);
        Storage::fake('kb-configured'); // resolvable but empty: the assertion below is the only reason this test can fail
        $bytes = PdfFixtureBuilder::buildThreePageSample();
        Storage::disk('kb')->put('reports/legacy.pdf', $bytes);
        // The legacy row: same source path, an older version, no `metadata.disk` at all.
        KnowledgeDocument::create([
            'project_key' => 'eng', 'source_path' => 'reports/legacy.pdf', 'source_type' => 'pdf', 'title' => 'Legacy',
            'mime_type' => 'application/pdf', 'language' => 'it', 'access_scope' => 'internal', 'status' => 'archived',
            'document_hash' => str_repeat('1', 64), 'version_hash' => str_repeat('1', 64), 'metadata' => [], 'indexed_at' => now()->subDay(),
        ]);

        $doc = app(DocumentIngestor::class)->ingest('eng', new SourceDocument(
            sourcePath: 'reports/legacy.pdf', mimeType: 'application/pdf', bytes: $bytes,
            externalUrl: null, externalId: null, connectorType: 'local', metadata: ['disk' => 'kb', 'prefix' => ''],
        ), 'Legacy');

        Storage::disk('kb')->assertExists((string) $doc->markdown_path);
        Storage::disk('kb')->assertExists('reports/legacy.pdf'); // the legacy row still needs it — on this disk too
        $this->assertArrayNotHasKey('source_dropped', $doc->fresh()->metadata);
    }

    /** SEC-SETTING-SHAPE-001 — a lock TTL that is not a positive number of seconds is the documented default, not a 1-second lock, and it is said once. */
    public function test_a_non_positive_lock_ttl_falls_back_to_the_default_and_warns(): void
    {
        config(['kb.conversion_artifacts.enabled' => true, 'kb.source_retention.mode' => 'markdown_only', 'kb.conversion_artifacts.source_lock_seconds' => 0]);
        \Illuminate\Support\Facades\Log::spy();
        $bytes = PdfFixtureBuilder::buildThreePageSample();
        Storage::disk('kb')->put('reports/q12.pdf', $bytes);

        $doc = app(DocumentIngestor::class)->ingest('eng', new SourceDocument(
            sourcePath: 'reports/q12.pdf', mimeType: 'application/pdf', bytes: $bytes,
            externalUrl: null, externalId: null, connectorType: 'local', metadata: ['disk' => 'kb', 'prefix' => ''],
        ), 'Q12');

        Storage::disk('kb')->assertExists((string) $doc->markdown_path);
        Storage::disk('kb')->assertMissing('reports/q12.pdf');
        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')
            ->with(Mockery::on(static fn (string $message): bool => str_contains($message, 'source_lock_seconds')), Mockery::on(static fn (array $context): bool => ($context['default'] ?? null) === 60 && ($context['configured'] ?? null) === 0))
            ->once(); // the fallback is reported once per process, not once per lock taken
    }

    /** ADR 0030 §3 / R21 — a `reference_only` version stores no artifact but still requires the shared original: its commit takes the storage-key lock too, so it can never commit past a concurrent drop's reference scan. */
    public function test_a_reference_only_ingest_commits_under_the_storage_key_lock_while_the_flag_is_on(): void
    {
        config(['kb.conversion_artifacts.enabled' => true, 'kb.source_retention.mode' => 'reference_only', 'kb.conversion_artifacts.source_lock_wait_seconds' => 0]);
        $bytes = PdfFixtureBuilder::buildThreePageSample();
        Storage::disk('kb')->put('reports/q14.pdf', $bytes);
        $held = \Illuminate\Support\Facades\Cache::lock('kb:source:kb:'.sha1('reports/q14.pdf'), 60);
        $this->assertTrue($held->get());
        try {
            try {
                app(DocumentIngestor::class)->ingest('eng', new SourceDocument(
                    sourcePath: 'reports/q14.pdf', mimeType: 'application/pdf', bytes: $bytes,
                    externalUrl: null, externalId: null, connectorType: 'local', metadata: ['disk' => 'kb', 'prefix' => ''],
                ), 'Q14');
                $this->fail('a reference_only commit under a held storage-key lock must fail loudly');
            } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
                // expected: nothing committed past the drop in progress
            }
            $this->assertSame(0, KnowledgeDocument::withoutGlobalScopes()->where('source_path', 'reports/q14.pdf')->count());
        } finally {
            $held->release();
        }
        $doc = app(DocumentIngestor::class)->ingest('eng', new SourceDocument(
            sourcePath: 'reports/q14.pdf', mimeType: 'application/pdf', bytes: $bytes,
            externalUrl: null, externalId: null, connectorType: 'local', metadata: ['disk' => 'kb', 'prefix' => ''],
        ), 'Q14');
        $this->assertNull($doc->markdown_path);
        $this->assertSame('reference_only', $doc->fresh()->metadata['source_retention']);
    }

    /** R43 — with the artifacts flag OFF (and for Markdown sources) no drop is possible, so an ingest never waits on the storage-key lock. */
    public function test_off_an_ingest_never_waits_on_the_storage_key_lock(): void
    {
        config(['kb.conversion_artifacts.enabled' => false, 'kb.source_retention.mode' => 'markdown_only', 'kb.conversion_artifacts.source_lock_wait_seconds' => 0]);
        $bytes = PdfFixtureBuilder::buildThreePageSample();
        Storage::disk('kb')->put('reports/q10.pdf', $bytes);
        $held = \Illuminate\Support\Facades\Cache::lock('kb:source:kb:'.sha1('reports/q10.pdf'), 60);
        $heldMd = \Illuminate\Support\Facades\Cache::lock('kb:source:kb:'.sha1('docs/held.md'), 60);
        $this->assertTrue($held->get() && $heldMd->get());
        try {
            $doc = app(DocumentIngestor::class)->ingest('eng', new SourceDocument(
                sourcePath: 'reports/q10.pdf', mimeType: 'application/pdf', bytes: $bytes,
                externalUrl: null, externalId: null, connectorType: 'local', metadata: ['disk' => 'kb', 'prefix' => ''],
            ), 'Q10');
            $this->assertNotNull($doc->id);
            Storage::disk('kb')->assertExists('reports/q10.pdf');

            // A Markdown source is its own artifact and is never dropped: no lock even with the flag on.
            config(['kb.conversion_artifacts.enabled' => true]);
            $md = $this->ingestMarkdown("# Held\n\nNever dropped.", 'docs/held.md');
            $this->assertNotNull($md->markdown_path);
        } finally {
            $held->release();
            $heldMd->release();
        }
    }

    /** An unknown retention value is never a permissive one: it resolves to the configured mode, and the untrusted boundaries strip the key anyway. */
    public function test_an_unknown_source_retention_value_is_replaced_by_the_configured_mode(): void
    {
        config(['kb.conversion_artifacts.enabled' => true, 'kb.source_retention.mode' => 'full_copy']);

        $doc = $this->ingestMarkdown("# Unknown\n\nBody.", 'docs/unknown.md', ['source_retention' => 'drop_everything']);

        $this->assertSame('full_copy', $doc->fresh()->metadata['source_retention']);
    }

    /**
     * ADR 0030 §3 — an identical re-ingest with the flag on publishes the
     * artifact of a version that predates the artifacts (no pointer), under
     * ITS contract: a row stamped `reference_only` gets nothing.
     */
    public function test_an_identical_re_ingest_publishes_the_artifact_of_a_version_that_predates_the_artifacts(): void
    {
        config(['kb.conversion_artifacts.enabled' => false]);
        $markdown = "# Legacy\n\nIngested before the flag.";
        $legacy = $this->ingestMarkdown($markdown, 'docs/legacy.md');
        $this->assertNull($legacy->markdown_path);
        $reference = $this->ingestMarkdown("# Ref\n\nReference only.", 'docs/ref.md');
        $reference->update(['metadata' => array_merge($reference->metadata, ['source_retention' => 'reference_only'])]);

        config(['kb.conversion_artifacts.enabled' => true]);
        $again = $this->ingestMarkdown($markdown, 'docs/legacy.md');
        $refAgain = $this->ingestMarkdown("# Ref\n\nReference only.", 'docs/ref.md');

        $this->assertSame($legacy->id, $again->id, 'no new version');
        $this->assertNotNull($again->markdown_path);
        $this->assertSame($markdown, Storage::disk('kb')->get((string) $again->markdown_path));
        $this->assertSame(hash('sha256', $markdown), $again->fresh()->content_hash);
        $this->assertSame($reference->id, $refAgain->id);
        $this->assertNull($refAgain->fresh()->markdown_path);
    }

    /**
     * ADR 0030 §3 — the publish and the removal assert the path lock
     * IMMEDIATELY before their irreversible step, not once far upstream:
     * the containment checks and the existence probes between are storage
     * round-trips, and a TTL that lapses across them must refuse rather than
     * move or delete under whoever holds the path now.
     */
    public function test_publish_and_remove_refuse_a_lock_that_lapsed_after_their_probes(): void
    {
        config(['kb.conversion_artifacts.enabled' => true]);
        $doc = $this->ingestMarkdown("# Kept\n\nBody.", 'docs/kept.md', ['disk' => 'kb', 'prefix' => '']);
        $final = (string) $doc->markdown_path;
        $store = app(ConversionArtifactStore::class);

        $lapsed = new class('kb:artifact:test', 60) extends \Illuminate\Cache\Lock
        {
            public function acquire()
            {
                return true;
            }

            public function release()
            {
                return true;
            }

            public function forceRelease() {}

            protected function getCurrentOwner()
            {
                return 'another-holder';
            }
        };
        $held = new \App\Support\Kb\HeldLock($lapsed, 'artifact path');
        \Illuminate\Support\Facades\Log::spy();

        // The removal: the file exists, the probe passes, the lock does not.
        $this->assertSame(ConversionArtifactStore::FAILED, $store->remove('kb', $final, $held));
        Storage::disk('kb')->assertExists($final);

        // The publish: the temp stays where the discard can take it, the
        // final keeps the bytes that were already there.
        $tmp = $store->writeTemp('kb', $final, "# Other\n\nBytes.");
        $thrown = null;
        try {
            $store->publish('kb', $tmp, $final, $held);
        } catch (\App\Support\Kb\LockLostException $e) {
            $thrown = $e;
        }
        $this->assertInstanceOf(\App\Support\Kb\LockLostException::class, $thrown);
        $this->assertSame("# Kept\n\nBody.", Storage::disk('kb')->get($final), 'the final was not overwritten under a lapsed lock');
        // WHERE the refusal happened, not merely that it happened: this
        // warning comes from the comparison of the final's bytes with the
        // temp's, so both probes ran BEFORE the lock was asserted — an
        // assertion still sitting upstream of them could not produce it.
        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->withArgs(static fn (string $message): bool => str_contains($message, 'does not match its content hash'))->once();
        $store->discardTemp('kb', $tmp);
    }

    /**
     * ADR 0030 §3 — the one window the key lock cannot close: Laravel issues
     * the COMMIT after the transaction closure returns, so a TTL that lapses
     * in that last stretch leaves the row invisible to a holder that takes
     * the key. The outcome is reconciled, not assumed: the original is
     * probed and, when it is gone while the row's artifact stands in for it,
     * the row is stamped `source_dropped` and the loss reported.
     */
    public function test_a_key_lock_that_lapses_across_the_commit_reconciles_the_row_instead_of_assuming(): void
    {
        config(['kb.conversion_artifacts.enabled' => true, 'kb.source_retention.mode' => 'full_copy']);
        $bytes = PdfFixtureBuilder::buildThreePageSample();
        // The original IS on the disk when the commit starts: only a source
        // that was there and is gone afterwards was taken by a holder.
        Storage::disk('kb')->put('reports/q17.pdf', $bytes);
        $key = 'kb:source:kb:'.sha1('reports/q17.pdf');
        $store = \Illuminate\Support\Facades\Cache::store();
        $lapsingAfterCommit = new class($key, 60) extends \Illuminate\Cache\Lock
        {
            public int $checks = 0;

            public function acquire()
            {
                return true;
            }

            public function release()
            {
                return true;
            }

            public function forceRelease() {}

            protected function getCurrentOwner()
            {
                // Owned while the transaction runs; once it returns the key
                // is another holder's — and that holder, a `markdown_only`
                // drop that never saw the uncommitted row, takes the original
                // with it. That is the whole scenario, reproduced here.
                if (++$this->checks <= 1) {
                    return $this->owner;
                }
                Storage::disk('kb')->delete('reports/q17.pdf');

                return 'another-holder';
            }
        };
        \Illuminate\Support\Facades\Cache::partialMock()
            ->shouldReceive('lock')
            ->andReturnUsing(fn (string $name, int $seconds = 0, $owner = null) => $name === $key ? $lapsingAfterCommit : $store->lock($name, $seconds, $owner));
        \Illuminate\Support\Facades\Log::spy();

        $doc = app(DocumentIngestor::class)->ingest('eng', new SourceDocument(
            sourcePath: 'reports/q17.pdf', mimeType: 'application/pdf', bytes: $bytes,
            externalUrl: null, externalId: null, connectorType: 'local', metadata: ['disk' => 'kb', 'prefix' => ''],
        ), 'Q17');

        $this->assertTrue((bool) ($doc->fresh()->metadata['source_dropped'] ?? false), 'the row records that its original is gone');
        Storage::disk('kb')->assertExists((string) $doc->markdown_path); // the artifact stands in for the source
        \Illuminate\Support\Facades\Log::shouldHaveReceived('error')->once()->withArgs(static fn (string $message): bool => str_contains($message, 'the original was dropped by a concurrent holder'));
    }

    /**
     * `source_dropped` says "an artifact stands in for the original", so it
     * is stamped only once that artifact is really on the disk: a publish
     * that fails after the original was taken leaves the row UNSTAMPED and
     * reported, so the orphan sweep keeps seeing it.
     */
    public function test_a_publish_that_fails_after_the_original_was_taken_leaves_the_row_unstamped(): void
    {
        config(['kb.conversion_artifacts.enabled' => true, 'kb.source_retention.mode' => 'full_copy']);
        $bytes = PdfFixtureBuilder::buildThreePageSample();
        Storage::disk('kb')->put('reports/q19.pdf', $bytes);
        $key = 'kb:source:kb:'.sha1('reports/q19.pdf');
        $store = \Illuminate\Support\Facades\Cache::store();
        $lapsingAfterCommit = new class($key, 60) extends \Illuminate\Cache\Lock
        {
            public int $checks = 0;

            public function acquire()
            {
                return true;
            }

            public function release()
            {
                return true;
            }

            public function forceRelease() {}

            protected function getCurrentOwner()
            {
                if (++$this->checks <= 1) {
                    return $this->owner;
                }
                Storage::disk('kb')->delete('reports/q19.pdf'); // the concurrent holder takes the original

                return 'another-holder';
            }
        };
        \Illuminate\Support\Facades\Cache::partialMock()
            ->shouldReceive('lock')
            ->andReturnUsing(fn (string $name, int $seconds = 0, $owner = null) => $name === $key ? $lapsingAfterCommit : $store->lock($name, $seconds, $owner));
        // The row is repointed the instant it commits: the publish then finds
        // nothing to stand in for and refuses.
        \Illuminate\Support\Facades\Event::listen('eloquent.created: '.KnowledgeDocument::class, static function (KnowledgeDocument $row): void {
            if ($row->source_path === 'reports/q19.pdf') {
                KnowledgeDocument::withoutGlobalScopes()->whereKey($row->id)->update(['markdown_path' => null]);
            }
        });
        \Illuminate\Support\Facades\Log::spy();

        $doc = app(DocumentIngestor::class)->ingest('eng', new SourceDocument(
            sourcePath: 'reports/q19.pdf', mimeType: 'application/pdf', bytes: $bytes,
            externalUrl: null, externalId: null, connectorType: 'local', metadata: ['disk' => 'kb', 'prefix' => ''],
        ), 'Q19');

        $this->assertArrayNotHasKey('source_dropped', $doc->fresh()->metadata ?? [], 'nothing stands in for the source: the row stays visible to the sweeps');
        \Illuminate\Support\Facades\Log::shouldHaveReceived('error')->withArgs(static fn (string $message): bool => str_contains($message, 'could NOT be published'))->once();
    }

    /**
     * ADR 0030 §3 / R43 — on a store that cannot exclude concurrent holders
     * the storage key gives no serialization at all, so an artifact-enabled
     * ingest of a non-Markdown source is REFUSED rather than committed
     * believing it is serialized; and the `markdown_only` drop keeps the
     * original for the same reason.
     */
    public function test_a_store_that_cannot_exclude_refuses_the_ingest_and_keeps_the_original(): void
    {
        config(['kb.conversion_artifacts.enabled' => true, 'kb.source_retention.mode' => 'markdown_only']);
        config(['cache.stores.nullish' => ['driver' => 'null'], 'cache.default' => 'nullish']);
        $bytes = PdfFixtureBuilder::buildThreePageSample();
        Storage::disk('kb')->put('reports/q20.pdf', $bytes);

        $thrown = null;
        try {
            app(DocumentIngestor::class)->ingest('eng', new SourceDocument(
                sourcePath: 'reports/q20.pdf', mimeType: 'application/pdf', bytes: $bytes,
                externalUrl: null, externalId: null, connectorType: 'local', metadata: ['disk' => 'kb', 'prefix' => ''],
            ), 'Q20');
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(\RuntimeException::class, $thrown, 'the ingest is refused, never committed unserialized');
        $this->assertStringContainsString('the storage key lock is unavailable', $thrown->getMessage());
        $this->assertSame(0, KnowledgeDocument::withoutGlobalScopes()->where('source_path', 'reports/q20.pdf')->count());
        Storage::disk('kb')->assertExists('reports/q20.pdf'); // the original is untouched
        $this->assertSame([], array_filter(Storage::disk('kb')->allFiles('.artifacts'), static fn (string $f): bool => ! str_ends_with($f, '.tmp')), 'nothing published');
    }

    /**
     * The other half of the reconciliation: a source that was NEVER on this
     * disk (an ingest from bytes — a benchmark corpus, an API batch) must
     * not be stamped `source_dropped` because the lock lapsed and the probe
     * found nothing. Only a source that WAS there and is gone afterwards was
     * taken by a holder; anything else is a TTL to raise, reported as such.
     */
    public function test_a_lapse_across_the_commit_never_stamps_a_source_that_was_never_on_the_disk(): void
    {
        config(['kb.conversion_artifacts.enabled' => true, 'kb.source_retention.mode' => 'full_copy']);
        $bytes = PdfFixtureBuilder::buildThreePageSample();
        // Deliberately NOT on the disk: the ingest carries the bytes itself.
        $key = 'kb:source:kb:'.sha1('reports/q18.pdf');
        $store = \Illuminate\Support\Facades\Cache::store();
        $lapsingAfterCommit = new class($key, 60) extends \Illuminate\Cache\Lock
        {
            public int $checks = 0;

            public function acquire()
            {
                return true;
            }

            public function release()
            {
                return true;
            }

            public function forceRelease() {}

            protected function getCurrentOwner()
            {
                return ++$this->checks <= 1 ? $this->owner : 'another-holder';
            }
        };
        \Illuminate\Support\Facades\Cache::partialMock()
            ->shouldReceive('lock')
            ->andReturnUsing(fn (string $name, int $seconds = 0, $owner = null) => $name === $key ? $lapsingAfterCommit : $store->lock($name, $seconds, $owner));
        \Illuminate\Support\Facades\Log::spy();

        $doc = app(DocumentIngestor::class)->ingest('eng', new SourceDocument(
            sourcePath: 'reports/q18.pdf', mimeType: 'application/pdf', bytes: $bytes,
            externalUrl: null, externalId: null, connectorType: 'local', metadata: ['disk' => 'kb', 'prefix' => ''],
        ), 'Q18');

        $this->assertArrayNotHasKey('source_dropped', $doc->fresh()->metadata ?? [], 'nothing was taken: nothing is stamped');
        Storage::disk('kb')->assertExists((string) $doc->markdown_path);
        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->withArgs(static fn (string $message): bool => str_contains($message, 'no original was taken'))->once();
        \Illuminate\Support\Facades\Log::shouldNotHaveReceived('error');
    }

    /**
     * ADR 0030 §3 / R30 — the repair of a version that predates the
     * artifacts composes its path from the ROW's tenant, not from the
     * ambient context: the pointer update and the publish both authorize
     * against `$existing->tenant_id`, so a context that moved between the
     * lookup and the repair (or a caller outside the current-tenant job)
     * must not point the row at another tenant's content-addressed path.
     */
    public function test_the_repair_of_a_pointerless_version_composes_the_path_from_the_rows_tenant(): void
    {
        config(['kb.conversion_artifacts.enabled' => true]);
        $markdown = "# Legacy\n\nBody.";
        $existing = $this->ingestMarkdown($markdown, 'docs/legacy-tenant.md', ['disk' => 'kb', 'prefix' => '']);
        $existing->updateUnscopedWithinOwnTenant(['markdown_path' => null, 'content_hash' => null]);
        $existing = KnowledgeDocument::withoutGlobalScopes()->findOrFail($existing->id);
        $rowTenant = (string) $existing->tenant_id;
        $this->assertNotSame('', $rowTenant);
        Storage::disk('kb')->delete(Storage::disk('kb')->allFiles('.artifacts'));

        // The context has moved on since the row was read.
        app(TenantContext::class)->set('another-tenant');

        $method = new \ReflectionMethod(DocumentIngestor::class, 'publishArtifactOfPointerlessVersion');
        $published = $method->invoke(
            app(DocumentIngestor::class),
            $existing,
            $markdown,
            ['disk' => 'kb', 'prefix' => ''],
            'kb',
            app(ConversionArtifactStore::class),
        );

        $this->assertTrue($published);
        $final = (string) KnowledgeDocument::withoutGlobalScopes()->findOrFail($existing->id)->markdown_path;
        $this->assertStringContainsString('.artifacts/'.$rowTenant.'/', $final, "the row's own tenant namespace");
        $this->assertStringNotContainsString('another-tenant', $final);
        Storage::disk('kb')->assertExists($final);
    }

    /** The repair of an existing version goes to the disk the version RECORDED, not to the incoming request's. */
    public function test_an_identical_re_ingest_repairs_the_artifact_on_the_versions_recorded_disk(): void
    {
        Storage::fake('kb-archive');
        config(['kb.conversion_artifacts.enabled' => true]);
        $markdown = "# Moved\n\nStored on the archive disk.";
        $doc = $this->ingestMarkdown($markdown, 'docs/moved.md', ['disk' => 'kb-archive', 'prefix' => '']);
        Storage::disk('kb-archive')->assertExists((string) $doc->markdown_path);
        Storage::disk('kb-archive')->delete((string) $doc->markdown_path);

        $again = $this->ingestMarkdown($markdown, 'docs/moved.md', ['disk' => 'kb', 'prefix' => '']);

        $this->assertSame($doc->id, $again->id);
        Storage::disk('kb-archive')->assertExists((string) $doc->markdown_path);
        Storage::disk('kb')->assertMissing((string) $doc->markdown_path);
    }

    public function test_markdown_only_retention_never_drops_a_markdown_source_which_is_its_own_artifact(): void
    {
        config(['kb.conversion_artifacts.enabled' => true, 'kb.source_retention.mode' => 'markdown_only']);
        Storage::disk('kb')->put('docs/keep.md', "# Keep\n\nBody.");

        $doc = $this->ingestMarkdown("# Keep\n\nBody.", 'docs/keep.md', ['disk' => 'kb', 'prefix' => '']);

        $this->assertNotNull($doc->markdown_path);
        Storage::disk('kb')->assertExists('docs/keep.md');
    }

    public function test_tenant_and_project_are_safe_segments_never_verbatim(): void
    {
        config(['kb.conversion_artifacts.enabled' => true]);
        $markdown = "# Seg\n\nBody.";

        // A 121-char project key exceeds the safe-segment length: hashed, never verbatim.
        $doc = $this->ingestMarkdown($markdown, 'docs/seg.md', [], str_repeat('p', 121));

        $tenant = app(TenantContext::class)->current();
        $projectSeg = 'h-'.hash('sha256', str_repeat('p', 121));
        $this->assertSame('.artifacts/'.$tenant.'/'.$projectSeg.'/docs/seg.md.versions/'.hash('sha256', $markdown).'.md', $doc->markdown_path);
        Storage::disk('kb')->assertExists((string) $doc->markdown_path);

        // The segment rule itself: traversal, separators and dot-only names are hashed.
        $this->assertSame('h-'.hash('sha256', 'acme/../evil'), ConversionArtifactStore::safeSegment('acme/../evil'));
        $this->assertSame('h-'.hash('sha256', '..'), ConversionArtifactStore::safeSegment('..'));
        $this->assertSame('h-'.hash('sha256', '.hidden'), ConversionArtifactStore::safeSegment('.hidden'));
        $this->assertSame('acme-1.0_x', ConversionArtifactStore::safeSegment('acme-1.0_x'));
        // ADR 0030 §3 — the encoding is injective: `h-` is reserved, so a name that
        // LOOKS encoded is itself hashed and can never collide with the unsafe value
        // whose digest it spells (the same source/version would otherwise share a path).
        $unsafe = 'acme/evil';
        $spoof = 'h-'.hash('sha256', $unsafe);
        $this->assertSame($spoof, ConversionArtifactStore::safeSegment($unsafe));
        $this->assertSame('h-'.hash('sha256', $spoof), ConversionArtifactStore::safeSegment($spoof));
        $this->assertNotSame(ConversionArtifactStore::safeSegment($unsafe), ConversionArtifactStore::safeSegment($spoof));
        $this->assertSame('h-'.hash('sha256', 'h-plain'), ConversionArtifactStore::safeSegment('h-plain'));
        // And the composed path can never leave the root.
        $store = app(ConversionArtifactStore::class);
        $this->expectException(\InvalidArgumentException::class);
        $store->pathFor('t', 'p', '../outside.md', str_repeat('a', 64));
    }

    public function test_an_identical_re_ingest_is_a_no_op_that_keeps_one_artifact(): void
    {
        config(['kb.conversion_artifacts.enabled' => true]);
        $markdown = "# Same\n\nBody.";

        $first = $this->ingestMarkdown($markdown);
        $second = $this->ingestMarkdown($markdown);

        $this->assertSame($first->id, $second->id);
        $this->assertCount(1, Storage::disk('kb')->allFiles('.artifacts'));
    }

    /**
     * ADR 0030 §3 — the same-hash short-circuit verifies the existing
     * version's artifact and republishes a missing or corrupt one from the
     * freshly converted bytes: no new version, no chunk rewrite.
     */
    public function test_an_identical_re_ingest_repairs_a_missing_or_corrupt_artifact_without_a_new_version(): void
    {
        config(['kb.conversion_artifacts.enabled' => true]);
        $markdown = "# Repair\n\nBody.";
        $first = $this->ingestMarkdown($markdown, 'docs/repair.md');
        $path = (string) $first->markdown_path;
        $chunks = KnowledgeChunk::query()->where('knowledge_document_id', $first->id)->pluck('id')->all();

        Storage::disk('kb')->put($path, '# Repair'); // corrupt on disk
        $again = $this->ingestMarkdown($markdown, 'docs/repair.md');
        $this->assertSame($first->id, $again->id);
        $this->assertSame($markdown, Storage::disk('kb')->get($path), 'the corrupt artifact is replaced by the verified bytes');
        $this->assertSame($first->content_hash, $again->fresh()->content_hash);
        $this->assertSame($chunks, KnowledgeChunk::query()->where('knowledge_document_id', $first->id)->pluck('id')->all(), 'no chunk rewrite');

        Storage::disk('kb')->delete($path); // missing on disk
        $this->ingestMarkdown($markdown, 'docs/repair.md');
        $this->assertSame($markdown, Storage::disk('kb')->get($path), 'a missing artifact is republished');
        $this->assertCount(1, Storage::disk('kb')->allFiles('.artifacts'), 'no temp is left behind');
    }

    public function test_a_concurrent_identical_publish_keeps_the_winner_and_drops_only_the_losers_temp(): void
    {
        $store = app(ConversionArtifactStore::class);
        $final = $store->pathFor('default', 'eng', 'docs/race.md', str_repeat('a', 64));
        $winner = $store->writeTemp('kb', $final, 'same bytes');
        $store->publish('kb', $winner, $final);
        $loser = $store->writeTemp('kb', $final, 'same bytes');
        $bystander = $store->writeTemp('kb', $final, 'same bytes');

        $store->publish('kb', $loser, $final);

        Storage::disk('kb')->assertExists($final);
        Storage::disk('kb')->assertMissing($loser);
        Storage::disk('kb')->assertExists($bystander); // another writer's temp is never touched
        $this->assertSame('same bytes', Storage::disk('kb')->get($final));
    }

    /**
     * ADR 0030 §3 — a final that already exists is re-hashed, never taken on
     * faith: a corrupt (truncated / replaced) artifact is replaced by the
     * verified temp, so the next identical ingest repairs it instead of
     * dropping the correct bytes and keeping the corrupt ones.
     */
    public function test_a_corrupt_pre_existing_final_is_replaced_by_the_verified_temp_not_kept(): void
    {
        $store = app(ConversionArtifactStore::class);
        $markdown = "# Right\n\nBody.";
        $final = $store->pathFor('default', 'eng', 'docs/repair.md', hash('sha256', $markdown));
        Storage::disk('kb')->put($final, '# Right'); // truncated on disk
        $temp = $store->writeTemp('kb', $final, $markdown);

        $store->publish('kb', $temp, $final);

        $this->assertSame($markdown, Storage::disk('kb')->get($final), 'the verified bytes replace the corrupt file');
        Storage::disk('kb')->assertMissing($temp);
    }

    public function test_a_failed_transaction_discards_this_attempts_temp_and_publishes_nothing(): void
    {
        config(['kb.conversion_artifacts.enabled' => true]);
        // Two canonical docs in one project sharing a slug on different paths:
        // the second insert violates uq_kb_doc_slug INSIDE the transaction.
        $frontmatter = "---\nid: dec-1\nslug: dec-1\ntype: decision\nstatus: accepted\ntitle: Dec\n---\n\n# Dec\n\nBody.\n";
        $this->ingestMarkdown($frontmatter, 'docs/a.md');
        $before = count(Storage::disk('kb')->allFiles('.artifacts'));

        $collided = false;
        try {
            $this->ingestMarkdown($frontmatter."\nChanged.", 'docs/b.md');
        } catch (\Illuminate\Database\QueryException) {
            $collided = true;
        }
        $this->assertTrue($collided, 'expected the unique-slot collision to fail the ingest inside the transaction');

        $files = Storage::disk('kb')->allFiles('.artifacts');
        $this->assertCount($before, $files);
        foreach ($files as $file) {
            $this->assertStringEndsNotWith('.tmp', $file);
        }
    }

    /**
     * ADR 0030 §3 — the dropped original must never be read as an orphan:
     * `kb:ingest-folder --prune-orphans` walks the disk, the file is gone by
     * design, and the row stays.
     */
    public function test_markdown_only_rows_survive_the_orphan_sweep_after_their_original_was_dropped(): void
    {
        config(['kb.conversion_artifacts.enabled' => true, 'kb.source_retention.mode' => 'markdown_only']);
        $bytes = PdfFixtureBuilder::buildThreePageSample();
        Storage::disk('kb')->put('reports/q2.pdf', $bytes);
        $doc = app(DocumentIngestor::class)->ingest('eng', new SourceDocument(
            sourcePath: 'reports/q2.pdf', mimeType: 'application/pdf', bytes: $bytes,
            externalUrl: null, externalId: null, connectorType: 'local', metadata: ['disk' => 'kb', 'prefix' => ''],
        ), 'Q2');
        Storage::disk('kb')->assertMissing('reports/q2.pdf');
        $this->assertTrue($doc->fresh()->metadata['source_dropped']);

        // The real disk listing no longer contains the source: a naive sweep would delete it.
        $existing = array_values(array_filter(Storage::disk('kb')->allFiles('reports'), static fn (string $f): bool => ! str_starts_with($f, '.artifacts')));
        $results = app(\App\Services\Kb\DocumentDeleter::class)->deleteOrphans('eng', 'reports', $existing, force: true, tenantId: app(TenantContext::class)->current());

        $this->assertSame([], $results);
        $this->assertDatabaseHas('knowledge_documents', ['id' => $doc->id, 'deleted_at' => null]);
        Storage::disk('kb')->assertExists((string) $doc->markdown_path);
    }

    /**
     * ADR 0030 §3 — the original is a shared storage key: another row (any
     * tenant, trashed included) without an artifact keeps it alive.
     */
    public function test_markdown_only_keeps_the_original_while_another_row_on_the_same_key_has_no_artifact(): void
    {
        config(['kb.conversion_artifacts.enabled' => true, 'kb.source_retention.mode' => 'markdown_only']);
        $bytes = PdfFixtureBuilder::buildThreePageSample();
        Storage::disk('kb')->put('reports/shared.pdf', $bytes);
        // Tenant B ingested the same key before artifacts existed (no artifact on its row).
        app(TenantContext::class)->set('tenant-b');
        KnowledgeDocument::create([
            'project_key' => 'eng', 'source_path' => 'reports/shared.pdf', 'source_type' => 'pdf', 'title' => 'B',
            'mime_type' => 'application/pdf', 'language' => 'it', 'access_scope' => 'internal', 'status' => 'active',
            'document_hash' => hash('sha256', 'b'), 'version_hash' => hash('sha256', 'b'),
            'metadata' => ['disk' => 'kb', 'prefix' => ''], 'indexed_at' => now(),
        ]);
        app(TenantContext::class)->reset();

        $doc = app(DocumentIngestor::class)->ingest('eng', new SourceDocument(
            sourcePath: 'reports/shared.pdf', mimeType: 'application/pdf', bytes: $bytes,
            externalUrl: null, externalId: null, connectorType: 'local', metadata: ['disk' => 'kb', 'prefix' => ''],
        ), 'A');

        Storage::disk('kb')->assertExists('reports/shared.pdf');
        Storage::disk('kb')->assertExists((string) $doc->markdown_path);
        $this->assertArrayNotHasKey('source_dropped', $doc->fresh()->metadata);
    }

    /**
     * ADR 0030 §2/§4 — a forced re-embed re-runs persistence on the SAME row:
     * it must neither null a stored artifact because the flag is off today
     * nor rewrite who created the version.
     */
    public function test_a_forced_reembed_never_nulls_the_artifact_pointer_nor_rewrites_the_actor(): void
    {
        config(['kb.conversion_artifacts.enabled' => true]);
        $markdown = "# Keep\n\nBody.";
        $doc = $this->ingestMarkdown($markdown, 'docs/keep.md', ['version_actor' => 'user:42']);
        $path = (string) $doc->markdown_path;
        $doc->update(['version_actor' => 'user:7', 'version_reason' => 'restore of #1']);

        config(['kb.conversion_artifacts.enabled' => false]);
        $again = app(DocumentIngestor::class)->ingest('eng', new SourceDocument(
            sourcePath: 'docs/keep.md', mimeType: 'text/markdown', bytes: $markdown,
            externalUrl: null, externalId: null, connectorType: 'local', metadata: [],
        ), 'Note', forceReembed: true);

        $this->assertSame($doc->id, $again->id);
        $this->assertSame($path, $again->markdown_path);
        $this->assertSame($doc->content_hash, $again->content_hash);
        $this->assertSame('user:7', $again->version_actor);
        $this->assertSame('restore of #1', $again->version_reason);
        Storage::disk('kb')->assertExists($path);
    }

    public function test_the_version_actor_defaults_to_system_ingest_and_the_reason_is_bounded(): void
    {
        config(['kb.conversion_artifacts.enabled' => true]);

        $doc = $this->ingestMarkdown("# Actor\n\nBody.", 'docs/actor.md', ['version_reason' => str_repeat('r', 2000)]);

        $this->assertSame('system:ingest', $doc->version_actor);
        $this->assertSame(1024, mb_strlen((string) $doc->version_reason));
    }

    public function test_a_trusted_caller_actor_is_recorded_as_given(): void
    {
        $doc = $this->ingestMarkdown("# Actor\n\nBody.", 'docs/actor2.md', ['version_actor' => 'user:42', 'version_reason' => 'manual re-ingest']);

        $this->assertSame('user:42', $doc->version_actor);
        $this->assertSame('manual re-ingest', $doc->version_reason);
    }
}
