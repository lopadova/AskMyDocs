<?php

declare(strict_types=1);

namespace Tests\Feature\Widget;

use App\Ai\AiManager;
use App\Ai\AiResponse;
use App\Models\WidgetKey;
use App\Models\WidgetSession;
use App\Models\WidgetSessionStep;
use App\Services\Kb\Chat\ChatRetrievalService;
use App\Services\Kb\Retrieval\SearchResult;
use App\Services\Widget\WidgetOrchestratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

final class WidgetCitationPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_answer_persists_compact_masked_citations_and_replay_returns_them(): void
    {
        $key = WidgetKey::create([
            'tenant_id' => 'default',
            'project_key' => 'docs-v3',
            'public_key' => 'pk_citation_persistence',
            'allowed_origins' => ['https://allowed.test'],
            'rate_limit' => 1000,
            'skill' => 'askmydocs-assistant@1',
            'is_active' => true,
            'label' => 'citation-persistence',
        ]);

        $result = new SearchResult(
            primary: collect([[
                'chunk_id' => 44,
                'project_key' => 'docs-v3',
                'document' => [
                    'id' => 17,
                    'title' => 'Account guide',
                    'source_path' => 'guides/account.md',
                    'source_type' => 'markdown',
                    'slug' => 'account-guide',
                    'generation_source' => 'human',
                ],
                'heading_path' => 'Account > Contact',
                'rerank_score' => 0.91,
                'vector_score' => 0.88,
                'chunk_hash' => str_repeat('a', 64),
                'chunk_text' => 'Write to jane@example.com for help with your account.',
            ]]),
            expanded: collect(),
            rejected: collect(),
        );

        $retrieval = Mockery::mock(ChatRetrievalService::class)->makePartial();
        $retrieval->shouldReceive('retrieve')->once()->andReturn($result);
        $retrieval->shouldReceive('shouldRefuse')->once()->andReturn(false);
        $this->app->instance(ChatRetrievalService::class, $retrieval);

        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->once()->andReturn(new AiResponse(
            content: 'Use the account guide.',
            provider: 'fake',
            model: 'fake-citations',
            promptTokens: 10,
            completionTokens: 5,
            totalTokens: 15,
            finishReason: 'stop',
            toolCalls: [],
        ));
        $this->app->instance(AiManager::class, $ai);

        $headers = [
            'X-Widget-Key' => $key->public_key,
            'Origin' => 'https://allowed.test',
        ];
        $response = $this->withHeaders($headers)->postJson('/api/widget/sessions/start', [
            'snapshot' => ['page' => ['url' => 'https://allowed.test/account']],
            'message' => 'Who can help me?',
        ]);

        $response->assertOk()
            ->assertJsonPath('citations.0.document_id', 17)
            ->assertJsonPath('citations.0.chunks.0.evidence_hash', str_repeat('a', 64));

        $session = WidgetSession::query()->firstOrFail();
        $step = $session->steps()
            ->where('kind', WidgetSessionStep::KIND_BOT_MESSAGE)
            ->firstOrFail();
        $citation = $step->args_json['citations'][0];

        $this->assertSame(17, $citation['document_id']);
        $this->assertSame('Account guide', $citation['title']);
        $this->assertSame('guides/account.md', $citation['source_path']);
        $this->assertSame('markdown', $citation['source_type']);
        $this->assertSame('primary', $citation['origin']);
        $this->assertSame(['Account > Contact'], $citation['headings']);
        $this->assertSame('Account > Contact', $citation['chunks'][0]['heading']);
        $this->assertStringContainsString('[EMAIL]', $citation['chunks'][0]['snippet']);
        $this->assertArrayNotHasKey('score', $citation['chunks'][0]);
        $this->assertArrayNotHasKey('evidence_hash', $citation['chunks'][0]);

        $replay = $this->withHeaders($headers)
            ->getJson("/api/widget/sessions/{$session->public_session_id}/replay");

        $replay->assertOk()
            ->assertJsonPath('steps.1.kind', WidgetSessionStep::KIND_BOT_MESSAGE)
            ->assertJsonPath('steps.1.args_json.citations.0.document_id', 17)
            ->assertJsonPath('steps.1.args_json.citations.0.chunks.0.heading', 'Account > Contact');
        $this->assertStringContainsString(
            '[EMAIL]',
            (string) $replay->json('steps.1.args_json.citations.0.chunks.0.snippet'),
        );
    }

    public function test_replay_remains_compatible_with_legacy_bot_steps_without_citations(): void
    {
        $key = WidgetKey::factory()->create([
            'tenant_id' => 'default',
            'public_key' => 'pk_legacy_replay',
        ]);
        $session = WidgetSession::factory()->create([
            'tenant_id' => 'default',
            'widget_key_id' => $key->id,
            'project_key' => $key->project_key,
        ]);
        $session->steps()->create([
            'tenant_id' => 'default',
            'step_index' => 0,
            'kind' => WidgetSessionStep::KIND_BOT_MESSAGE,
            'args_json' => ['content' => 'Legacy answer.'],
        ]);

        $this->withHeaders([
            'X-Widget-Key' => $key->public_key,
            'Origin' => 'https://allowed.test',
        ])->getJson("/api/widget/sessions/{$session->public_session_id}/replay")
            ->assertOk()
            ->assertJsonPath('steps.0.args_json.content', 'Legacy answer.')
            ->assertJsonMissingPath('steps.0.args_json.citations');
    }

    public function test_grounded_citations_survive_a_tool_call_and_its_automatic_continuation(): void
    {
        config(['ai.default' => 'fake']);
        $key = WidgetKey::create([
            'tenant_id' => 'default',
            'project_key' => 'docs-v3',
            'public_key' => 'pk_tool_citation_persistence',
            'allowed_origins' => ['https://allowed.test'],
            'rate_limit' => 1000,
            'skill' => 'askmydocs-assistant@1',
            'is_active' => true,
            'label' => 'tool-citation-persistence',
        ]);
        $result = new SearchResult(
            primary: collect([[
                'chunk_id' => 91,
                'project_key' => 'docs-v3',
                'document' => [
                    'id' => 23,
                    'title' => 'Operations guide',
                    'source_path' => 'guides/operations.md',
                    'source_type' => 'markdown',
                    'slug' => 'operations-guide',
                    'generation_source' => 'human',
                ],
                'heading_path' => 'Operations > Cache',
                'rerank_score' => 0.94,
                'vector_score' => 0.89,
                'chunk_hash' => str_repeat('b', 64),
                'chunk_text' => 'Contact ops@example.com before refreshing the cache.',
            ]]),
            expanded: collect(),
            rejected: collect(),
        );

        $retrieval = Mockery::mock(ChatRetrievalService::class)->makePartial();
        $retrieval->shouldReceive('retrieve')->once()->andReturn($result);
        $retrieval->shouldReceive('shouldRefuse')->once()->andReturn(false);
        $this->app->instance(ChatRetrievalService::class, $retrieval);

        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->twice()->andReturn(
            new AiResponse(
                content: 'Controllo la pagina usando la guida operativa.',
                provider: 'fake',
                model: 'fake-citations',
                promptTokens: 10,
                completionTokens: 5,
                totalTokens: 15,
                finishReason: 'tool_calls',
                toolCalls: [['name' => 'read_page', 'arguments' => '{}']],
            ),
            new AiResponse(
                content: 'Controllo completato secondo la guida.',
                provider: 'fake',
                model: 'fake-citations',
                promptTokens: 8,
                completionTokens: 6,
                totalTokens: 14,
                finishReason: 'stop',
                toolCalls: [],
            ),
        );
        $this->app->instance(AiManager::class, $ai);

        $orchestrator = app(WidgetOrchestratorService::class);
        $first = $orchestrator->start(
            $key,
            ['page' => ['url' => 'https://allowed.test/account']],
            'Controlla la cache',
            'https://allowed.test/account',
            'https://allowed.test',
        );

        $this->assertSame('tool_call', $first['type']);
        $this->assertSame(23, $first['citations'][0]['document_id']);
        $session = WidgetSession::query()->firstOrFail();
        $groundedToolMessage = $session->steps()
            ->where('kind', WidgetSessionStep::KIND_BOT_MESSAGE)
            ->firstOrFail();
        $this->assertStringContainsString(
            '[EMAIL]',
            (string) data_get($groundedToolMessage->args_json, 'citations.0.chunks.0.snippet'),
        );

        $second = $orchestrator->step(
            $session->fresh(),
            ['page' => ['url' => 'https://allowed.test/account']],
            null,
            ['tool' => 'read_page', 'ok' => true, 'diagnostic' => ['visible' => true]],
        );

        $this->assertSame('message', $second['type']);
        $this->assertSame(23, $second['citations'][0]['document_id']);
        $this->assertStringContainsString(
            '[EMAIL]',
            (string) data_get($second, 'citations.0.chunks.0.snippet'),
        );

        $botSteps = $session->steps()
            ->where('kind', WidgetSessionStep::KIND_BOT_MESSAGE)
            ->orderBy('step_index')
            ->get();
        $this->assertCount(2, $botSteps);
        $this->assertSame(23, data_get($botSteps->last()?->args_json, 'citations.0.document_id'));
    }

    public function test_an_ungrounded_tool_continuation_does_not_inherit_stale_sources(): void
    {
        config(['ai.default' => 'fake']);
        $key = WidgetKey::create([
            'tenant_id' => 'default',
            'project_key' => 'docs-v3',
            'public_key' => 'pk_no_stale_tool_citations',
            'allowed_origins' => ['https://allowed.test'],
            'rate_limit' => 1000,
            'skill' => 'askmydocs-assistant@1',
            'is_active' => true,
            'label' => 'no-stale-tool-citations',
        ]);
        $session = WidgetSession::create([
            'tenant_id' => 'default',
            'widget_key_id' => $key->id,
            'project_key' => $key->project_key,
            'public_session_id' => (string) \Illuminate\Support\Str::uuid(),
            'status' => WidgetSession::STATUS_WAITING_TOOL,
            'skill' => $key->skill,
            'meta' => ['consecutive_errors' => 0],
        ]);
        $session->steps()->createMany([
            [
                'tenant_id' => 'default',
                'step_index' => 0,
                'kind' => WidgetSessionStep::KIND_BOT_MESSAGE,
                'args_json' => [
                    'content' => 'Old grounded answer.',
                    'citations' => [[
                        'document_id' => 99,
                        'title' => 'Old source',
                        'source_path' => 'old.md',
                        'chunks' => [],
                    ]],
                ],
            ],
            [
                'tenant_id' => 'default',
                'step_index' => 1,
                'kind' => WidgetSessionStep::KIND_USER_MESSAGE,
                'args_json' => ['content' => 'A later ungrounded turn.'],
            ],
            [
                'tenant_id' => 'default',
                'step_index' => 2,
                'kind' => WidgetSessionStep::KIND_TOOL_CALL,
                'tool' => 'read_page',
                'args_json' => [],
            ],
        ]);

        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->once()->andReturn(new AiResponse(
            content: 'Ungrounded continuation.',
            provider: 'fake',
            model: 'fake-citations',
            promptTokens: 4,
            completionTokens: 3,
            totalTokens: 7,
            finishReason: 'stop',
            toolCalls: [],
        ));
        $this->app->instance(AiManager::class, $ai);

        $response = app(WidgetOrchestratorService::class)->step(
            $session,
            ['page' => ['url' => 'https://allowed.test']],
            null,
            ['tool' => 'read_page', 'ok' => true],
        );

        $this->assertSame([], $response['citations']);
        $this->assertSame(
            [],
            data_get(
                $session->steps()->where('kind', WidgetSessionStep::KIND_BOT_MESSAGE)->latest('step_index')->first()?->args_json,
                'citations',
            ),
        );
    }
}
