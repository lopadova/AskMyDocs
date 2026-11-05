<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\Agent;

use App\Mcp\Tools\KbMultiGetTool;
use App\Mcp\Tools\KbReadDocumentTool;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Services\Kb\Agent\AmbiguousDocumentReference;
use App\Services\Kb\Agent\DocumentBatchReader;
use App\Services\Kb\Agent\DocumentReferenceResolver;
use App\Support\Http\AllowListedEndpoint;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Tests\TestCase;

/**
 * W6 (qmd parity) — `multi_get` and `#docid` short references across the
 * service, CLI, HTTP and MCP surfaces (R44), tenant-scoped (R30), bounded and
 * explicit about truncation (R14), and clean in both flag states (R43).
 */
final class MultiGetAndReferencesTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 'mg-tenant';

    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->group(__DIR__.'/../../../../routes/api.php');
    }

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(self::TENANT);
        $this->seed(RbacSeeder::class);
        config(['kb.multi_get.enabled' => true]);
    }

    private function doc(string $path, string $text, string $hash, string $tenant = self::TENANT, string $project = 'hr', string $status = 'indexed'): KnowledgeDocument
    {
        $doc = KnowledgeDocument::query()->create([
            'tenant_id' => $tenant,
            'project_key' => $project,
            'source_type' => 'markdown',
            'title' => basename($path),
            'source_path' => $path,
            'mime_type' => 'text/markdown',
            'language' => 'en',
            'access_scope' => 'internal',
            'status' => $status,
            'document_hash' => $hash,
            'version_hash' => hash('sha256', $path.$tenant.$status),
            'metadata' => [],
        ]);
        KnowledgeChunk::query()->create([
            'tenant_id' => $tenant,
            'knowledge_document_id' => $doc->id,
            'project_key' => $project,
            'chunk_order' => 0,
            'chunk_hash' => hash('sha256', $text),
            'heading_path' => basename($path),
            'chunk_text' => $text,
            'metadata' => [],
        ]);

        return $doc;
    }

    private function seedDocs(): void
    {
        $this->doc('policies/leave.md', "Leave policy\nline two", str_repeat('a', 63).'1');
        $this->doc('policies/remote.md', 'Remote work policy', 'abcdef01'.str_repeat('0', 56));
        $this->doc('policies/sub/deep.md', 'Deep file', 'abcdef02'.str_repeat('0', 56));
        $this->doc('runbooks/oncall.md', 'On-call runbook', str_repeat('c', 64));
        $this->doc('policies/old.md', 'Archived version', str_repeat('d', 64), status: 'archived');
        $this->doc('policies/leave.md', 'OTHER TENANT leave policy', str_repeat('e', 64), tenant: 'other-tenant');
    }

    private function reader(): DocumentBatchReader
    {
        return app(DocumentBatchReader::class);
    }

    // ── references ───────────────────────────────────────────────────────

    public function test_reference_parse_rules(): void
    {
        $this->assertSame('abcdef', DocumentReferenceResolver::parse('#abcdef'));
        $this->assertSame('abcdef', DocumentReferenceResolver::parse('#ABCDEF'));
        $this->assertNull(DocumentReferenceResolver::parse('#abc'), 'too short');
        $this->assertNull(DocumentReferenceResolver::parse('#xyz123'), 'not hex');
        $this->assertNull(DocumentReferenceResolver::parse('policies/leave.md'));
        $this->assertNull(DocumentReferenceResolver::parse('123456789012345'), 'a numeric id is never a reference');
        $this->assertSame('abcdef0123456', DocumentReferenceResolver::parse('abcdef0123456'), '12+ hex with a letter, no #');
        $this->assertSame('#'.str_repeat('a', 12), DocumentReferenceResolver::shortRef(str_repeat('a', 64)));
    }

    public function test_reference_resolution_is_tenant_scoped_and_ambiguity_is_explicit(): void
    {
        $this->seedDocs();
        $resolver = app(DocumentReferenceResolver::class);

        $this->assertSame('policies/remote.md', $resolver->resolve(self::TENANT, '#abcdef01')->source_path);
        try {
            $resolver->resolve(self::TENANT, '#abcdef');
            $this->fail('an ambiguous prefix must not pick a row silently');
        } catch (AmbiguousDocumentReference $e) {
            $this->assertCount(2, $e->candidates);
        }
        $this->expectException(\App\Services\Kb\Agent\DocumentReferenceNotFound::class);
        $resolver->resolve(self::TENANT, '#'.str_repeat('e', 12), null);
    }

    // ── batch reader ─────────────────────────────────────────────────────

    public function test_paths_globs_and_refs_resolve_within_the_tenant_and_skip_archived(): void
    {
        $this->seedDocs();
        $out = $this->reader()->read(self::TENANT, ['policies/leave.md', 'policies/*.md', '#'.str_repeat('c', 12)]);

        $paths = array_column($out['documents'], 'source_path');
        $this->assertSame(['policies/leave.md', 'policies/remote.md', 'runbooks/oncall.md'], $paths, 'deduped, glob without crossing /, ref resolved');
        $this->assertNotContains('policies/old.md', $paths, 'archived excluded');
        $this->assertNotContains('OTHER TENANT leave policy', array_column($out['documents'], 'content'), 'R30');
        $this->assertStringContainsString('Leave policy', $out['documents'][0]['content']);
        $this->assertFalse($out['meta']['truncated']);

        $deep = $this->reader()->read(self::TENANT, ['policies/**']);
        $this->assertContains('policies/sub/deep.md', array_column($deep['documents'], 'source_path'), '** crosses folders');
    }

    public function test_misses_are_reported_with_reasons_and_suggestions(): void
    {
        $this->seedDocs();
        $out = $this->reader()->read(self::TENANT, ['policies/leav.md', 'nothing/*.md', '#'.str_repeat('9', 12).'a', '../etc/passwd']);

        $reasons = array_column($out['missing'], 'reason', 'selector');
        $this->assertSame('not_found', $reasons['policies/leav.md']);
        $this->assertSame('no_match', $reasons['nothing/*.md']);
        $this->assertSame('not_found', $reasons['#'.str_repeat('9', 12).'a']);
        $this->assertSame('invalid_path', $reasons['../etc/passwd'], 'traversal is refused by KbPath');
        $suggestions = collect($out['missing'])->firstWhere('selector', 'policies/leav.md')['suggestions'];
        $this->assertSame('policies/leave.md', $suggestions[0]['source_path'] ?? null, 'did you mean');
        $this->assertSame([], $out['documents']);
    }

    public function test_the_byte_budget_truncates_one_document_and_omits_the_rest_explicitly(): void
    {
        $this->seedDocs();
        // leave.md = 21 bytes (fits), remote.md = 18 bytes (cut to 4), oncall.md omitted.
        $out = $this->reader()->read(self::TENANT, ['policies/leave.md', 'policies/remote.md', 'runbooks/oncall.md'], maxBytes: 25);

        $this->assertCount(2, $out['documents']);
        $this->assertSame(25, $out['meta']['bytes_returned']);
        $this->assertSame('Remo', $out['documents'][1]['content']);
        $this->assertSame(18, $out['documents'][1]['bytes'], 'the full size is reported, not the cut one');
        $this->assertFalse($out['documents'][0]['truncated']);
        $this->assertTrue($out['documents'][1]['truncated']);
        $this->assertSame(['runbooks/oncall.md'], array_column($out['omitted'], 'source_path'));
        $this->assertSame(15, $out['omitted'][0]['bytes'], 'size from SQL, content never loaded');
        $this->assertFalse($out['meta']['glob_capped']);
        $this->assertTrue($out['meta']['truncated']);
    }

    public function test_selector_count_is_bounded(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->reader()->read(self::TENANT, array_map(fn ($i) => "p{$i}.md", range(1, 21)));
    }

    // ── surfaces ─────────────────────────────────────────────────────────

    private function viewer(): User
    {
        $u = User::create(['name' => 'V', 'email' => 'v-'.uniqid().'@example.test', 'password' => Hash::make('secret-secret')])->fresh();
        $u->assignRole('viewer');
        ProjectMembership::create(['tenant_id' => self::TENANT, 'user_id' => $u->id, 'project_key' => 'hr', 'role' => 'member']);

        return $u;
    }

    public function test_http_both_flag_states_auth_and_validation(): void
    {
        $this->seedDocs();
        $this->postJson('/api/kb/documents/multi', ['selectors' => ['policies/leave.md']])->assertStatus(401);

        $user = $this->viewer();
        $this->actingAs($user)->postJson('/api/kb/documents/multi', ['selectors' => []])->assertStatus(422);
        $this->actingAs($user)->postJson('/api/kb/documents/multi', ['selectors' => ['policies/leave.md'], 'max_bytes' => 10_000_000])->assertStatus(422);

        $ok = $this->actingAs($user)->postJson('/api/kb/documents/multi', ['selectors' => ['policies/*.md']]);
        $ok->assertOk()->assertJsonStructure(['documents' => [['id', 'ref', 'source_path', 'bytes', 'truncated', 'content']], 'omitted', 'missing', 'meta']);

        config(['kb.multi_get.enabled' => false]);
        $this->actingAs($user)->postJson('/api/kb/documents/multi', ['selectors' => ['policies/leave.md']])
            ->assertStatus(404)->assertJsonPath('code', 'multi_get_disabled');
    }

    public function test_mcp_tool_both_states(): void
    {
        $this->seedDocs();
        $tool = new KbMultiGetTool();
        $on = json_decode((string) $tool->handle(new Request(['selectors' => ['runbooks/*.md']]), $this->reader(), app(TenantContext::class))->content(), true);
        $this->assertSame(['runbooks/oncall.md'], array_column($on['documents'], 'source_path'));

        config(['kb.multi_get.enabled' => false]);
        $off = json_decode((string) $tool->handle(new Request(['selectors' => ['runbooks/*.md']]), $this->reader(), app(TenantContext::class))->content(), true);
        $this->assertSame(['disabled' => true, 'flag' => 'KB_MULTI_GET_ENABLED'], $off);
    }

    public function test_cli_multi_get_and_show_by_reference(): void
    {
        $this->seedDocs();
        $this->assertSame(0, Artisan::call('kb:multi-get', ['selectors' => ['runbooks/*.md'], '--tenant' => self::TENANT, '--json' => true]));
        $this->assertSame(['runbooks/oncall.md'], array_column(json_decode(Artisan::output(), true)['documents'], 'source_path'));
        $this->assertSame(1, Artisan::call('kb:multi-get', ['selectors' => ['nope.md'], '--tenant' => self::TENANT]), 'nothing readable is a failure (R14)');

        $this->assertSame(0, Artisan::call('kb:show', ['document' => '#abcdef01', '--tenant' => self::TENANT, '--json' => true]));
        $this->assertSame('policies/remote.md', json_decode(Artisan::output(), true)['document']['source_path']);
        $this->assertSame(1, Artisan::call('kb:show', ['document' => '#abcdef', '--tenant' => self::TENANT]), 'ambiguous');
        $this->assertStringContainsString('longer prefix', Artisan::output());
    }

    public function test_read_document_tool_accepts_a_reference(): void
    {
        $this->seedDocs();
        $tool = new KbReadDocumentTool();
        $payload = json_decode((string) $tool->handle(new Request(['ref' => '#abcdef01']))->content(), true);
        $this->assertSame('policies/remote.md', $payload['document']['source_path']);
        $this->assertSame('#abcdef010000', $payload['document']['ref']);

        $ambiguous = $tool->handle(new Request(['ref' => '#abcdef']));
        $this->assertTrue($ambiguous->isError(), 'R14: an ambiguous reference is an MCP error, not a success');
        $this->assertStringContainsString('policies/remote.md', (string) $ambiguous->content());
    }

    // ── shared SSRF guard ────────────────────────────────────────────────

    public function test_allow_listed_endpoint(): void
    {
        $this->assertNull(AllowListedEndpoint::violation('https://llm.internal/v1/chat/completions', ['llm.internal'], false));
        $this->assertNull(AllowListedEndpoint::violation('https://llm.internal:8443/v1', ['llm.internal:8443'], false));
        $this->assertSame('endpoint_not_allowed', AllowListedEndpoint::violation('https://llm.internal:9999/v1', ['llm.internal:8443'], false));
        $this->assertSame('endpoint_not_allowed', AllowListedEndpoint::violation('https://evil.llm.internal/v1', ['llm.internal'], false), 'no suffix matching');
        $this->assertSame('endpoint_scheme', AllowListedEndpoint::violation('http://llm.internal/v1', ['llm.internal'], false));
        $this->assertNull(AllowListedEndpoint::violation('http://llm.internal/v1', ['llm.internal'], true));
        $this->assertSame('endpoint_invalid', AllowListedEndpoint::violation('https://u:p@llm.internal/v1', ['llm.internal'], false));
        $this->assertSame('endpoint_invalid', AllowListedEndpoint::violation('https://llm.internal/v1?x=1', ['llm.internal'], false));
        $this->assertSame('endpoint_not_allowed', AllowListedEndpoint::violation('https://llm.internal/v1', [], false), 'empty list allows nothing');
    }
}
