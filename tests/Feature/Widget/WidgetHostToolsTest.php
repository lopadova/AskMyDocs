<?php

declare(strict_types=1);

namespace Tests\Feature\Widget;

use App\Models\WidgetKey;
use App\Models\WidgetSession;
use App\Models\WidgetSessionStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * F1.4 / F1.5 — Host Tool Provider (HTP) end-to-end lato AskMyDocs.
 *
 * L'LLM (OpenAI) e gli embeddings sono stubbati via Http::fake — solo i
 * servizi esterni si intercettano (R13). I test ispezionano il body della
 * richiesta /chat/completions per provare che gli host tools sono (o NON
 * sono) inclusi nella tool list passata al modello, e che una tool_call host
 * torna al FE marcata execution:"host" senza esecuzione server-side. F1.5
 * verifica che lo step() con tool_result.execution=host reinietti l'artifact.
 */
final class WidgetHostToolsTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array<string, mixed>> body di ogni POST /chat/completions catturato */
    private array $capturedChatBodies = [];

    private function makeKey(array $overrides = []): WidgetKey
    {
        static $n = 0;
        $n++;

        return WidgetKey::create(array_merge([
            'tenant_id' => 'default',
            'project_key' => 'gescat',
            'public_key' => 'pk_host_'.$n,
            'allowed_origins' => ['https://allowed.test'],
            'rate_limit' => 1000,
            'skill' => 'gescat-assistant@1',
            'host_tools_enabled' => true,
            'is_active' => true,
            'label' => 'host-'.$n,
        ], $overrides));
    }

    private function headers(WidgetKey $key): array
    {
        return ['X-Widget-Key' => $key->public_key, 'Origin' => 'https://allowed.test'];
    }

    /**
     * @param  list<array<string, mixed>>  $hostTools
     * @return array<string, mixed>
     */
    private function snapshot(array $hostTools = [], array $overrides = []): array
    {
        return array_merge([
            'page' => ['url' => 'https://allowed.test/articoli', 'title' => 'Articoli'],
            'regions' => [],
            'fields' => [],
            'actions' => [],
            'messages' => [],
            'locales_available' => ['it'],
            'page_outline' => ['headings' => [], 'buttons_unannotated' => [], 'inputs_unannotated' => []],
            'host_tools' => $hostTools,
        ], $overrides);
    }

    private function hostTool(string $name): array
    {
        return [
            'name' => $name,
            'description' => 'Host tool '.$name,
            'parameters' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query']],
            'returns' => 'ui-data-table',
            'execution' => 'host',
        ];
    }

    /**
     * Fa il fake di embeddings + chat; cattura il body di ogni chat call e
     * ritorna la `$chatResponse` per le /chat/completions.
     *
     * @param  array<string, mixed>  $chatResponse  payload OpenAI da restituire
     */
    private function fakeLlm(array $chatResponse): void
    {
        $this->capturedChatBodies = [];

        Http::fake(function ($request) use ($chatResponse) {
            if (str_contains($request->url(), '/embeddings')) {
                return Http::response([
                    'data' => [['index' => 0, 'embedding' => array_fill(0, 1536, 0.0)]],
                    'model' => 'text-embedding-3-small',
                    'usage' => ['total_tokens' => 1],
                ], 200);
            }

            $this->capturedChatBodies[] = (array) $request->data();

            return Http::response($chatResponse, 200);
        });
    }

    /** @return array<string, mixed> */
    private function chatMessage(string $content): array
    {
        return [
            'model' => 'gpt-4o',
            'choices' => [['message' => ['role' => 'assistant', 'content' => $content], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ];
    }

    /** @return array<string, mixed> */
    private function chatToolCall(string $tool, array $args): array
    {
        return [
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
        ];
    }

    /**
     * @return list<string> i nomi dei tool (function.name) nell'ultima chat call
     */
    private function lastToolNames(): array
    {
        $body = end($this->capturedChatBodies) ?: [];
        $tools = is_array($body['tools'] ?? null) ? $body['tools'] : [];

        return array_values(array_filter(array_map(
            static fn ($t): ?string => is_array($t) ? ($t['function']['name'] ?? null) : null,
            $tools,
        )));
    }

    // ─── F1.4 ──────────────────────────────────────────────────────────

    public function test_host_tools_are_included_in_the_llm_tool_list_when_enabled(): void
    {
        $this->fakeLlm($this->chatMessage('Ecco i risultati.'));

        $key = $this->makeKey(); // gescat-assistant@1 → host_tools_enabled true

        $this->withHeaders($this->headers($key))->postJson('/api/widget/sessions/start', [
            'snapshot' => $this->snapshot([$this->hostTool('articoli__searchArticoli')]),
            'message' => 'Cerca la pera rossa',
        ])->assertOk();

        $names = $this->lastToolNames();
        $this->assertContains('articoli__searchArticoli', $names, 'host tool deve essere nella tool list');
        // i tool FE/BE della skill restano presenti accanto agli host tools.
        $this->assertContains('search_knowledge_base', $names);
    }

    public function test_host_tools_are_ignored_when_skill_has_host_tools_disabled(): void
    {
        $this->fakeLlm($this->chatMessage('Risposta.'));

        // askmydocs-assistant@1 NON ha host_tools_enabled → ramo ignorato.
        $key = $this->makeKey(['skill' => 'askmydocs-assistant@1', 'host_tools_enabled' => false]);

        $this->withHeaders($this->headers($key))->postJson('/api/widget/sessions/start', [
            'snapshot' => $this->snapshot([$this->hostTool('articoli__searchArticoli')]),
            'message' => 'Cerca la pera rossa',
        ])->assertOk();

        $this->assertNotContains('articoli__searchArticoli', $this->lastToolNames());
    }

    public function test_allowlist_filters_out_a_host_tool_outside_the_allowed_prefixes(): void
    {
        $this->fakeLlm($this->chatMessage('Risposta.'));

        // gescat-assistant@1 allowlist = ["articoli__","nodi__","consumptionRate__"].
        $key = $this->makeKey();

        $this->withHeaders($this->headers($key))->postJson('/api/widget/sessions/start', [
            'snapshot' => $this->snapshot([
                $this->hostTool('articoli__searchArticoli'), // in allowlist
                $this->hostTool('clienti__searchClienti'),   // FUORI allowlist
            ]),
            'message' => 'Cerca',
        ])->assertOk();

        $names = $this->lastToolNames();
        $this->assertContains('articoli__searchArticoli', $names);
        $this->assertNotContains('clienti__searchClienti', $names);
    }

    public function test_a_host_tool_call_is_returned_to_the_fe_marked_execution_host(): void
    {
        $this->fakeLlm($this->chatToolCall('articoli__searchArticoli', ['query' => 'pera']));

        $key = $this->makeKey();

        $res = $this->withHeaders($this->headers($key))->postJson('/api/widget/sessions/start', [
            'snapshot' => $this->snapshot([$this->hostTool('articoli__searchArticoli')]),
            'message' => 'Cerca la pera rossa',
        ]);

        $res->assertOk()
            ->assertJsonPath('type', 'tool_call')
            ->assertJsonPath('tool_call.tool', 'articoli__searchArticoli')
            ->assertJsonPath('tool_call.args.query', 'pera')
            ->assertJsonPath('tool_call.execution', 'host')
            ->assertJsonPath('tool_call.is_host_tool', true)
            ->assertJsonPath('tool_call.is_be_tool', false)
            // la sessione resta in attesa dell'esecuzione FE-proxied.
            ->assertJsonPath('session.status', WidgetSession::STATUS_WAITING_TOOL);

        // host tool persistito come tool_call; NON eseguito server-side.
        $this->assertDatabaseHas('widget_session_steps', [
            'kind' => WidgetSessionStep::KIND_TOOL_CALL,
            'tool' => 'articoli__searchArticoli',
        ]);
    }

    // ─── F1.5 ──────────────────────────────────────────────────────────

    public function test_step_with_host_tool_result_reinjects_the_artifact_into_the_llm_context(): void
    {
        $key = $this->makeKey();

        // Sessione attiva (post tool_call host), pronta a ricevere il risultato.
        $session = WidgetSession::create([
            'tenant_id' => 'default',
            'widget_key_id' => $key->id,
            'project_key' => 'gescat',
            'public_session_id' => Str::uuid()->toString(),
            'status' => WidgetSession::STATUS_WAITING_TOOL,
            'skill' => 'gescat-assistant@1',
            'page_url' => 'https://allowed.test/articoli',
            'origin' => 'https://allowed.test',
        ]);

        // Dopo aver visto l'artifact, il modello risponde con testo finale.
        $this->fakeLlm($this->chatMessage('Ho trovato 1 articolo: Pera rossa.'));

        $artifact = [
            'componentType' => 'ui-data-table',
            'componentProps' => ['rows' => [['codice' => 'PERA-001', 'nome' => 'Pera rossa']]],
        ];

        $res = $this->withHeaders($this->headers($key))->postJson(
            "/api/widget/sessions/{$session->public_session_id}/step",
            [
                'snapshot' => $this->snapshot([$this->hostTool('articoli__searchArticoli')]),
                'tool_result' => [
                    'tool' => 'articoli__searchArticoli',
                    'execution' => 'host',
                    'ok' => true,
                    'artifact' => $artifact,
                ],
            ],
        );

        $res->assertOk()->assertJsonPath('type', 'message');

        // F1.5 — il risultato host (KIND_TOOL_RESULT) è persistito (stessa pipeline FE).
        $this->assertDatabaseHas('widget_session_steps', [
            'widget_session_id' => $session->id,
            'kind' => WidgetSessionStep::KIND_TOOL_RESULT,
            'tool' => 'articoli__searchArticoli',
        ]);

        // L'artifact è reiniettato nel contesto LLM: appare nel body della chat.
        $body = end($this->capturedChatBodies) ?: [];
        $messages = is_array($body['messages'] ?? null) ? $body['messages'] : [];
        $haystack = json_encode($messages, JSON_UNESCAPED_UNICODE) ?: '';
        $this->assertStringContainsString('PERA-001', $haystack, 'artifact host deve essere reiniettato nel contesto LLM');
    }
}
