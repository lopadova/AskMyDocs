<?php

declare(strict_types=1);

namespace Tests\Feature\Kb;

use App\Ai\EmbeddingsResponse;
use App\Models\KnowledgeChunk;
use App\Services\Kb\EmbeddingCacheService;
use App\Services\Kb\KbSearchService;
use App\Services\Kb\Retrieval\RetrievalFilters;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

/**
 * T2.1 — exercises the new applyFilters() scaffold on KbSearchService.
 *
 * The full search() hot path runs pgvector SQL (`embedding <=> ?::vector`)
 * which SQLite can't parse, so this test focuses on the FILTER LOGIC by:
 *  - constructing a real `KnowledgeChunk::query()` Eloquent builder,
 *  - reflecting into the private `applyFilters()` method,
 *  - asserting the resulting SQL+bindings include the expected
 *    `whereIn` / `whereHas(document, ...)` clauses,
 *  - and verifying `RetrievalFilters::isEmpty()` short-circuits the call
 *    (the search() public path uses isEmpty() to keep the legacy query
 *    plan bit-for-bit identical when no filters are passed).
 *
 * End-to-end retrieval-with-filters tests (real Postgres + pgvector)
 * land in v3.x integration suites; for v3.0 the unit-level SQL
 * inspection is the right granularity.
 */
final class KbSearchServiceFiltersTest extends TestCase
{
    use RefreshDatabase;

    private KbSearchService $svc;

    protected function setUp(): void
    {
        parent::setUp();

        $cache = Mockery::mock(EmbeddingCacheService::class);
        $cache->shouldReceive('generate')->andReturn(new EmbeddingsResponse(
            embeddings: [array_fill(0, 768, 0.0)],
            provider: 'fake',
            model: 'fake-768',
        ));
        $this->app->instance(EmbeddingCacheService::class, $cache);

        $this->svc = app(KbSearchService::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_retrieval_filters_isEmpty_returns_true_for_default_construction(): void
    {
        $f = new RetrievalFilters();
        $this->assertTrue($f->isEmpty());
    }

    public function test_retrieval_filters_isEmpty_returns_false_when_any_dimension_set(): void
    {
        $this->assertFalse((new RetrievalFilters(projectKeys: ['a']))->isEmpty());
        $this->assertFalse((new RetrievalFilters(tagSlugs: ['a']))->isEmpty());
        $this->assertFalse((new RetrievalFilters(sourceTypes: ['markdown']))->isEmpty());
        $this->assertFalse((new RetrievalFilters(canonicalTypes: ['decision']))->isEmpty());
        $this->assertFalse((new RetrievalFilters(connectorTypes: ['local']))->isEmpty());
        $this->assertFalse((new RetrievalFilters(docIds: [1]))->isEmpty());
        $this->assertFalse((new RetrievalFilters(folderGlobs: ['hr/**']))->isEmpty());
        $this->assertFalse((new RetrievalFilters(languages: ['it']))->isEmpty());
        $this->assertFalse((new RetrievalFilters(dateFrom: '2026-01-01'))->isEmpty());
        $this->assertFalse((new RetrievalFilters(dateTo: '2026-12-31'))->isEmpty());
    }

    public function test_for_legacy_project_returns_empty_filters_for_null(): void
    {
        $this->assertTrue(RetrievalFilters::forLegacyProject(null)->isEmpty());
        $this->assertTrue(RetrievalFilters::forLegacyProject('')->isEmpty());
    }

    public function test_for_legacy_project_wraps_single_project_into_projectKeys(): void
    {
        $f = RetrievalFilters::forLegacyProject('proj-A');
        $this->assertSame(['proj-A'], $f->projectKeys);
        $this->assertFalse($f->isEmpty());
    }

    public function test_apply_filters_adds_whereIn_for_project_keys(): void
    {
        $sql = $this->buildFilteredSql(new RetrievalFilters(projectKeys: ['proj-A', 'proj-B']));
        $this->assertStringContainsString('"knowledge_chunks"."project_key" in (?, ?)', $sql['sql']);
        $this->assertContains('proj-A', $sql['bindings']);
        $this->assertContains('proj-B', $sql['bindings']);
    }

    public function test_apply_filters_adds_whereHas_document_for_source_types(): void
    {
        $sql = $this->buildFilteredSql(new RetrievalFilters(sourceTypes: ['pdf', 'docx']));
        $this->assertStringContainsString('"source_type" in (?, ?)', $sql['sql']);
        $this->assertContains('pdf', $sql['bindings']);
        $this->assertContains('docx', $sql['bindings']);
    }

    public function test_apply_filters_adds_whereHas_document_for_canonical_types(): void
    {
        $sql = $this->buildFilteredSql(new RetrievalFilters(canonicalTypes: ['decision', 'runbook']));
        $this->assertStringContainsString('"canonical_type" in (?, ?)', $sql['sql']);
        $this->assertContains('decision', $sql['bindings']);
        $this->assertContains('runbook', $sql['bindings']);
    }

    public function test_apply_filters_adds_whereHas_document_for_doc_ids(): void
    {
        $sql = $this->buildFilteredSql(new RetrievalFilters(docIds: [42, 99]));
        $this->assertStringContainsString('"id" in (?, ?)', $sql['sql']);
        $this->assertContains(42, $sql['bindings']);
        $this->assertContains(99, $sql['bindings']);
    }

    public function test_apply_filters_adds_whereHas_document_for_languages(): void
    {
        $sql = $this->buildFilteredSql(new RetrievalFilters(languages: ['it', 'en']));
        $this->assertStringContainsString('"language" in (?, ?)', $sql['sql']);
        $this->assertContains('it', $sql['bindings']);
        $this->assertContains('en', $sql['bindings']);
    }

    public function test_apply_filters_adds_date_range_constraints(): void
    {
        $sql = $this->buildFilteredSql(new RetrievalFilters(
            dateFrom: '2026-01-01 00:00:00',
            dateTo: '2026-12-31 23:59:59',
        ));
        $this->assertStringContainsString('"indexed_at" >= ?', $sql['sql']);
        $this->assertStringContainsString('"indexed_at" <= ?', $sql['sql']);
        $this->assertContains('2026-01-01 00:00:00', $sql['bindings']);
        $this->assertContains('2026-12-31 23:59:59', $sql['bindings']);
    }

    public function test_apply_filters_combines_multiple_dimensions_in_single_query(): void
    {
        $sql = $this->buildFilteredSql(new RetrievalFilters(
            projectKeys: ['proj-A'],
            sourceTypes: ['pdf'],
            languages: ['en'],
            docIds: [1],
        ));
        // Chunk-level filter for project_keys.
        $this->assertStringContainsString('"knowledge_chunks"."project_key" in (?)', $sql['sql']);
        // Document-level filters bundled into a single whereHas subquery.
        $this->assertStringContainsString('"source_type" in (?)', $sql['sql']);
        $this->assertStringContainsString('"language" in (?)', $sql['sql']);
        $this->assertStringContainsString('"id" in (?)', $sql['sql']);
    }

    public function test_apply_filters_adds_whereExists_join_for_tag_slugs(): void
    {
        // T2.3 — tag filter via whereExists subquery on
        // knowledge_document_tags + kb_tags. Slugs are exact-match
        // (whereIn, not LIKE) so no R19 escape concern.
        $sql = $this->buildFilteredSql(new RetrievalFilters(tagSlugs: ['policy', 'security']));

        $this->assertStringContainsString('exists', $sql['sql']);
        $this->assertStringContainsString('"knowledge_document_tags"', $sql['sql']);
        $this->assertStringContainsString('"kb_tags"', $sql['sql']);
        $this->assertStringContainsString('"kt"."slug" in (?, ?)', $sql['sql']);
        $this->assertContains('policy', $sql['bindings']);
        $this->assertContains('security', $sql['bindings']);
    }

    public function test_apply_filters_tag_subquery_constrains_project_explicitly(): void
    {
        // T2.3 cycle-1 fix: knowledge_document_tags pivot has no
        // project_key FK, so the schema doesn't structurally prevent
        // cross-project tag associations. The subquery must EXPLICITLY
        // constrain `kt.project_key = knowledge_chunks.project_key` so
        // the search is tenant-safe regardless of write-time invariants.
        $sql = $this->buildFilteredSql(new RetrievalFilters(tagSlugs: ['policy']));

        $this->assertStringContainsString('"kt"."project_key" = "knowledge_chunks"."project_key"', $sql['sql']);
    }

    public function test_apply_filters_tag_slug_match_is_exact_not_like(): void
    {
        // R19 documents that LIKE-based filters need %/_/\ escaping. Slug
        // matching uses whereIn (exact match) by DESIGN — verify the SQL
        // does NOT include LIKE so future maintainers don't try to
        // "harden" with unnecessary escaping that would actually break
        // valid slugs containing underscores (e.g. `pre_release`).
        $sql = $this->buildFilteredSql(new RetrievalFilters(tagSlugs: ['pre_release']));

        $this->assertStringNotContainsString(' like ', strtolower($sql['sql']));
        $this->assertStringContainsString('in (?)', $sql['sql']);
        $this->assertContains('pre_release', $sql['bindings']);
    }

    public function test_apply_filters_keeps_folder_globs_as_sql_no_op_filtering_happens_post_fetch(): void
    {
        // T2.4 — folderGlobs are intentionally NOT applied in applyFilters().
        // PostgreSQL has no native fnmatch + `**` doesn't translate to
        // LIKE cleanly, so the filter runs PHP-side AFTER the SQL
        // candidate fetch via KbPath::matchesAnyGlob. Verify the SQL
        // does NOT carry a folder constraint — the post-fetch step
        // owns it.
        $sql = $this->buildFilteredSql(new RetrievalFilters(folderGlobs: ['hr/policies/**']));

        $this->assertStringNotContainsString('source_path', $sql['sql']);
        $this->assertStringNotContainsString('hr/policies', $sql['sql']);
    }

    public function test_apply_filters_does_nothing_for_connector_types_in_t2_4(): void
    {
        // connector_type is the only filter dimension still deferred
        // (no schema column yet — v3.1). T2.3 (tags) and T2.4 (folder
        // globs) are both implemented; only connectorTypes remains
        // a documented no-op.
        $sql = $this->buildFilteredSql(new RetrievalFilters(connectorTypes: ['google-drive']));
        $this->assertStringNotContainsString('connector_type', $sql['sql']);
    }

    /**
     * Construct a KnowledgeChunk::query(), invoke the private applyFilters
     * via reflection, return the resulting SQL + bindings. This is the
     * cleanest way to test query construction without executing pgvector
     * SQL on SQLite.
     *
     * @return array{sql: string, bindings: array<int, mixed>}
     */
    private function buildFilteredSql(RetrievalFilters $filters): array
    {
        $builder = KnowledgeChunk::query();

        $reflection = new ReflectionMethod($this->svc, 'applyFilters');
        $reflection->setAccessible(true);
        $reflection->invoke($this->svc, $builder, $filters);

        return [
            'sql' => $builder->toSql(),
            'bindings' => $builder->getBindings(),
        ];
    }
}
