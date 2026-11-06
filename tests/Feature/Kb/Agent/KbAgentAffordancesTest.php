<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\Agent;

use App\Models\KbTag;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Services\Kb\Agent\DocumentLineReader;
use App\Services\Kb\Agent\KbFacetsService;
use App\Services\Kb\Agent\SimilarDocumentFinder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\SeedsKbDocuments;
use Tests\TestCase;

/**
 * v8.44/W4 (ADR 0035 §D6.1-3) — facets, line-range reads and "did you mean",
 * with the isolation guarantees that make them safe on an agent surface:
 * tenant (R30) and per-principal document ACL (R33).
 */
final class KbAgentAffordancesTest extends TestCase
{
    use RefreshDatabase;
    use SeedsKbDocuments;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    private function viewerOn(string $tenant, string $project): User
    {
        $u = User::create(['name' => 'V', 'email' => 'v-'.uniqid().'@example.test', 'password' => Hash::make('secret-secret')])->fresh();
        $u->assignRole('viewer');
        ProjectMembership::create(['tenant_id' => $tenant, 'user_id' => $u->id, 'project_key' => $project, 'role' => 'member']);

        return $u;
    }

    // ── facets ───────────────────────────────────────────────────────────

    public function test_facets_count_every_dimension_from_sql(): void
    {
        $a = $this->seedDoc('t1', 'hr', 'policies/leave.md', ['canonical_type' => 'standard', 'canonical_status' => 'accepted', 'is_canonical' => true, 'language' => 'it', 'source_updated_at' => '2026-01-10 10:00:00']);
        $this->seedDoc('t1', 'hr', 'policies/remote.md', ['canonical_type' => 'standard', 'canonical_status' => 'deprecated', 'is_canonical' => true, 'language' => 'it', 'source_updated_at' => '2026-03-01 10:00:00']);
        $this->seedDoc('t1', 'hr', 'handbook/intro.md', ['language' => 'en', 'source_type' => 'pdf']);
        $this->seedDoc('t1', 'hr', 'root.md');
        $tag = KbTag::create(['tenant_id' => 't1', 'project_key' => 'hr', 'slug' => 'leave', 'label' => 'Leave']);
        DB::table('knowledge_document_tags')->insert(['knowledge_document_id' => $a->id, 'kb_tag_id' => $tag->id, 'created_at' => now(), 'updated_at' => now()]);

        $f = app(KbFacetsService::class)->for('t1');

        $this->assertSame(4, $f['document_count']);
        $this->assertSame([['value' => 'markdown', 'count' => 3], ['value' => 'pdf', 'count' => 1]], $f['source_types']);
        $this->assertSame([['value' => 'standard', 'count' => 2]], $f['canonical_types'], 'non-canonical docs are not counted as a type');
        $this->assertEqualsCanonicalizing([['value' => 'accepted', 'count' => 1], ['value' => 'deprecated', 'count' => 1]], $f['canonical_statuses']);
        $this->assertSame([['value' => 'it', 'count' => 2], ['value' => 'en', 'count' => 2]], $this->sortedLangs($f['languages']));
        $this->assertSame([['slug' => 'leave', 'label' => 'Leave', 'count' => 1]], $f['tags']);
        $this->assertSame([['prefix' => 'policies/', 'count' => 2], ['prefix' => 'handbook/', 'count' => 1]], $f['folders'], 'root-level files have no folder');

        // Depth 2 adds the two-level prefixes for paths that have them.
        $this->seedDoc('t1', 'hr', 'policies/2026/q1.md');
        $this->seedDoc('t1', 'hr', 'policies/2026/q2.md');
        $deep = app(KbFacetsService::class)->for('t1', null, 2)['folders'];
        $this->assertContains(['prefix' => 'policies/2026/', 'count' => 2], $deep);
        $this->assertNotContains(['prefix' => 'policies/2026/', 'count' => 2], app(KbFacetsService::class)->for('t1', null, 1)['folders']);
        $this->assertStringStartsWith('2026-01-10', $f['source_updated_at']['min']);
        $this->assertStringStartsWith('2026-03-01', $f['source_updated_at']['max']);
    }

    /** @param list<array{value:string,count:int}> $rows @return list<array{value:string,count:int}> */
    private function sortedLangs(array $rows): array
    {
        usort($rows, fn ($a, $b) => [$b['count'], $a['value'] === 'it' ? 0 : 1] <=> [$a['count'], $b['value'] === 'it' ? 0 : 1]);

        return $rows;
    }

    public function test_facets_exclude_archived_versions_other_tenants_and_respect_the_project_filter(): void
    {
        $this->seedDoc('t1', 'hr', 'a.md');
        $this->seedDoc('t1', 'hr', 'old.md', ['status' => 'archived']);
        $this->seedDoc('t1', 'ops', 'b.md');
        $this->seedDoc('t2', 'hr', 'foreign.md', ['source_type' => 'docx']);

        $this->assertSame(2, app(KbFacetsService::class)->for('t1')['document_count']);
        $this->assertSame(1, app(KbFacetsService::class)->for('t1', 'ops')['document_count']);
        $this->assertSame([['value' => 'docx', 'count' => 1]], app(KbFacetsService::class)->for('t2')['source_types'], 'tenant two sees only its own');
    }

    public function test_facets_never_reveal_a_dimension_only_hidden_documents_carry(): void
    {
        $this->seedDoc('t1', 'hr', 'policies/a.md', ['language' => 'it']);
        $this->seedDoc('t1', 'finance', 'secret-payroll/b.pdf', ['language' => 'de', 'source_type' => 'pdf']);
        // Membership-scoped reads (as in a multi-project deployment): without
        // project isolation every viewer holds `kb.read.any`.
        config(['kb.project_isolation.enabled' => true]);
        app(\App\Support\TenantContext::class)->set('t1');
        $viewer = $this->viewerOn('t1', 'hr');

        $this->actingAs($viewer);
        $seen = app(KbFacetsService::class)->for('t1');

        $this->assertSame(1, $seen['document_count']);
        $this->assertSame([['value' => 'it', 'count' => 1]], $seen['languages'], 'no `de`: the only German doc is hidden');
        $this->assertSame([['value' => 'markdown', 'count' => 1]], $seen['source_types'], 'no `pdf`');
        $this->assertSame([['prefix' => 'policies/', 'count' => 1]], $seen['folders'], '`secret-payroll/` is invisible');
    }

    // ── did you mean ────────────────────────────────────────────────────

    public function test_suggestions_rank_by_distance_and_name_slug_and_path(): void
    {
        $this->seedDoc('t1', 'ops', 'policies/refund-policy.md', ['slug' => 'refund-policy']);
        $this->seedDoc('t1', 'ops', 'policies/refund-policy-2023.md', ['slug' => 'refund-policy-2023']);
        $this->seedDoc('t1', 'ops', 'misc/unrelated.md');

        $out = app(SimilarDocumentFinder::class)->suggest('t1', 'refund-polcy');

        $this->assertSame('refund-policy', $out[0]['slug']);
        $this->assertSame(1, $out[0]['distance']);
        $this->assertNotContains('unrelated', array_column($out, 'title'));
    }

    public function test_suggestions_are_bounded_scoped_and_acl_safe(): void
    {
        $this->seedDoc('t1', 'hr', 'policies/leave-policy.md', ['slug' => 'leave-policy']);
        $this->seedDoc('t1', 'finance', 'payroll/leave-policy-exec.md', ['slug' => 'leave-policy-exec']);
        $this->seedDoc('t2', 'hr', 'policies/leave-policy-t2.md', ['slug' => 'leave-policy-t2']);
        $this->seedDoc('t1', 'hr', 'policies/leave-policy-old.md', ['slug' => 'leave-policy-old', 'status' => 'archived']);

        $this->assertSame(1, count(app(SimilarDocumentFinder::class)->suggest('t1', 'leave-polcy', 'hr', 5)));

        config(['kb.project_isolation.enabled' => true]);
        app(\App\Support\TenantContext::class)->set('t1');
        $this->actingAs($this->viewerOn('t1', 'hr'));
        $paths = array_column(app(SimilarDocumentFinder::class)->suggest('t1', 'leave-polcy'), 'source_path');
        $this->assertSame(['policies/leave-policy.md'], $paths, 'no hidden project, no other tenant, no archived version');
    }

    public function test_like_metacharacters_in_the_needle_cannot_widen_the_candidate_set(): void
    {
        $this->seedDoc('t1', 'ops', 'a/abcdef.md', ['slug' => 'abcdef']);

        foreach (['%%%', '___', '\\\\\\', 'a_c', '%bc%'] as $needle) {
            $this->assertSame([], app(SimilarDocumentFinder::class)->suggest('t1', $needle), "needle [{$needle}] must not match everything");
        }
        $this->assertSame([], app(SimilarDocumentFinder::class)->suggest('t1', 'ab'), 'a needle under 3 characters yields nothing');
    }

    // ── line ranges ──────────────────────────────────────────────────────

    public function test_a_line_range_is_a_bounded_slice_of_the_document_view(): void
    {
        $doc = $this->seedDoc('t1', 'ops', 'long.md');
        $this->seedChunk($doc, 0, "line1\nline2\nline3");
        $this->seedChunk($doc, 1, "line5\nline6");
        $reader = app(DocumentLineReader::class);

        $all = $reader->read($doc);
        $this->assertSame(6, $all['total_lines'], '3 + blank separator + 2');
        $this->assertSame("line1\nline2\nline3\n\nline5\nline6", $all['content']);
        $this->assertFalse($all['truncated']);

        $mid = $reader->read($doc, 2, 3);
        $this->assertSame(['from_line' => 2, 'to_line' => 4, 'total_lines' => 6, 'truncated' => true, 'content' => "line2\nline3\n"], $mid);

        $past = $reader->read($doc, 99);
        $this->assertSame('', $past['content']);
        $this->assertSame(98, $past['to_line']);
    }

    public function test_the_read_is_capped_by_config(): void
    {
        $doc = $this->seedDoc('t1', 'ops', 'big.md');
        $this->seedChunk($doc, 0, implode("\n", range(1, 50)));
        config(['kb.line_read.max_lines' => 10]);

        $r = app(DocumentLineReader::class)->read($doc, 1, 9999);

        $this->assertSame(10, $r['to_line']);
        $this->assertTrue($r['truncated']);
    }

    public function test_a_chunk_reports_the_lines_it_occupies_in_the_same_view(): void
    {
        $doc = $this->seedDoc('t1', 'ops', 'c.md');
        $first = $this->seedChunk($doc, 0, "a\nb\nc");
        $second = $this->seedChunk($doc, 1, "d\ne");
        $reader = app(DocumentLineReader::class);

        $this->assertSame(['start_line' => 1, 'end_line' => 3], $reader->chunkLines($doc, $first->id));
        $this->assertSame(['start_line' => 5, 'end_line' => 6], $reader->chunkLines($doc, $second->id));
        $this->assertNull($reader->chunkLines($doc, 99999));

        // The reported range addresses exactly that chunk's text.
        $range = $reader->chunkLines($doc, $second->id);
        $this->assertSame("d\ne", $reader->read($doc, $range['start_line'], $range['end_line'] - $range['start_line'] + 1)['content']);
    }

    public function test_chunk_lines_match_the_view_for_every_chunk_including_order_ties_without_loading_the_document(): void
    {
        $doc = $this->seedDoc('t1', 'ops', 'ties.md');
        $chunks = [
            $this->seedChunk($doc, 0, "one\ntwo"),
            $this->seedChunk($doc, 1, 'three'),
            // Same chunk_order: the view breaks the tie by id.
            $this->seedChunk($doc, 1, "four\nfive\nsix"),
            $this->seedChunk($doc, 2, ''),
            $this->seedChunk($doc, 3, "seven\n\neight"),
        ];
        $reader = app(DocumentLineReader::class);

        $recording = false;
        $queries = [];
        \Illuminate\Support\Facades\DB::listen(function ($q) use (&$queries, &$recording): void {
            if ($recording) {
                $queries[] = strtolower($q->sql);
            }
        });

        foreach ($chunks as $chunk) {
            $queries = [];
            $recording = true;
            $range = $reader->chunkLines($doc, $chunk->id);
            $recording = false;

            // One bounded target read + one aggregate: the document's text is
            // never hydrated to locate a chunk.
            $this->assertCount(2, $queries);
            $this->assertStringContainsString('limit 1', $queries[0]);
            $this->assertStringContainsString('count(*) as chunks', $queries[1]);

            $this->assertNotNull($range);
            $this->assertSame(
                (string) $chunk->chunk_text,
                $reader->read($doc, $range['start_line'], $range['end_line'] - $range['start_line'] + 1)['content'],
                "chunk {$chunk->id} must address exactly its own text in the view",
            );
        }
    }

    public function test_the_reader_never_reads_another_tenants_chunks_for_a_document(): void
    {
        $mine = $this->seedDoc('t1', 'ops', 'm.md');
        $this->seedChunk($mine, 0, 'mine');
        $foreign = $this->seedDoc('t2', 'ops', 'f.md');
        $this->seedChunk($foreign, 0, 'foreign');

        $this->assertSame('mine', app(DocumentLineReader::class)->read($mine)['content']);
    }
}
