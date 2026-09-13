<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\Versioning;

use App\Ai\EmbeddingsResponse;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Services\Kb\DocumentIngestor;
use App\Services\Kb\EmbeddingCacheService;
use App\Services\Kb\Pipeline\SourceDocument;
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
