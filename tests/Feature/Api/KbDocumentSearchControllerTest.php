<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Http\Controllers\Api\KbDocumentSearchController;
use App\Models\KnowledgeDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * T2.6 — exercises GET /api/kb/documents/search for the FE @mention
 * autocomplete popover (consumed by T2.7/T2.8).
 *
 * Verifies the title+path substring search, project scope, R19 escape
 * for `_` / `%` / `\` in the query string, response shape, and the
 * 422 validation guards (too-short / over-length).
 */
final class KbDocumentSearchControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Sanctum isn't loaded in the test harness — register the route
        // raw without auth middleware (mirrors the pattern in
        // KbIngestControllerTest::setUp).
        Route::get('/api/kb/documents/search', KbDocumentSearchController::class)
            ->name('api.kb.documents.search');
    }

    public function test_returns_documents_whose_title_contains_the_query_substring(): void
    {
        $this->seedDoc('hr', 'docs/policy-alpha.md', 'Policy Alpha');
        $this->seedDoc('hr', 'docs/policy-beta.md', 'Policy Beta');
        $this->seedDoc('hr', 'docs/runbook.md', 'Runbook Other');

        $resp = $this->getJson('/api/kb/documents/search?q=Policy');

        $resp->assertOk()->assertJsonCount(2, 'data');
        $titles = collect($resp->json('data'))->pluck('title')->sort()->values()->all();
        $this->assertSame(['Policy Alpha', 'Policy Beta'], $titles);
    }

    public function test_search_substring_also_matches_source_path(): void
    {
        $this->seedDoc('hr', 'docs/hidden-policy.md', 'Untitled');

        $resp = $this->getJson('/api/kb/documents/search?q=hidden-policy');

        $resp->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('docs/hidden-policy.md', $resp->json('data.0.source_path'));
    }

    public function test_project_keys_filter_narrows_to_listed_tenants(): void
    {
        $this->seedDoc('proj-A', 'docs/x.md', 'Shared Title');
        $this->seedDoc('proj-B', 'docs/x.md', 'Shared Title');
        $this->seedDoc('proj-C', 'docs/x.md', 'Shared Title');

        $resp = $this->getJson('/api/kb/documents/search?q=Shared&project_keys[]=proj-A&project_keys[]=proj-C');

        $resp->assertOk()->assertJsonCount(2, 'data');
        $projects = collect($resp->json('data'))->pluck('project_key')->sort()->values()->all();
        $this->assertSame(['proj-A', 'proj-C'], $projects);
    }

    public function test_escapes_underscore_per_R19_so_literal_underscore_is_not_a_wildcard(): void
    {
        // R19 invariant: a literal `_` in the query MUST NOT match any
        // single character. Without the escape, `Policy_v2` would match
        // `Policyv2` (and `Policyav2`, etc.). With the escape, only
        // exact-substring matches return.
        $this->seedDoc('hr', 'docs/a.md', 'Policy_v2');
        $this->seedDoc('hr', 'docs/b.md', 'Policyv2');

        $resp = $this->getJson('/api/kb/documents/search?q=Policy_v2');

        $resp->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('Policy_v2', $resp->json('data.0.title'));
    }

    public function test_escapes_percent_per_R19_so_literal_percent_is_not_a_wildcard(): void
    {
        // R19 invariant: literal `%` is NOT a wildcard. Without escape,
        // a query of `100%` would match every title containing `100`
        // followed by anything. With escape, only literal `100%` matches.
        $this->seedDoc('hr', 'docs/a.md', '100% complete');
        $this->seedDoc('hr', 'docs/b.md', '1000 items');

        $resp = $this->getJson('/api/kb/documents/search?q=100%25');  // %25 = '%' URL-encoded

        $resp->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('100% complete', $resp->json('data.0.title'));
    }

    public function test_response_shape_includes_id_project_title_path_and_canonical_meta(): void
    {
        $this->seedDoc('hr', 'docs/x.md', 'Sample', sourceType: 'pdf', canonicalType: 'decision');

        $resp = $this->getJson('/api/kb/documents/search?q=Sample');

        $resp->assertOk()
            ->assertJsonStructure([
                'data' => [
                    ['id', 'project_key', 'title', 'source_path', 'source_type', 'canonical_type'],
                ],
            ])
            ->assertJsonPath('data.0.source_type', 'pdf')
            ->assertJsonPath('data.0.canonical_type', 'decision')
            ->assertJsonPath('data.0.project_key', 'hr');
    }

    public function test_excludes_archived_documents(): void
    {
        $this->seedDoc('hr', 'docs/archived.md', 'Archived Doc', status: 'archived');
        $this->seedDoc('hr', 'docs/live.md', 'Live Doc');

        $resp = $this->getJson('/api/kb/documents/search?q=Doc');

        $resp->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('Live Doc', $resp->json('data.0.title'));
    }

    public function test_caps_results_at_20(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $this->seedDoc('hr', "docs/many-{$i}.md", "Bulk {$i}");
        }

        $resp = $this->getJson('/api/kb/documents/search?q=Bulk');

        $resp->assertOk()->assertJsonCount(20, 'data');
    }

    public function test_rejects_query_too_short_with_422(): void
    {
        $this->getJson('/api/kb/documents/search?q=a')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['q']);
    }

    public function test_rejects_missing_query_with_422(): void
    {
        $this->getJson('/api/kb/documents/search')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['q']);
    }

    public function test_rejects_query_too_long_with_422(): void
    {
        $tooLong = str_repeat('a', 121);
        $this->getJson("/api/kb/documents/search?q={$tooLong}")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['q']);
    }

    public function test_returns_empty_data_array_when_no_match(): void
    {
        $this->seedDoc('hr', 'docs/x.md', 'Existing');

        $resp = $this->getJson('/api/kb/documents/search?q=NonExistentQuery');

        $resp->assertOk()
            ->assertExactJson(['data' => []]);
    }

    private function seedDoc(
        string $projectKey,
        string $sourcePath,
        string $title,
        string $sourceType = 'markdown',
        ?string $canonicalType = null,
        string $status = 'active',
    ): KnowledgeDocument {
        return KnowledgeDocument::create([
            'project_key' => $projectKey,
            'source_type' => $sourceType,
            'title' => $title,
            'source_path' => $sourcePath,
            'mime_type' => 'text/markdown',
            'language' => 'it',
            'access_scope' => 'internal',
            'status' => $status,
            'document_hash' => hash('sha256', $sourcePath . microtime(true) . random_int(0, PHP_INT_MAX)),
            'version_hash' => hash('sha256', $sourcePath . 'v' . microtime(true) . random_int(0, PHP_INT_MAX)),
            'metadata' => [],
            'indexed_at' => now(),
            'is_canonical' => $canonicalType !== null,
            'canonical_type' => $canonicalType,
            'retrieval_priority' => 50,
            'source_of_truth' => true,
        ]);
    }
}
