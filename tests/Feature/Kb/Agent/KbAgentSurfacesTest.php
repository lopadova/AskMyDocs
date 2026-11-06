<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\Agent;

use App\Mcp\Tools\KbDocumentBySlugTool;
use App\Mcp\Tools\KbFacetsTool;
use App\Mcp\Tools\KbReadChunkTool;
use App\Mcp\Tools\KbReadDocumentTool;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Services\Kb\Agent\KbFacetsService;
use App\Services\Kb\Agent\SimilarDocumentFinder;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Mcp\Request;
use Tests\Concerns\SeedsKbDocuments;
use Tests\TestCase;

/**
 * v8.44/W4 (ADR 0035 §D6.1-3) — the agent affordances on every surface
 * (HTTP / CLI / MCP) and in both flag states (R43).
 */
final class KbAgentSurfacesTest extends TestCase
{
    use RefreshDatabase;
    use SeedsKbDocuments;

    private const TENANT = 'ag-tenant';

    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->group(__DIR__.'/../../../../routes/api.php');
    }

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(self::TENANT);
        $this->seed(RbacSeeder::class);
    }

    private function user(string $role = 'viewer'): User
    {
        $u = User::create(['name' => 'U', 'email' => $role.'-'.uniqid().'@example.test', 'password' => Hash::make('secret-secret')])->fresh();
        $u->assignRole($role);
        ProjectMembership::create(['tenant_id' => self::TENANT, 'user_id' => $u->id, 'project_key' => 'hr', 'role' => 'member']);

        return $u;
    }

    // ── facets ───────────────────────────────────────────────────────────

    public function test_facets_http_is_a_clean_404_when_off_and_the_documented_shape_when_on(): void
    {
        $this->seedDoc(self::TENANT, 'hr', 'policies/a.md');
        $viewer = $this->user();

        config(['kb.facets.enabled' => false]);
        $this->actingAs($viewer)->getJson('/api/kb/facets')->assertStatus(404)->assertJsonPath('code', 'facets_disabled');

        config(['kb.facets.enabled' => true]);
        $this->actingAs($viewer)->getJson('/api/kb/facets')->assertOk()
            ->assertJsonStructure(['document_count', 'source_types', 'canonical_types', 'canonical_statuses', 'languages', 'tags', 'folders', 'source_updated_at' => ['min', 'max']])
            ->assertJsonPath('document_count', 1);
    }

    public function test_facets_http_validates_and_rejects_guests(): void
    {
        config(['kb.facets.enabled' => true]);

        $this->getJson('/api/kb/facets')->assertUnauthorized();
        $this->actingAs($this->user())->getJson('/api/kb/facets?folder_depth=9')->assertStatus(422)->assertJsonValidationErrors('folder_depth');
    }

    public function test_facets_http_counts_only_what_the_caller_can_read(): void
    {
        config(['kb.facets.enabled' => true, 'kb.project_isolation.enabled' => true]);
        $this->seedDoc(self::TENANT, 'hr', 'policies/a.md');
        $this->seedDoc(self::TENANT, 'finance', 'payroll/b.md');

        $this->actingAs($this->user())->getJson('/api/kb/facets')->assertOk()->assertJsonPath('document_count', 1);
    }

    public function test_facets_mcp_off_and_on(): void
    {
        $this->seedDoc(self::TENANT, 'hr', 'policies/a.md');
        $call = function (array $args = []): array {
            $r = (new KbFacetsTool())->handle(new Request($args), app(KbFacetsService::class), app(TenantContext::class));

            return json_decode((string) $r->content(), true, flags: JSON_THROW_ON_ERROR);
        };

        config(['kb.facets.enabled' => false]);
        $this->assertSame(['disabled' => true, 'flag' => 'KB_MCP_FACETS_ENABLED'], $call());

        config(['kb.facets.enabled' => true]);
        $this->assertSame(1, $call()['document_count']);
        $this->assertSame(0, $call(['project_key' => 'nope'])['document_count']);
    }

    public function test_facets_cli_json_works_regardless_of_the_flag_and_restores_the_tenant(): void
    {
        config(['kb.facets.enabled' => false]);
        $this->seedDoc('cli-tenant', 'hr', 'policies/a.md');

        $this->artisan('kb:facets', ['--tenant' => 'cli-tenant', '--json' => true])->expectsOutputToContain('"document_count": 1')->assertExitCode(0);
        $this->assertSame(self::TENANT, app(TenantContext::class)->current());
    }

    // ── line ranges ──────────────────────────────────────────────────────

    public function test_read_document_tool_is_unchanged_without_a_range_and_slices_with_one(): void
    {
        $doc = $this->seedDoc(self::TENANT, 'hr', 'a.md');
        $this->seedChunk($doc, 0, "l1\nl2\nl3");
        $read = function (array $args): array {
            $r = (new KbReadDocumentTool())->handle(new Request($args), app(\App\Services\Kb\Agent\DocumentLineReader::class));

            return json_decode((string) $r->content(), true, flags: JSON_THROW_ON_ERROR);
        };

        $whole = $read(['document_id' => $doc->id]);
        $this->assertArrayHasKey('chunks', $whole['document']);
        $this->assertArrayNotHasKey('lines', $whole, 'R27: the default payload is exactly what it was');

        $ranged = $read(['document_id' => $doc->id, 'from_line' => 2, 'max_lines' => 1]);
        $this->assertSame('l2', $ranged['lines']['content']);
        $this->assertSame(3, $ranged['lines']['total_lines']);
        $this->assertArrayNotHasKey('chunks', $ranged['document']);
    }

    public function test_read_document_tool_cannot_read_another_tenants_document_by_range(): void
    {
        $foreign = $this->seedDoc('someone-else', 'hr', 'f.md');
        $this->seedChunk($foreign, 0, 'secret');

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        (new KbReadDocumentTool())->handle(new Request(['document_id' => $foreign->id, 'from_line' => 1]), app(\App\Services\Kb\Agent\DocumentLineReader::class));
    }

    public function test_read_chunk_tool_reports_the_lines_the_chunk_occupies(): void
    {
        $doc = $this->seedDoc(self::TENANT, 'hr', 'a.md');
        $this->seedChunk($doc, 0, "a\nb\nc");
        $second = $this->seedChunk($doc, 1, "d\ne");

        $r = (new KbReadChunkTool())->handle(new Request(['chunk_id' => $second->id]));
        $chunk = json_decode((string) $r->content(), true, flags: JSON_THROW_ON_ERROR)['chunk'];

        $this->assertSame(5, $chunk['start_line']);
        $this->assertSame(6, $chunk['end_line']);
    }

    public function test_preview_endpoint_is_unchanged_by_default_and_slices_with_params(): void
    {
        $doc = $this->seedDoc(self::TENANT, 'hr', 'a.md');
        $this->seedChunk($doc, 0, "l1\nl2\nl3\nl4");
        $viewer = $this->user();

        $plain = $this->actingAs($viewer)->getJson("/api/kb/documents/{$doc->id}/preview")->assertOk();
        $this->assertSame("l1\nl2\nl3\nl4", $plain->json('content'));
        $this->assertNull($plain->json('lines'), 'R27');

        $ranged = $this->actingAs($viewer)->getJson("/api/kb/documents/{$doc->id}/preview?from_line=2&max_lines=2")->assertOk();
        $this->assertSame("l2\nl3", $ranged->json('content'));
        $this->assertSame(['from_line' => 2, 'to_line' => 3, 'total_lines' => 4, 'truncated' => true], $ranged->json('lines'));

        $this->actingAs($viewer)->getJson("/api/kb/documents/{$doc->id}/preview?from_line=0")->assertStatus(422);

        // A ranged read loads the document text once (through the reader), never
        // the full-content query as well.
        $textReads = 0;
        \Illuminate\Support\Facades\DB::listen(function ($q) use (&$textReads): void {
            if (str_contains($q->sql, '"chunk_text"') && str_contains($q->sql, 'from "knowledge_chunks"')) {
                $textReads++;
            }
        });
        $this->actingAs($viewer)->getJson("/api/kb/documents/{$doc->id}/preview?from_line=1&max_lines=1")->assertOk();
        $this->assertSame(1, $textReads, 'one read of the document text per ranged request');
    }

    public function test_kb_show_prints_a_range_by_id_slug_or_path_and_suggests_on_a_miss(): void
    {
        $doc = $this->seedDoc(self::TENANT, 'hr', 'policies/refund-policy.md', ['slug' => 'refund-policy']);
        $this->seedChunk($doc, 0, "one\ntwo\nthree");

        $this->artisan('kb:show', ['document' => (string) $doc->id, '--tenant' => self::TENANT, '--from' => 2, '--lines' => 1])
            ->expectsOutputToContain('lines 2-2 of 3')->assertExitCode(0);
        $this->artisan('kb:show', ['document' => 'refund-policy', '--project' => 'hr', '--tenant' => self::TENANT, '--json' => true])
            ->expectsOutputToContain('"total_lines": 3')->assertExitCode(0);
        $this->artisan('kb:show', ['document' => 'policies/refund-policy.md', '--tenant' => self::TENANT])->assertExitCode(0);
        $this->artisan('kb:show', ['document' => 'refund-polcy', '--tenant' => self::TENANT])
            ->expectsOutputToContain('Did you mean')->assertExitCode(1);
        $this->artisan('kb:show', ['document' => 'zzzzzzzz-nothing-like-it', '--tenant' => self::TENANT])->assertExitCode(1);
    }

    // ── did you mean ────────────────────────────────────────────────────

    public function test_slug_tool_suggests_on_a_miss_and_stays_silent_on_a_hit(): void
    {
        $this->seedDoc(self::TENANT, 'hr', 'policies/refund-policy.md', ['slug' => 'refund-policy', 'is_canonical' => true, 'canonical_type' => 'standard', 'canonical_status' => 'accepted']);
        $call = function (string $slug): array {
            $r = (new KbDocumentBySlugTool())->handle(new Request(['slug' => $slug, 'project_key' => 'hr']), app(SimilarDocumentFinder::class));

            return json_decode((string) $r->content(), true, flags: JSON_THROW_ON_ERROR);
        };

        $miss = $call('refund-polcy');
        $this->assertSame('not_found', $miss['error']);
        $this->assertSame('refund-policy', $miss['suggestions'][0]['slug']);

        $hit = $call('refund-policy');
        $this->assertArrayNotHasKey('error', $hit);
        $this->assertArrayNotHasKey('suggestions', $hit);

        $this->assertSame([], $call('qqqqqqqqqq')['suggestions'], 'nothing close → an empty list, not an error');
    }
}
