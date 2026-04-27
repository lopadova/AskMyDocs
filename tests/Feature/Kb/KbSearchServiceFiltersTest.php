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

    public function test_apply_filters_doc_ids_empty_array_is_true_no_op_not_a_zero_eq_one_clause(): void
    {
        // T2.5 cycle-1 fix: the original test used a binding-count check
        // which would silently pass even if Laravel compiled an unguarded
        // `whereIn('id', [])` into a `0 = 1` always-false predicate (which
        // would incorrectly filter EVERY result, not no-op). The proper
        // assertion is comparing two queries:
        //  (a) one with sourceTypes only
        //  (b) one with sourceTypes + EMPTY docIds
        // The SQL+bindings MUST be identical — proving the empty docIds
        // contributed nothing. As defence-in-depth we also assert the
        // SQL does NOT contain the `0 = 1` / `"id" in ()` patterns that
        // would indicate an unguarded whereIn slipped through.
        $baseline = $this->buildFilteredSql(new RetrievalFilters(
            sourceTypes: ['markdown'],
        ));
        $withEmptyDocIds = $this->buildFilteredSql(new RetrievalFilters(
            sourceTypes: ['markdown'],
            docIds: [],
        ));

        $this->assertSame($baseline['sql'], $withEmptyDocIds['sql']);
        $this->assertSame($baseline['bindings'], $withEmptyDocIds['bindings']);

        // Defence-in-depth: an unguarded `whereIn('id', [])` would compile
        // to one of these (Laravel grammars vary by dialect).
        $this->assertStringNotContainsString('"id" in ()', $withEmptyDocIds['sql']);
        $this->assertStringNotContainsString('0 = 1', $withEmptyDocIds['sql']);
        $this->assertStringNotContainsString('1 = 0', $withEmptyDocIds['sql']);
    }

    public function test_apply_filters_doc_ids_single_value_uses_single_binding(): void
    {
        // T2.5 — single id produces `id in (?)` (NOT `id = ?`); the
        // whereIn shape stays uniform regardless of array size, which
        // simplifies the SQL builder + EXPLAIN plan reasoning.
        $sql = $this->buildFilteredSql(new RetrievalFilters(docIds: [42]));
        $this->assertStringContainsString('"id" in (?)', $sql['sql']);
        $this->assertContains(42, $sql['bindings']);
        $this->assertCount(1, array_filter($sql['bindings'], fn ($v) => $v === 42));
    }

    public function test_apply_filters_doc_ids_combine_with_other_dimensions_in_single_whereHas(): void
    {
        // T2.5 cycle-1 fix: the original test only asserted each
        // whereIn fragment was present SOMEWHERE in the SQL. A refactor
        // that emitted multiple whereHas('document') subqueries (one per
        // document-level dimension) would still satisfy the fragment
        // assertions while violating the intended grouping invariant
        // (which keeps the document-side EXPLAIN plan single-pass).
        // Proper assertion: count `select * from "knowledge_documents"`
        // occurrences — exactly ONE subquery should hold ALL the
        // document-level constraints together.
        $sql = $this->buildFilteredSql(new RetrievalFilters(
            docIds: [1, 2, 3],
            sourceTypes: ['pdf'],
            languages: ['en'],
        ));

        $this->assertStringContainsString('"id" in (?, ?, ?)', $sql['sql']);
        $this->assertStringContainsString('"source_type" in (?)', $sql['sql']);
        $this->assertStringContainsString('"language" in (?)', $sql['sql']);
        foreach ([1, 2, 3, 'pdf', 'en'] as $expected) {
            $this->assertContains($expected, $sql['bindings']);
        }

        // Document-level subquery count: exactly ONE for the combined
        // dimensions (NOT one per dimension). The pattern Eloquent emits
        // for whereHas('document', fn) is `exists (select * from
        // "knowledge_documents" ...)` — counting the literal substring
        // is fine here because the SQL is fully deterministic at this
        // layer.
        $subqueryCount = substr_count(
            $sql['sql'],
            'select * from "knowledge_documents"',
        );
        $this->assertSame(1, $subqueryCount, 'expected document-level filters grouped in a SINGLE whereHas subquery');
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

    public function test_filterByFolderGlobs_returns_input_unchanged_for_empty_globs(): void
    {
        // Coverage gap fix from T2.4 cycle-1: the filter step must be
        // explicit and testable. With no globs, the input collection
        // passes through unchanged.
        $chunks = collect(['a', 'b', 'c']);
        $this->assertSame(
            $chunks->all(),
            $this->svc->filterByFolderGlobs($chunks, [])->all(),
        );
    }

    public function test_filterByFolderGlobs_keeps_only_chunks_whose_document_path_matches_a_glob(): void
    {
        // Build fake chunks via stdClass with nested document.source_path
        // so we don't need to run pgvector SQL.
        $mk = function (string $path) {
            $chunk = new \stdClass();
            $chunk->document = new \stdClass();
            $chunk->document->source_path = $path;
            return $chunk;
        };
        $chunks = collect([
            $mk('hr/policies/leave.md'),
            $mk('engineering/runbook.md'),
            $mk('hr/policies/inner/onboarding.md'),
        ]);

        $filtered = $this->svc->filterByFolderGlobs($chunks, ['hr/policies/*']);

        // `hr/policies/*` matches one segment only — `inner/onboarding.md`
        // crosses a segment boundary, so it's excluded.
        $paths = $filtered->map(fn ($c) => $c->document->source_path)->all();
        $this->assertSame(['hr/policies/leave.md'], $paths);
    }

    public function test_filterByFolderGlobs_double_star_crosses_segments(): void
    {
        $mk = function (string $path) {
            $chunk = new \stdClass();
            $chunk->document = new \stdClass();
            $chunk->document->source_path = $path;
            return $chunk;
        };
        $chunks = collect([
            $mk('hr/policies/leave.md'),
            $mk('hr/policies/inner/onboarding.md'),
            $mk('engineering/runbook.md'),
        ]);

        $filtered = $this->svc->filterByFolderGlobs($chunks, ['hr/policies/**']);

        $paths = $filtered->map(fn ($c) => $c->document->source_path)->sort()->values()->all();
        $this->assertSame(
            ['hr/policies/inner/onboarding.md', 'hr/policies/leave.md'],
            $paths,
        );
    }

    public function test_filterByFolderGlobs_drops_chunks_with_null_document(): void
    {
        // Defensive: orphaned chunks (document deleted but chunk lingered)
        // shouldn't blow up; the filter excludes them.
        $orphan = new \stdClass();
        $orphan->document = null;

        $valid = new \stdClass();
        $valid->document = new \stdClass();
        $valid->document->source_path = 'a/b.md';

        $chunks = collect([$orphan, $valid]);

        $filtered = $this->svc->filterByFolderGlobs($chunks, ['a/*']);

        $this->assertCount(1, $filtered);
        $this->assertSame('a/b.md', $filtered->first()->document->source_path);
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
