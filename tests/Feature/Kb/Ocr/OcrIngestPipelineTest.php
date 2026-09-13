<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\Ocr;

use App\Ai\EmbeddingsResponse;
use App\Jobs\IngestDocumentJob;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Services\Kb\DocumentDeleter;
use App\Services\Kb\DocumentIngestor;
use App\Services\Kb\EmbeddingCacheService;
use App\Services\Kb\Ocr\Drivers\FakeOcrDriver;
use App\Services\Kb\Ocr\OcrFigureStore;
use App\Services\Kb\Pipeline\SourceDocument;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Padosoft\LaravelAiFinOps\Models\UsageRecord;
use Padosoft\PiiRedactor\RedactorEngine;
use Padosoft\PiiRedactor\Strategies\RedactionStrategy;
use Padosoft\PiiRedactor\Strategies\RedactionStrategyFactory;
use Padosoft\PiiRedactor\TokenStore\TokenStore;
use RuntimeException;
use Tests\TestCase;

/**
 * v8.36 / ADR 0029 — OCR through the real ingestion core: chunks carry the
 * extraction origin + confidence, PII is redacted before the first embedding
 * (ADR 0020 seam, asserted through the relationship — R33 lesson), FinOps
 * gets one `ocr` row, and the OFF path refuses the image exactly as before.
 */
final class OcrIngestPipelineTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'mario.rossi@example.com';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('kb');
        config([
            'kb.sources.disk' => 'kb',
            'kb.sources.path_prefix' => '',
            'kb.ocr.enabled' => true,
            'kb.ocr.driver' => 'fake',
            'kb.ocr.fake.pages' => null,
            'kb.ocr.rate_per_page' => 0.004,
            'ai-finops.enabled' => true,
            'ai-finops.metering' => true,
        ]);
        $this->app->instance(EmbeddingCacheService::class, $this->fakeEmbeddingCache());
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }

    private function fakeEmbeddingCache(): EmbeddingCacheService
    {
        $cache = Mockery::mock(EmbeddingCacheService::class);
        $cache->shouldReceive('generate')->andReturnUsing(function (array $texts) {
            $embeddings = array_map(static fn () => array_fill(0, 3, 0.1), array_values($texts));

            return new EmbeddingsResponse(embeddings: $embeddings, provider: 'openai', model: 'text-embedding-3-small');
        });

        return $cache;
    }

    private function image(string $path = 'scans/letter.png'): SourceDocument
    {
        return new SourceDocument(
            sourcePath: $path,
            mimeType: 'image/png',
            bytes: (string) base64_decode(FakeOcrDriver::PNG_1X1, true),
            externalUrl: null,
            externalId: null,
            connectorType: 'local',
            metadata: ['disk' => 'kb', 'prefix' => ''],
        );
    }

    public function test_an_image_ingests_into_page_chunks_that_carry_the_extraction_origin_and_confidence(): void
    {
        config(['kb.ocr.fake.pages' => [
            ['markdown' => 'Page one of the letter.', 'confidence' => 0.91, 'figures' => 1],
            ['markdown' => 'Page two of the letter.', 'confidence' => 0.42],
        ]]);

        $document = app(DocumentIngestor::class)->ingest('legal', $this->image(), title: 'Letter');

        $this->assertSame('image', $document->source_type);
        $this->assertSame('image/png', $document->mime_type);
        $this->assertSame('ocr', $document->metadata['converter']['provenance']);
        $this->assertSame('fake', $document->metadata['converter']['ocr']['driver']);
        $this->assertSame(2, $document->metadata['converter']['page_count']);
        // ADR 0028 authorship is untouched by OCR.
        $this->assertNull($document->provenance_tier);
        // ADR 0014 — a machine-read document is born in the auto tier (W3 promotes it).
        $this->assertSame('auto', $document->generation_source);

        // Through the relationship — the rows retrieval reads.
        $chunks = KnowledgeChunk::query()
            ->whereHas('document', fn ($q) => $q->where('id', $document->id))
            ->orderBy('chunk_order')
            ->get();
        $this->assertCount(2, $chunks);
        $this->assertSame('ocr', $chunks[0]->metadata['provenance']);
        $this->assertSame(0.91, $chunks[0]->metadata['ocr_confidence']);
        $this->assertSame(1, $chunks[0]->metadata['page']);
        $this->assertSame(0.42, $chunks[1]->metadata['ocr_confidence']);
        $this->assertSame('Page 2', $chunks[1]->heading_path);
        $this->assertStringContainsString('images/fig-1-1.png', $chunks[0]->chunk_text);
        $run = OcrFigureStore::runKeyFor((string) base64_decode(FakeOcrDriver::PNG_1X1, true), 'fake', 'fake;figures=1');
        Storage::disk('kb')->assertExists("scans/letter.png.ocr/{$run}/images/fig-1-1.png");
    }

    public function test_finops_records_one_ocr_row_priced_per_page(): void
    {
        config(['kb.ocr.fake.pages' => [['markdown' => 'a'], ['markdown' => 'b'], ['markdown' => 'c']]]);

        app(DocumentIngestor::class)->ingest('legal', $this->image('scans/three.png'), title: 'Three');

        $row = UsageRecord::query()->where('purpose_tag', 'ocr')->first();
        $this->assertNotNull($row, 'expected one FinOps ledger row for the OCR run');
        $this->assertSame('ocr', $row->provider);
        $this->assertSame('fake', $row->model);
        $this->assertSame('image', $row->modality);
        $this->assertSame(app(TenantContext::class)->current(), (string) $row->tenant_id);
        $this->assertEqualsWithDelta(0.012, (float) $row->cost_total, 0.0000001);
        $this->assertSame(3, (int) $row->metadata['pages']);
    }

    public function test_pii_on_a_scanned_page_is_redacted_before_the_chunk_is_stored(): void
    {
        config([
            'pii-redactor.enabled' => true,
            'pii-redactor.salt' => 'ocr-test-salt',
            'pii-redactor.token_store.driver' => 'memory',
            'kb.pii_redactor.enabled' => true,
            'kb.pii_redactor.redact_inline_ingest' => true,
            'kb.pii_redactor.ingest_strategy' => 'mask',
            'kb.ocr.fake.pages' => [['markdown' => 'Contact Mario Rossi at '.self::EMAIL.' about the contract.', 'confidence' => 0.8]],
        ]);
        foreach ([RedactorEngine::class, RedactionStrategyFactory::class, RedactionStrategy::class, TokenStore::class] as $abstract) {
            $this->app->forgetInstance($abstract);
        }

        $document = app(DocumentIngestor::class)->ingest('legal', $this->image('scans/pii.png'), title: 'PII');

        $texts = KnowledgeChunk::query()
            ->whereHas('document', fn ($q) => $q->where('id', $document->id))
            ->pluck('chunk_text')
            ->all();
        $this->assertNotEmpty($texts);
        foreach ($texts as $text) {
            $this->assertStringNotContainsString(self::EMAIL, $text);
        }
    }

    public function test_off_an_image_is_refused_before_any_driver_runs(): void
    {
        config(['kb.ocr.enabled' => false]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No converter registered for MIME type: image/png');
        app(DocumentIngestor::class)->ingest('legal', $this->image(), title: 'Letter');
    }

    /**
     * ADR 0029 §6 — a reuse performs no write, so an old run reused by a new
     * ingest must still count as in flight until the new row commits: the
     * reuse refreshes the run's reservation (re-records result.json), and a
     * hard delete inside the grace keeps the run.
     */
    public function test_a_reused_run_is_a_fresh_reservation_again(): void
    {
        $run = OcrFigureStore::runKeyFor((string) base64_decode(FakeOcrDriver::PNG_1X1, true), 'fake', 'fake;figures=1');
        $result = "scans/again2.png.ocr/{$run}/result.json";
        $figure = "scans/again2.png.ocr/{$run}/images/fig-1-1.png";
        $first = app(DocumentIngestor::class)->ingest('legal', $this->image('scans/again2.png'), title: 'Again');
        Storage::disk('kb')->assertExists($result);
        // Age the recorded run well past the in-flight grace (real clock).
        $stale = time() - OcrFigureStore::inFlightGraceSeconds() - 3600;
        touch(Storage::disk('kb')->path($result), $stale);
        touch(Storage::disk('kb')->path($figure), $stale);
        $this->assertLessThan(time() - 60, Storage::disk('kb')->lastModified($result));

        // Same bytes under another tenant: a reuse, not a new run.
        $tenants = app(TenantContext::class);
        $home = $tenants->current();
        $tenants->set('other-tenant');
        try {
            $theirs = app(DocumentIngestor::class)->ingest('legal', $this->image('scans/again2.png'), title: 'Again');
        } finally {
            $tenants->set($home);
        }
        $this->assertTrue((bool) $theirs->metadata['converter']['ocr']['reused']);
        $this->assertSame(1, UsageRecord::query()->where('purpose_tag', 'ocr')->count(), 'a reuse is not metered');
        $this->assertGreaterThan(time() - 60, Storage::disk('kb')->lastModified($result), 'the reuse refreshed the reservation');

        // The first tenant's hard delete cannot purge a run another row references — and
        // even with the reference gate aside, the run is in flight again.
        app(DocumentDeleter::class)->delete($first, force: true);
        Storage::disk('kb')->assertExists($figure);
    }

    public function test_re_ingesting_the_same_scan_is_the_usual_version_hash_no_op(): void
    {
        $first = app(DocumentIngestor::class)->ingest('legal', $this->image(), title: 'Letter');
        $second = app(DocumentIngestor::class)->ingest('legal', $this->image(), title: 'Letter');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, KnowledgeDocument::query()->where('source_path', 'scans/letter.png')->count());
        // R16 — exactly one metered run: the second ingest reused the recorded
        // run and paid nothing (a `>= 1` here would pass on the regression).
        $this->assertSame(1, UsageRecord::query()->where('purpose_tag', 'ocr')->count());
    }

    /**
     * A dry run has no write and no cost side effect: ParseMarkdownStep
     * marks the source, the OCR core neither calls the driver nor writes
     * `.ocr/` nor meters — it previews the page shape instead.
     */
    public function test_a_flow_dry_run_neither_writes_figures_nor_meters_nor_calls_the_driver(): void
    {
        config(['kb.ocr.fake.pages' => [['markdown' => 'real text', 'figures' => 1]]]);
        Storage::disk('kb')->put('scans/dry.png', (string) base64_decode(FakeOcrDriver::PNG_1X1, true));

        $run = \Padosoft\LaravelFlow\Facades\Flow::dryRun(\App\Flow\Definitions\IngestDocumentFlow::NAME, [
            'tenant_id' => app(TenantContext::class)->current(),
            'project_key' => 'legal',
            'source_path' => 'scans/dry.png',
            'disk' => 'kb',
            'title' => 'Dry',
            'metadata' => [],
            'mime_type' => 'image/png',
        ], \Padosoft\LaravelFlow\FlowExecutionOptions::make(idempotencyKey: 'legal:scans/dry.png:dry', correlationId: 'legal'));

        $this->assertSame(\Padosoft\LaravelFlow\FlowRun::STATUS_SUCCEEDED, $run->status, (string) ($run->stepResults["parse-markdown"]->error?->getMessage() ?? json_encode($run->stepResults)));
        $parsed = $run->stepResults['parse-markdown'] ?? $run->stepResults[array_key_first($run->stepResults)];
        $this->assertTrue((bool) ($parsed->output['extraction_meta']['ocr']['dry_run'] ?? false));
        $this->assertStringContainsString('OCR dry run', (string) $parsed->output['markdown']);
        $this->assertStringNotContainsString('real text', (string) $parsed->output['markdown']);
        $this->assertFalse(Storage::disk('kb')->directoryExists('scans/dry.png.ocr'), 'a dry run must not write .ocr/');
        $this->assertSame(0, UsageRecord::query()->where('purpose_tag', 'ocr')->count(), 'a dry run must not meter');
        $this->assertSame(0, KnowledgeDocument::query()->where('source_path', 'scans/dry.png')->count());
    }

    public function test_a_dry_run_shows_a_recorded_run_read_only(): void
    {
        config(['kb.ocr.fake.pages' => [['markdown' => 'recorded text', 'figures' => 1]]]);
        app(DocumentIngestor::class)->ingest('legal', $this->image('scans/seen.png'), title: 'Seen');
        $this->assertSame(1, UsageRecord::query()->where('purpose_tag', 'ocr')->count());
        $before = collect(Storage::disk('kb')->allFiles('scans/seen.png.ocr'))->sort()->values()->all();

        $doc = $this->image('scans/seen.png');
        $preview = app(\App\Services\Kb\Converters\OcrConverter::class)->convert(new SourceDocument(
            sourcePath: $doc->sourcePath,
            mimeType: $doc->mimeType,
            bytes: $doc->bytes,
            externalUrl: null,
            externalId: null,
            connectorType: 'local',
            metadata: ['disk' => 'kb', 'prefix' => '', 'dry_run' => true],
        ));

        $this->assertStringContainsString('recorded text', $preview->markdown);
        $this->assertTrue((bool) $preview->extractionMeta['ocr']['reused']);
        $this->assertTrue((bool) $preview->extractionMeta['ocr']['dry_run']);
        $this->assertSame($before, collect(Storage::disk('kb')->allFiles('scans/seen.png.ocr'))->sort()->values()->all(), 'read-only');
        $this->assertSame(1, UsageRecord::query()->where('purpose_tag', 'ocr')->count(), 'nothing new metered');
    }

    /**
     * The `.ocr/` tree sits beside a source object that is not tenant
     * namespaced, so the deleter's storage-key gate counts referencing rows
     * ACROSS tenants (documented R30 exception): one tenant's hard delete
     * never removes assets another tenant's row still references.
     */
    public function test_hard_delete_keeps_the_ocr_assets_while_another_tenant_references_the_same_source(): void
    {
        $tenants = app(TenantContext::class);
        $home = $tenants->current();
        $mine = app(DocumentIngestor::class)->ingest('legal', $this->image('scans/shared.png'), title: 'Shared');
        $run = OcrFigureStore::runKeyFor((string) base64_decode(FakeOcrDriver::PNG_1X1, true), 'fake', 'fake;figures=1');
        $figure = "scans/shared.png.ocr/{$run}/images/fig-1-1.png";
        Storage::disk('kb')->assertExists($figure);

        $tenants->set('other-tenant');
        try {
            $theirs = app(DocumentIngestor::class)->ingest('legal', $this->image('scans/shared.png'), title: 'Shared');
        } finally {
            $tenants->set($home);
        }
        $this->assertNotSame($mine->id, $theirs->id);
        $this->assertSame('other-tenant', (string) $theirs->tenant_id);

        app(DocumentDeleter::class)->delete($mine, force: true);
        Storage::disk('kb')->assertExists($figure);
        $this->assertSame(0, KnowledgeDocument::withTrashed()->where('id', $mine->id)->count());
    }

    /**
     * ADR 0029 §4 — the `.ocr/` assets share the source file's lifecycle:
     * a soft delete keeps them (retention reversibility), the hard delete
     * of the last referencing row removes the whole directory.
     */
    public function test_hard_delete_purges_the_ocr_assets_and_soft_delete_keeps_them(): void
    {
        $document = app(DocumentIngestor::class)->ingest('legal', $this->image(), title: 'Letter');
        $run = OcrFigureStore::runKeyFor((string) base64_decode(FakeOcrDriver::PNG_1X1, true), 'fake', 'fake;figures=1');
        $figure = "scans/letter.png.ocr/{$run}/images/fig-1-1.png";
        Storage::disk('kb')->assertExists($figure);

        app(DocumentDeleter::class)->delete($document, force: false);
        Storage::disk('kb')->assertExists($figure);

        $trashed = KnowledgeDocument::withTrashed()->findOrFail($document->id);
        // Past the in-flight grace (ADR 0029 §6): nothing can still be about
        // to reference the run, so the last row takes the whole tree with it.
        $this->travel(OcrFigureStore::inFlightGraceSeconds() + 60)->seconds();
        app(DocumentDeleter::class)->delete($trashed, force: true);
        Storage::disk('kb')->assertMissing($figure);
        $this->assertFalse(Storage::disk('kb')->directoryExists('scans/letter.png.ocr'));
    }

    /**
     * ADR 0029 §6 — the run is recorded (and its lock released) before the
     * row that references it commits, so the reference gate has a window in
     * which a concurrent hard delete sees no reference. The run directory is
     * the durable reservation: inside the in-flight grace a hard delete keeps
     * it, and `kb:prune-orphan-files` removes the dangling tree once aged.
     */
    public function test_hard_delete_inside_the_in_flight_grace_keeps_the_run_and_the_orphan_sweep_removes_it_once_aged(): void
    {
        $document = app(DocumentIngestor::class)->ingest('legal', $this->image('scans/race.png'), title: 'Race');
        $run = OcrFigureStore::runKeyFor((string) base64_decode(FakeOcrDriver::PNG_1X1, true), 'fake', 'fake;figures=1');
        $figure = "scans/race.png.ocr/{$run}/images/fig-1-1.png";
        Storage::disk('kb')->assertExists($figure);

        app(DocumentDeleter::class)->delete($document, force: true);
        $this->assertSame(0, KnowledgeDocument::withTrashed()->where('id', $document->id)->count());
        Storage::disk('kb')->assertExists($figure); // a run inside the grace is a reservation, not garbage

        // Still inside the grace: the sweep lists the tree but keeps it.
        $this->artisan('kb:prune-orphan-files')
            ->expectsOutputToContain('dangling_ocr=1 purged=0 in_flight=1')
            ->assertSuccessful();
        Storage::disk('kb')->assertExists($figure);

        $this->travel(OcrFigureStore::inFlightGraceSeconds() + 60)->seconds();
        $this->artisan('kb:prune-orphan-files')
            ->expectsOutputToContain('dangling_ocr=1 purged=1 in_flight=0')
            ->assertSuccessful();
        $this->assertFalse(Storage::disk('kb')->directoryExists('scans/race.png.ocr'));
    }


    /**
     * A forced re-run (kb:ocr / POST …/ocr) whose Markdown is byte-identical
     * to the recorded version is still a NEW, billed run: the row's
     * `converter.ocr.run` and its chunks follow the run that was paid for,
     * instead of being dropped by the same-hash guard while the row keeps
     * pointing at the previous run (Copilot #478 round 3).
     */
    public function test_a_forced_re_run_with_identical_output_replaces_the_recorded_run_on_the_row(): void
    {
        Storage::disk('kb')->put('scans/again.png', (string) base64_decode(FakeOcrDriver::PNG_1X1, true));
        $first = app(DocumentIngestor::class)->ingest('legal', $this->image('scans/again.png'), title: 'Again');
        $firstRun = (string) $first->metadata['converter']['ocr']['run'];
        $firstChunkIds = KnowledgeChunk::query()->where('knowledge_document_id', $first->id)->pluck('id')->all();
        $this->assertNotSame([], $firstChunkIds);
        $this->assertSame(1, UsageRecord::query()->where('purpose_tag', 'ocr')->count());

        Queue::fake();
        $job = new IngestDocumentJob(
            projectKey: 'legal',
            relativePath: 'scans/again.png',
            disk: 'kb',
            title: 'Again',
            metadata: ['ocr' => ['force' => true]],
            mimeType: 'image/png',
            tenantId: app(TenantContext::class)->current(),
            runKey: 'ocr:forced-again',
        );
        $this->app->call([$job, 'handle']);

        $rows = KnowledgeDocument::query()->where('source_path', 'scans/again.png')->get();
        $this->assertCount(1, $rows, 'identical output is the same version, never a second row');
        $row = $rows->first();
        $this->assertSame($first->id, $row->id);
        $this->assertNotSame($firstRun, (string) $row->metadata['converter']['ocr']['run'], 'the row must point at the run that was billed');
        $this->assertFalse((bool) $row->metadata['converter']['ocr']['reused']);
        // R16 — exactly two metered runs: the forced one was paid, not reused.
        $this->assertSame(2, UsageRecord::query()->where('purpose_tag', 'ocr')->count());
        $chunkIds = KnowledgeChunk::query()->where('knowledge_document_id', $row->id)->pluck('id')->all();
        $this->assertSame([], array_intersect($firstChunkIds, $chunkIds), 'chunks are replaced, not kept beside the new set');
        $this->assertCount(count($firstChunkIds), $chunkIds, 'chunks are replaced, not duplicated');
    }
}
