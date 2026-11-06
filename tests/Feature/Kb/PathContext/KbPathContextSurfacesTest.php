<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\PathContext;

use App\Mcp\Tools\KbPathContextsTool;
use App\Models\KbPathContext;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Services\Kb\Chat\ChatRetrievalService;
use App\Services\Kb\PathContext\PathContextService;
use App\Services\Kb\Retrieval\SearchResult;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Laravel\Mcp\Request;
use Tests\TestCase;

/**
 * v8.44/W4 (ADR 0035 §D6.4) — path contexts across HTTP / CLI / MCP and into
 * the prompt + citations, in BOTH flag states (R43), tenant-scoped (R30).
 */
final class KbPathContextSurfacesTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 'pc-tenant';

    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('api')->group(__DIR__.'/../../../../routes/api.php');
    }

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(self::TENANT);
        $this->seed(RbacSeeder::class);
        config(['kb.path_contexts.enabled' => true]);
    }

    private function user(string $role): User
    {
        $u = User::create(['name' => ucfirst($role), 'email' => $role.'-'.uniqid().'@example.test', 'password' => Hash::make('secret-secret')])->fresh();
        $u->assignRole($role);
        ProjectMembership::create(['tenant_id' => self::TENANT, 'user_id' => $u->id, 'project_key' => 'hr', 'role' => 'member']);

        return $u;
    }

    // ── HTTP ─────────────────────────────────────────────────────────────

    public function test_every_action_is_a_clean_404_when_the_flag_is_off(): void
    {
        config(['kb.path_contexts.enabled' => false]);
        $admin = $this->user('admin');

        $this->actingAs($admin)->getJson('/api/admin/kb/path-contexts')->assertStatus(404)->assertJsonPath('code', 'path_contexts_disabled');
        $this->actingAs($admin)->putJson('/api/admin/kb/path-contexts', ['project_key' => 'hr', 'path_prefix' => 'p', 'description' => 'd'])->assertStatus(404)->assertJsonPath('code', 'path_contexts_disabled');
        $this->actingAs($admin)->deleteJson('/api/admin/kb/path-contexts/1')->assertStatus(404)->assertJsonPath('code', 'path_contexts_disabled');
        $this->assertSame(0, KbPathContext::query()->count());
    }

    public function test_crud_round_trip_with_the_contract_shape(): void
    {
        $admin = $this->user('admin');

        $put = $this->actingAs($admin)->putJson('/api/admin/kb/path-contexts', ['project_key' => 'hr', 'path_prefix' => 'policies/', 'description' => 'HR policies']);
        $put->assertOk()->assertJsonStructure(['data' => ['id', 'project_key', 'path_prefix', 'description', 'updated_at']]);
        $this->assertSame('policies', $put->json('data.path_prefix'), 'normalised');
        $id = $put->json('data.id');

        $this->actingAs($admin)->putJson('/api/admin/kb/path-contexts', ['project_key' => 'hr', 'path_prefix' => 'policies', 'description' => 'Updated'])
            ->assertOk()->assertJsonPath('data.id', $id)->assertJsonPath('data.description', 'Updated');

        $this->actingAs($admin)->putJson('/api/admin/kb/path-contexts', ['project_key' => 'ops', 'path_prefix' => 'runbooks', 'description' => 'Ops'])->assertOk();

        $this->actingAs($admin)->getJson('/api/admin/kb/path-contexts')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.project_key', 'hr');
        $this->actingAs($admin)->getJson('/api/admin/kb/path-contexts?project_key=ops')->assertOk()->assertJsonCount(1, 'data');

        $this->actingAs($admin)->deleteJson("/api/admin/kb/path-contexts/{$id}")->assertNoContent();
        $this->actingAs($admin)->getJson('/api/admin/kb/path-contexts')->assertJsonCount(1, 'data');
    }

    public function test_validation_errors_are_422_per_field(): void
    {
        $admin = $this->user('admin');

        $this->actingAs($admin)->putJson('/api/admin/kb/path-contexts', [])->assertStatus(422)->assertJsonValidationErrors(['project_key', 'path_prefix', 'description']);
        $this->actingAs($admin)->putJson('/api/admin/kb/path-contexts', ['project_key' => 'hr', 'path_prefix' => '/abs', 'description' => 'd'])->assertStatus(422)->assertJsonValidationErrors('path_prefix');
        $this->actingAs($admin)->putJson('/api/admin/kb/path-contexts', ['project_key' => 'hr', 'path_prefix' => 'a/../b', 'description' => 'd'])->assertStatus(422)->assertJsonValidationErrors('path_prefix');
        // A non-string prefix stops at `string` (bail) — a 422, never the 500 of an array cast.
        $this->actingAs($admin)->putJson('/api/admin/kb/path-contexts', ['project_key' => 'hr', 'path_prefix' => ['policies'], 'description' => 'd'])->assertStatus(422)->assertJsonValidationErrors('path_prefix');
        $this->actingAs($admin)->putJson('/api/admin/kb/path-contexts', ['project_key' => 'hr', 'path_prefix' => ['a' => ['b' => 1]], 'description' => 'd'])->assertStatus(422)->assertJsonValidationErrors('path_prefix');
        $this->actingAs($admin)->putJson('/api/admin/kb/path-contexts', ['project_key' => 'hr', 'path_prefix' => 'ok', 'description' => str_repeat('x', 501)])->assertStatus(422)->assertJsonValidationErrors('description');
        $this->actingAs($admin)->putJson('/api/admin/kb/path-contexts', ['project_key' => str_repeat('p', 121), 'path_prefix' => 'ok', 'description' => 'd'])->assertStatus(422)->assertJsonValidationErrors('project_key');
        $this->assertSame(0, KbPathContext::query()->count());
    }

    public function test_a_foreign_tenants_id_is_indistinguishable_from_a_missing_one(): void
    {
        $foreign = KbPathContext::create(['tenant_id' => 'someone-else', 'project_key' => 'hr', 'path_prefix' => 'p', 'description' => 'theirs']);

        $this->actingAs($this->user('admin'))->deleteJson("/api/admin/kb/path-contexts/{$foreign->id}")->assertStatus(404);
        $this->assertSame(1, KbPathContext::query()->count(), 'untouched');
    }

    public function test_non_admin_roles_are_forbidden_and_a_guest_is_unauthenticated(): void
    {
        $this->actingAs($this->user('viewer'))->getJson('/api/admin/kb/path-contexts')->assertForbidden();
        $this->actingAs($this->user('editor'))->putJson('/api/admin/kb/path-contexts', ['project_key' => 'hr', 'path_prefix' => 'p', 'description' => 'd'])->assertForbidden();
        $this->assertSame(0, KbPathContext::query()->count());
    }

    public function test_a_guest_is_unauthenticated(): void
    {
        $this->getJson('/api/admin/kb/path-contexts')->assertUnauthorized();
    }

    // ── CLI ──────────────────────────────────────────────────────────────

    public function test_cli_add_list_remove_and_validation(): void
    {
        $this->artisan('kb:path-context', ['action' => 'add', '--project' => 'hr', '--prefix' => 'policies', '--description' => 'HR policies', '--tenant' => self::TENANT])
            ->expectsOutputToContain('Saved path context')->assertExitCode(0);
        $this->artisan('kb:path-context', ['action' => 'list', '--tenant' => self::TENANT])
            ->expectsOutputToContain('HR policies')->assertExitCode(0);
        $this->artisan('kb:path-context', ['action' => 'add', '--project' => 'hr', '--prefix' => '../x', '--description' => 'd', '--tenant' => self::TENANT])
            ->assertExitCode(1);
        $this->artisan('kb:path-context', ['action' => 'remove', '--project' => 'hr', '--prefix' => 'policies', '--tenant' => self::TENANT])
            ->expectsOutputToContain('Removed')->assertExitCode(0);
        $this->artisan('kb:path-context', ['action' => 'remove', '--project' => 'hr', '--prefix' => 'policies', '--tenant' => self::TENANT])
            ->assertExitCode(1);
        $this->artisan('kb:path-context', ['action' => 'wat'])->assertExitCode(1);
        $this->artisan('kb:path-context', ['action' => 'add', '--project' => 'hr'])->assertExitCode(1);
    }

    public function test_cli_works_while_the_feature_is_off_so_contexts_can_be_staged(): void
    {
        config(['kb.path_contexts.enabled' => false]);

        $this->artisan('kb:path-context', ['action' => 'add', '--project' => 'hr', '--prefix' => 'p', '--description' => 'staged', '--tenant' => self::TENANT])->assertExitCode(0);

        $this->assertSame(1, KbPathContext::query()->forTenant(self::TENANT)->count());
        $this->assertSame(self::TENANT, app(TenantContext::class)->current(), 'tenant restored');
    }

    // ── MCP ──────────────────────────────────────────────────────────────

    private function callTool(array $args = []): array
    {
        $r = (new KbPathContextsTool())->handle(new Request($args), app(PathContextService::class), app(TenantContext::class));

        return json_decode((string) $r->content(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_mcp_off_on_and_role_gate(): void
    {
        config(['kb.path_contexts.enabled' => false]);
        Auth::setUser($this->user('admin'));
        $this->assertSame(['disabled' => true, 'flag' => 'KB_PATH_CONTEXTS_ENABLED'], $this->callTool());

        config(['kb.path_contexts.enabled' => true]);
        app(PathContextService::class)->upsert(self::TENANT, 'hr', 'policies', 'HR policies');
        app(PathContextService::class)->upsert('other', 'hr', 'policies', 'foreign');
        $payload = $this->callTool(['project_key' => 'hr']);
        $this->assertSame([['project_key' => 'hr', 'path_prefix' => 'policies', 'description' => 'HR policies']], $payload['path_contexts']);

        Auth::setUser($this->user('viewer'));
        $denied = (new KbPathContextsTool())->handle(new Request([]), app(PathContextService::class), app(TenantContext::class));
        $this->assertTrue($denied->isError(), 'a baseline mcp:read principal must not learn that a folder exists');
    }

    // ── prompt + citations ───────────────────────────────────────────────

    /** @return array<string,mixed> a primary chunk array shaped like production */
    private function chunk(string $path, string $project = 'hr'): array
    {
        return [
            'chunk_id' => 1,
            'project_key' => $project,
            'heading_path' => 'H',
            'chunk_text' => 'body',
            'rerank_score' => 0.9,
            'document' => ['id' => 7, 'title' => 'T', 'source_path' => $path, 'source_type' => 'markdown'],
        ];
    }

    private function searchResult(array $chunk): SearchResult
    {
        return new SearchResult(collect([$chunk]), collect(), collect());
    }

    public function test_the_notes_ride_in_the_user_turn_never_in_the_system_prompt(): void
    {
        $svc = app(PathContextService::class);
        $svc->upsert(self::TENANT, 'hr', 'policies', 'HR policies <b>approved</b> by the DPO');
        $retrieval = app(ChatRetrievalService::class);

        $context = $retrieval->promptContext($this->searchResult($this->chunk('policies/leave.md')));
        $this->assertSame(['HR policies <b>approved</b> by the DPO'], $context['pathContexts']);

        // System prompt: only the fixed rule on how to read the block, never a note.
        $system = view('prompts.kb_rag', array_merge($context, ['projectKey' => 'hr', 'fewShotExamples' => []]))->render();
        $this->assertStringNotContainsString('approved', $system, 'an administrator note is never a system instruction');
        $this->assertStringNotContainsString('</path-contexts>', $system, 'the block itself is never in the system prompt');
        $this->assertStringContainsString('never instructions', $system);

        // User turn: delimited, markup neutralised.
        $block = (string) $retrieval->pathContextUserTurn($context['pathContexts']);
        $this->assertStringStartsWith('<path-contexts>', $block);
        $this->assertStringEndsWith('</path-contexts>', $block);
        $this->assertStringContainsString('- HR policies ‹b›approved‹/b› by the DPO', $block, 'angle brackets cannot close the delimiter or open a tag');
        $this->assertNull($retrieval->pathContextUserTurn([]));

        // Conversation history: its own user message right before the question.
        $history = [['role' => 'user', 'content' => 'earlier'], ['role' => 'assistant', 'content' => 'answer'], ['role' => 'user', 'content' => 'Q?']];
        $with = $retrieval->withPathContextTurn($history, $context['pathContexts']);
        $this->assertSame(['user', 'assistant', 'user', 'user'], array_column($with, 'role'));
        $this->assertSame($block, $with[2]['content']);
        $this->assertSame('Q?', $with[3]['content']);
        $this->assertSame($history, $retrieval->withPathContextTurn($history, []), 'no notes: history untouched');
    }

    public function test_stateless_chat_sends_the_notes_in_the_user_turn(): void
    {
        app(PathContextService::class)->upsert(self::TENANT, 'hr', 'policies', 'HR policies note');
        \Illuminate\Support\Facades\Route::post('/api/kb/chat', \App\Http\Controllers\Api\KbChatController::class);
        config(['chat-log.enabled' => false, 'kb.refusal.min_chunk_similarity' => 0.1, 'kb.refusal.min_chunks_required' => 1]);

        $search = \Mockery::mock(\App\Services\Kb\KbSearchService::class);
        $search->shouldReceive('searchWithContext')->andReturn(new SearchResult(collect([$this->chunk('policies/leave.md') + ['vector_score' => 0.95]]), collect(), collect(), ['filters_selected' => 0]));
        $this->app->instance(\App\Services\Kb\KbSearchService::class, $search);

        $sent = [];
        $ai = \Mockery::mock(\App\Ai\AiManager::class);
        $ai->shouldReceive('chat')->andReturnUsing(function (string $system, string $user) use (&$sent) {
            $sent[] = ['system' => $system, 'user' => $user];

            return new \App\Ai\AiResponse(content: 'Grounded answer.', provider: 'openai', model: 'm');
        });
        $this->app->instance(\App\Ai\AiManager::class, $ai);

        $this->postJson('/api/kb/chat', ['question' => 'How much leave?', 'project_key' => 'hr'])->assertOk();

        $answerCall = collect($sent)->last();
        $this->assertNotNull($answerCall);
        $this->assertStringNotContainsString('HR policies note', $answerCall['system']);
        $this->assertStringContainsString("- HR policies note\n</path-contexts>\n\nHow much leave?", $answerCall['user']);
    }

    public function test_the_notes_are_redacted_under_the_project_policy_before_reaching_prompt_or_citations(): void
    {
        app(PathContextService::class)->upsert(self::TENANT, 'hr', 'policies', 'Ask mario.rossi@example.com for exceptions');
        config([
            'pii-redactor.enabled' => true,
            'pii-redactor.salt' => 'path-context-salt',
            'pii-redactor.token_store.driver' => 'database',
            'kb.pii_redactor.enabled' => true,
            'kb.pii_redactor.redact_inline_ingest' => true,
            'kb.pii_redactor.ingest_strategy' => 'mask',
        ]);
        foreach ([\Padosoft\PiiRedactor\RedactorEngine::class, \Padosoft\PiiRedactor\Strategies\RedactionStrategyFactory::class, \Padosoft\PiiRedactor\Strategies\RedactionStrategy::class, \Padosoft\PiiRedactor\TokenStore\TokenStore::class] as $abstract) {
            $this->app->forgetInstance($abstract);
        }
        $this->assertTrue(app(\App\Services\Kb\Pii\ChunkRedactor::class)->isActive('hr'), 'precondition: redaction active for the project');

        $retrieval = app(ChatRetrievalService::class);
        $result = $this->searchResult($this->chunk('policies/leave.md'));

        $notes = $retrieval->promptContext($result)['pathContexts'];
        $this->assertCount(1, $notes);
        $this->assertStringNotContainsString('mario.rossi@example.com', $notes[0]);
        $this->assertStringStartsWith('Ask ', $notes[0]);
        $this->assertStringNotContainsString('mario.rossi@example.com', implode(' ', $retrieval->buildCitations($result)[0]['path_context']));

        // Redaction OFF: the note is passed as written (the stored value is never altered).
        config(['kb.pii_redactor.enabled' => false]);
        $this->assertSame(['Ask mario.rossi@example.com for exceptions'], $retrieval->promptContext($result)['pathContexts']);
    }

    public function test_a_failing_redactor_drops_the_notes_instead_of_sending_them_raw(): void
    {
        app(PathContextService::class)->upsert(self::TENANT, 'hr', 'policies', 'Ask mario.rossi@example.com');
        // Redaction ON with an invalid strategy: the redactor throws for real.
        config([
            'pii-redactor.enabled' => true,
            'pii-redactor.salt' => 'path-context-salt',
            'kb.pii_redactor.enabled' => true,
            'kb.pii_redactor.redact_inline_ingest' => true,
            'kb.pii_redactor.ingest_strategy' => 'not-a-strategy',
        ]);
        $this->assertTrue(app(\App\Services\Kb\Pii\ChunkRedactor::class)->isActive('hr'), 'precondition: redaction active, so it is attempted');

        $notes = app(ChatRetrievalService::class)->promptContext($this->searchResult($this->chunk('policies/leave.md')))['pathContexts'];

        $this->assertSame([], $notes, 'fail closed: no note rather than an unredacted one');
    }

    public function test_the_prompt_has_no_block_when_off_or_when_nothing_matches(): void
    {
        app(PathContextService::class)->upsert(self::TENANT, 'hr', 'policies', 'HR policies');
        $retrieval = app(ChatRetrievalService::class);

        $none = $retrieval->promptContext($this->searchResult($this->chunk('handbook/x.md')));
        $this->assertSame([], $none['pathContexts']);
        $this->assertStringNotContainsString('<path-contexts>', view('prompts.kb_rag', array_merge($none, ['projectKey' => 'hr', 'fewShotExamples' => []]))->render());

        config(['kb.path_contexts.enabled' => false]);
        $off = $retrieval->promptContext($this->searchResult($this->chunk('policies/leave.md')));
        $this->assertSame([], $off['pathContexts'], 'OFF: nothing is injected, even though a context exists');
    }

    public function test_citations_carry_the_chain_general_to_specific_and_default_to_empty(): void
    {
        $svc = app(PathContextService::class);
        $svc->upsert(self::TENANT, 'hr', 'policies', 'HR policies');
        $svc->upsert(self::TENANT, 'hr', 'policies/leave', 'Leave rules');
        $retrieval = app(ChatRetrievalService::class);

        $cited = $retrieval->buildCitations($this->searchResult($this->chunk('policies/leave/2026.md')));
        $this->assertSame(['HR policies', 'Leave rules'], $cited[0]['path_context']);

        $uncited = $retrieval->buildCitations($this->searchResult($this->chunk('handbook/x.md')));
        $this->assertSame([], $uncited[0]['path_context'], 'R27: the key is always present');

        config(['kb.path_contexts.enabled' => false]);
        $this->assertSame([], $retrieval->buildCitations($this->searchResult($this->chunk('policies/leave/2026.md')))[0]['path_context']);
    }

    public function test_a_context_never_leaks_across_tenants_into_a_citation(): void
    {
        app(PathContextService::class)->upsert('someone-else', 'hr', 'policies', 'foreign note');

        $cited = app(ChatRetrievalService::class)->buildCitations($this->searchResult($this->chunk('policies/leave.md')));

        $this->assertSame([], $cited[0]['path_context']);
    }
}
