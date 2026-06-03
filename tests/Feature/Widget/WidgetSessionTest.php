<?php

declare(strict_types=1);

namespace Tests\Feature\Widget;

use App\Models\WidgetKey;
use App\Models\WidgetSession;
use App\Models\WidgetSessionStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * M2 — loop ReAct del widget: /api/widget/sessions/start + step.
 *
 * L'LLM (OpenAI) e gli embeddings sono stubbati via Http::fake (R13: si
 * intercetta solo ciò che esce dal confine app). Copre: risposta groundata,
 * emissione di tool_call validata sullo snapshot, tool_call invalida → blocked,
 * cap snapshot → 422, isolamento sessione per key (R30), continuazione via step.
 */
final class WidgetSessionTest extends TestCase
{
    use RefreshDatabase;

    private function makeKey(array $overrides = []): WidgetKey
    {
        static $n = 0;
        $n++;

        return WidgetKey::create(array_merge([
            'tenant_id' => 'default',
            'project_key' => 'docs-v3',
            'public_key' => 'pk_sess_'.$n,
            'allowed_origins' => ['https://allowed.test'],
            'rate_limit' => 1000,
            'skill' => 'askmydocs-assistant@1',
            'is_active' => true,
            'label' => 'sess-'.$n,
        ], $overrides));
    }

    private function headers(WidgetKey $key): array
    {
        return ['X-Widget-Key' => $key->public_key, 'Origin' => 'https://allowed.test'];
    }

    private function snapshot(array $overrides = []): array
    {
        return array_merge([
            'page' => ['url' => 'https://allowed.test/account', 'title' => 'Account'],
            'regions' => [],
            'fields' => [['name' => 'email', 'label' => 'Email', 'type' => 'text', 'value' => '']],
            'actions' => [['verb' => 'submit', 'label' => 'Salva', 'enabled' => true]],
            'messages' => [],
            'locales_available' => ['it'],
            'page_outline' => ['headings' => [], 'buttons_unannotated' => [], 'inputs_unannotated' => []],
        ], $overrides);
    }

    private function fakeEmbeddings(): \GuzzleHttp\Promise\PromiseInterface
    {
        return Http::response([
            'data' => [['index' => 0, 'embedding' => array_fill(0, 1536, 0.0)]],
            'model' => 'text-embedding-3-small',
            'usage' => ['total_tokens' => 1],
        ], 200);
    }

    private function fakeChatMessage(string $content): \GuzzleHttp\Promise\PromiseInterface
    {
        return Http::response([
            'model' => 'gpt-4o',
            'choices' => [['message' => ['role' => 'assistant', 'content' => $content], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ], 200);
    }

    private function fakeChatToolCall(string $tool, array $args): \GuzzleHttp\Promise\PromiseInterface
    {
        return Http::response([
            'model' => 'gpt-4o',
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => '',
                    'tool_calls' => [[
                        'id' => 'call_1',
                        'type' => 'function',
                        'function' => ['name' => $tool, 'arguments' => json_encode($args)],
                    ]],
                ],
                'finish_reason' => 'tool_calls',
            ]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ], 200);
    }

    public function test_start_returns_a_grounded_answer_when_the_model_replies_with_text(): void
    {
        Http::fake([
            '*/embeddings' => $this->fakeEmbeddings(),
            '*/chat/completions' => $this->fakeChatMessage('Ecco come si configura.'),
        ]);

        $key = $this->makeKey(['project_key' => 'hr-portal']);

        $res = $this->withHeaders($this->headers($key))->postJson('/api/widget/sessions/start', [
            'snapshot' => $this->snapshot(),
            'message' => 'Come configuro il mio account?',
        ]);

        $res->assertOk()
            ->assertJsonPath('type', 'message')
            ->assertJsonPath('answer', 'Ecco come si configura.')
            ->assertJsonPath('session.status', WidgetSession::STATUS_ACTIVE);

        // R30 — sessione scoped alla key (tenant + project dalla key).
        $session = WidgetSession::firstOrFail();
        $this->assertSame('hr-portal', $session->project_key);
        $this->assertSame($key->id, $session->widget_key_id);

        // Persistenza step: messaggio utente + risposta bot.
        $this->assertDatabaseHas('widget_session_steps', ['kind' => WidgetSessionStep::KIND_USER_MESSAGE]);
        $this->assertDatabaseHas('widget_session_steps', ['kind' => WidgetSessionStep::KIND_BOT_MESSAGE]);
    }

    public function test_start_returns_a_validated_tool_call(): void
    {
        Http::fake([
            '*/embeddings' => $this->fakeEmbeddings(),
            '*/chat/completions' => $this->fakeChatToolCall('click', ['target' => 'submit']),
        ]);

        $key = $this->makeKey();

        $res = $this->withHeaders($this->headers($key))->postJson('/api/widget/sessions/start', [
            'snapshot' => $this->snapshot(),
            'message' => 'Salva il modulo',
        ]);

        $res->assertOk()
            ->assertJsonPath('type', 'tool_call')
            ->assertJsonPath('tool_call.tool', 'click')
            ->assertJsonPath('tool_call.args.target', 'submit')
            ->assertJsonPath('tool_call.confirmation_required', false)
            ->assertJsonPath('session.status', WidgetSession::STATUS_WAITING_TOOL);

        $this->assertDatabaseHas('widget_session_steps', [
            'kind' => WidgetSessionStep::KIND_TOOL_CALL,
            'tool' => 'click',
        ]);
    }

    public function test_an_invalid_tool_call_blocks_the_session(): void
    {
        // 'target' non esiste nello snapshot → validator boccia ogni tentativo
        // → raggiunto max_consecutive_errors la sessione va in blocked.
        Http::fake([
            '*/embeddings' => $this->fakeEmbeddings(),
            '*/chat/completions' => $this->fakeChatToolCall('click', ['target' => 'does-not-exist']),
        ]);

        $key = $this->makeKey();

        $res = $this->withHeaders($this->headers($key))->postJson('/api/widget/sessions/start', [
            'snapshot' => $this->snapshot(),
            'message' => 'Clicca un bottone inesistente',
        ]);

        $res->assertOk()->assertJsonPath('type', 'blocked');
        $this->assertSame(WidgetSession::STATUS_BLOCKED, WidgetSession::firstOrFail()->status);
    }

    public function test_oversized_snapshot_is_rejected_with_422(): void
    {
        $key = $this->makeKey();

        $fields = [];
        for ($i = 0; $i < 501; $i++) {
            $fields[] = ['name' => 'f'.$i, 'type' => 'text'];
        }

        $this->withHeaders($this->headers($key))->postJson('/api/widget/sessions/start', [
            'snapshot' => $this->snapshot(['fields' => $fields]),
            'message' => 'ciao',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'snapshot_too_large');
    }

    public function test_a_session_cannot_be_driven_by_another_key(): void
    {
        Http::fake([
            '*/embeddings' => $this->fakeEmbeddings(),
            '*/chat/completions' => $this->fakeChatMessage('ok'),
        ]);

        $keyA = $this->makeKey();
        $keyB = $this->makeKey();

        $start = $this->withHeaders($this->headers($keyA))->postJson('/api/widget/sessions/start', [
            'snapshot' => $this->snapshot(),
            'message' => 'ciao',
        ])->assertOk();

        $sessionId = $start->json('session.id');

        // keyB prova a guidare la sessione di keyA → 404 (anti-IDOR, R30).
        $this->withHeaders($this->headers($keyB))->postJson("/api/widget/sessions/{$sessionId}/step", [
            'snapshot' => $this->snapshot(),
            'message' => 'continua',
        ])->assertNotFound();
    }

    public function test_step_continues_the_session_and_can_complete_it(): void
    {
        $key = $this->makeKey();

        // Un solo fake a closure: Http::fake accoda gli stub e matcha il PRIMO,
        // quindi due fake con lo stesso URL pattern non si sovrascrivono. La
        // closure conta i turni chat e ritorna click (turno 1) poi report_done
        // (turno 2); gli embeddings sono serviti a parte.
        $chatTurn = 0;
        Http::fake(function ($request) use (&$chatTurn) {
            if (str_contains($request->url(), '/embeddings')) {
                return Http::response([
                    'data' => [['index' => 0, 'embedding' => array_fill(0, 1536, 0.0)]],
                    'model' => 'text-embedding-3-small',
                    'usage' => ['total_tokens' => 1],
                ], 200);
            }

            $chatTurn++;
            $tool = $chatTurn === 1 ? 'click' : 'report_done';
            $args = $chatTurn === 1 ? ['target' => 'submit'] : ['summary' => 'Salvato.'];

            return Http::response([
                'model' => 'gpt-4o',
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => '',
                        'tool_calls' => [[
                            'id' => 'call_'.$chatTurn,
                            'type' => 'function',
                            'function' => ['name' => $tool, 'arguments' => json_encode($args)],
                        ]],
                    ],
                    'finish_reason' => 'tool_calls',
                ]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            ], 200);
        });

        // Turno 1: start → tool_call click submit.
        $start = $this->withHeaders($this->headers($key))->postJson('/api/widget/sessions/start', [
            'snapshot' => $this->snapshot(),
            'message' => 'Salva',
        ])->assertOk()->assertJsonPath('tool_call.tool', 'click');
        $sessionId = $start->json('session.id');

        // Turno 2: step con il risultato del tool → il modello chiude (report_done).
        $this->withHeaders($this->headers($key))->postJson("/api/widget/sessions/{$sessionId}/step", [
            'snapshot' => $this->snapshot(),
            'tool_result' => ['ok' => true, 'tool' => 'click', 'diagnostic' => ['actual' => 'clicked']],
        ])
            ->assertOk()
            ->assertJsonPath('type', 'tool_call')
            ->assertJsonPath('tool_call.tool', 'report_done')
            ->assertJsonPath('session.status', WidgetSession::STATUS_COMPLETED);

        $this->assertDatabaseHas('widget_session_steps', ['kind' => WidgetSessionStep::KIND_TOOL_RESULT]);
    }

    // ─── M4: /exec-tool ────────────────────────────────────────────────

    /**
     * M4.12 — /exec-tool happy path: tool BE abilitato → 200 con artifact + 2 step persistiti.
     *
     * Il test usa WidgetAiToolRegistry con SearchKnowledgeBaseTool built-in
     * e stubba ChatRetrievalService per simulare risultati RAG.
     */
    public function test_exec_tool_happy_path_ritorna_artefatto_e_persiste_step(): void
    {
        $key = $this->makeKey();

        // Crea sessione attiva manualmente (nello test usiamo makeKey senza factory)
        $session = WidgetSession::create([
            'tenant_id' => 'default',
            'widget_key_id' => $key->id,
            'project_key' => 'docs-v3',
            'public_session_id' => \Illuminate\Support\Str::uuid()->toString(),
            'status' => WidgetSession::STATUS_WAITING_TOOL,
            'skill' => 'askmydocs-assistant@1',
            'page_url' => 'https://allowed.test/account',
            'origin' => 'https://allowed.test',
        ]);

        // Stubba il ChatRetrievalService nel container per il SearchKnowledgeBaseTool
        $chunk = (object) [
            'id' => 'chunk-1',
            'title' => 'Guida Setup',
            'source' => 'docs',
            'similarity' => 0.92,
            'relevance_score' => null,
            'content' => str_repeat('Contenuto del documento. ', 20),
        ];
        $collection = collect([$chunk]);

        // SearchResult reale — la classe è final, non mockable
        $searchResult = new \App\Services\Kb\Retrieval\SearchResult(
            primary: $collection,
            expanded: collect(),
            rejected: collect(),
        );

        $retrieval = \Mockery::mock(\App\Services\Kb\Chat\ChatRetrievalService::class);
        $retrieval->shouldReceive('retrieve')
            ->once()
            ->andReturn($searchResult);

        // Bind nel container per la risoluzione automatica in SearchKnowledgeBaseTool
        $this->app->instance(\App\Services\Kb\Chat\ChatRetrievalService::class, $retrieval);

        $res = $this->withHeaders($this->headers($key))->postJson(
            "/api/widget/sessions/{$session->public_session_id}/exec-tool",
            ['tool' => 'search_knowledge_base', 'args' => ['query' => 'setup']],
        );

        $res->assertOk()
            ->assertJsonPath('has_results', true)
            ->assertJsonPath('artifact.componentType', 'ui-data-table')
            ->assertJsonPath('interaction_mode', 'selection');

        // Persistenza step: KIND_TOOL_CALL + KIND_TOOL_RESULT
        $this->assertDatabaseHas('widget_session_steps', [
            'widget_session_id' => $session->id,
            'kind' => WidgetSessionStep::KIND_TOOL_CALL,
            'tool' => 'search_knowledge_base',
        ]);
        $this->assertDatabaseHas('widget_session_steps', [
            'widget_session_id' => $session->id,
            'kind' => WidgetSessionStep::KIND_TOOL_RESULT,
            'tool' => 'search_knowledge_base',
        ]);
    }

    /** M4.12 — /exec-tool con tool non abilitato → 422 tool_not_enabled. */
    public function test_exec_tool_rifiuta_tool_non_abilitato(): void
    {
        $key = $this->makeKey();

        $session = WidgetSession::create([
            'tenant_id' => 'default',
            'widget_key_id' => $key->id,
            'project_key' => 'docs-v3',
            'public_session_id' => \Illuminate\Support\Str::uuid()->toString(),
            'status' => WidgetSession::STATUS_WAITING_TOOL,
            'skill' => 'askmydocs-assistant@1',
            'page_url' => 'https://allowed.test/account',
            'origin' => 'https://allowed.test',
        ]);

        // 'fake_tool' non è in tools_enabled del manifest → 422
        $res = $this->withHeaders($this->headers($key))->postJson(
            "/api/widget/sessions/{$session->public_session_id}/exec-tool",
            ['tool' => 'fake_tool', 'args' => []],
        );

        $res->assertStatus(422)
            ->assertJsonPath('error', 'tool_not_enabled');
    }

    /** M4.12 — /exec-tool su sessione chiusa → 409 session_not_active. */
    public function test_exec_tool_rifiuta_sessione_chiusa(): void
    {
        $key = $this->makeKey();

        $session = WidgetSession::create([
            'tenant_id' => 'default',
            'widget_key_id' => $key->id,
            'project_key' => 'docs-v3',
            'public_session_id' => \Illuminate\Support\Str::uuid()->toString(),
            'status' => WidgetSession::STATUS_COMPLETED,
            'skill' => 'askmydocs-assistant@1',
            'page_url' => 'https://allowed.test/account',
            'origin' => 'https://allowed.test',
        ]);

        $res = $this->withHeaders($this->headers($key))->postJson(
            "/api/widget/sessions/{$session->public_session_id}/exec-tool",
            ['tool' => 'search_knowledge_base', 'args' => ['query' => 'test']],
        );

        $res->assertStatus(409)
            ->assertJsonPath('error', 'session_not_active');
    }

    /** M4.12 — /exec-tool has_results=false: query vuota ritorna ui-alert senza risultati. */
    public function test_exec_tool_query_vuota_ritorna_has_results_false(): void
    {
        $key = $this->makeKey();

        $session = WidgetSession::create([
            'tenant_id' => 'default',
            'widget_key_id' => $key->id,
            'project_key' => 'docs-v3',
            'public_session_id' => \Illuminate\Support\Str::uuid()->toString(),
            'status' => WidgetSession::STATUS_WAITING_TOOL,
            'skill' => 'askmydocs-assistant@1',
            'page_url' => 'https://allowed.test/account',
            'origin' => 'https://allowed.test',
        ]);

        // SearchKnowledgeBaseTool con query vuota → non chiama retrieval
        $res = $this->withHeaders($this->headers($key))->postJson(
            "/api/widget/sessions/{$session->public_session_id}/exec-tool",
            ['tool' => 'search_knowledge_base', 'args' => ['query' => '']],
        );

        $res->assertOk()
            ->assertJsonPath('has_results', false)
            ->assertJsonPath('artifact.componentType', 'ui-alert')
            ->assertJsonPath('artifact.componentProps.level', 'warning');
    }

    /** M4.12 — isolamento per key: key B non può eseguire exec-tool sulla sessione di key A. */
    public function test_exec_tool_isola_sessione_per_key_anti_idor(): void
    {
        $keyA = $this->makeKey();
        $keyB = $this->makeKey();

        $session = WidgetSession::create([
            'tenant_id' => 'default',
            'widget_key_id' => $keyA->id,
            'project_key' => 'docs-v3',
            'public_session_id' => \Illuminate\Support\Str::uuid()->toString(),
            'status' => WidgetSession::STATUS_WAITING_TOOL,
            'skill' => 'askmydocs-assistant@1',
            'page_url' => 'https://allowed.test/account',
            'origin' => 'https://allowed.test',
        ]);

        // keyB prova a eseguire un tool sulla sessione di keyA → 404
        $res = $this->withHeaders($this->headers($keyB))->postJson(
            "/api/widget/sessions/{$session->public_session_id}/exec-tool",
            ['tool' => 'search_knowledge_base', 'args' => ['query' => 'test']],
        );

        $res->assertNotFound();
    }
}
