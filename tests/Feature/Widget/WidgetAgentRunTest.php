<?php

declare(strict_types=1);

namespace Tests\Feature\Widget;

use App\Agent\AgentAnswer;
use App\Agent\AgentEventPublisher;
use App\Agent\AgentResultProjector;
use App\Jobs\ExecuteAgentRunJob;
use App\Models\AgentRun;
use App\Models\WidgetKey;
use App\Models\WidgetSession;
use App\Models\WidgetSessionStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

final class WidgetAgentRunTest extends TestCase
{
    use RefreshDatabase;

    public function test_anonymous_widget_turn_opens_a_scoped_session_and_dispatches_a_run(): void
    {
        Queue::fake();
        $key = $this->key('pk_agent_widget', 'orders');

        $response = $this->withHeaders($this->headers($key))->postJson(
            '/api/widget/sessions/agent/start',
            [
                'snapshot' => $this->snapshot('it-IT'),
                'message' => 'Dammi gli ordini di Tizio',
                'page_url' => 'https://allowed.test/orders',
            ],
        );

        $response->assertAccepted()
            ->assertJsonPath('type', 'agent_run')
            ->assertJsonPath('session.locale', 'it-IT')
            ->assertJsonPath('run.status', AgentRun::STATUS_QUEUED);
        $session = WidgetSession::query()->sole();
        $run = AgentRun::query()->sole();
        $this->assertSame($session->id, $run->widget_session_id);
        $this->assertSame('orders', $run->project_key);
        $this->assertSame('anonymous_widget', $run->actor_type);
        $this->assertSame($run->run_id, data_get($session->steps()->sole()->args_json, 'agent_run_id'));
        Queue::assertPushed(ExecuteAgentRunJob::class, fn ($job): bool => $job->agentRunId === $run->id
            && $job->tenantId === $run->tenant_id);
    }

    public function test_terminal_widget_projection_is_idempotent_and_replayable(): void
    {
        $key = $this->key('pk_agent_projection', 'orders');
        $session = $this->widgetSession($key);
        $run = $this->agentRun($session);
        $answer = new AgentAnswer(
            answer: 'Ho trovato due ordini.',
            locale: 'it-IT',
            completeness: 'complete',
            citations: [['document_id' => 12, 'title' => 'Condizioni']],
            toolSources: [['execution_id' => 44, 'tool' => 'list_orders']],
        );

        app(AgentResultProjector::class)->project($run, $answer);
        app(AgentResultProjector::class)->project($run, $answer);

        $step = $session->steps()->where('kind', WidgetSessionStep::KIND_BOT_MESSAGE)->sole();
        $this->assertSame($run->id, $step->agent_run_id);
        $this->assertSame('Ho trovato due ordini.', data_get($step->args_json, 'content'));
        $this->assertSame('list_orders', data_get($step->args_json, 'tool_sources.0.tool'));
        $this->assertSame(1, $session->steps()->where('agent_run_id', $run->id)->count());

        $this->withHeaders($this->headers($key))
            ->getJson('/api/widget/sessions/'.$session->public_session_id.'/replay')
            ->assertOk()
            ->assertJsonPath('steps.0.args_json.citations.0.document_id', 12);
    }

    public function test_widget_event_stream_replays_events_and_hides_another_key(): void
    {
        config()->set('agent.events.stream_seconds', 0);
        $key = $this->key('pk_agent_events', 'orders');
        $otherKey = $this->key('pk_agent_intruder', 'orders');
        $session = $this->widgetSession($key);
        $run = $this->agentRun($session);
        $publisher = app(AgentEventPublisher::class);
        $publisher->publish($run, 'run.started', 'run.started');
        $publisher->publish($run, 'run.completed', 'run.completed', canCancel: false);

        $url = '/api/widget/sessions/'.$session->public_session_id.'/agent-runs/'.$run->run_id.'/events?after=1';
        $response = $this->withHeaders($this->headers($key) + ['Accept' => 'text/event-stream'])->get($url);
        $response->assertOk()->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8');
        $this->assertStringContainsString('event: run.completed', $response->streamedContent());

        $this->withHeaders($this->headers($otherKey))->get($url)->assertNotFound();
    }

    private function key(string $publicKey, string $project): WidgetKey
    {
        return WidgetKey::create([
            'tenant_id' => 'default',
            'project_key' => $project,
            'public_key' => $publicKey,
            'allowed_origins' => ['https://allowed.test'],
            'rate_limit' => 1000,
            'skill' => 'askmydocs-assistant@1',
            'is_active' => true,
            'label' => $publicKey,
        ]);
    }

    /** @return array<string,string> */
    private function headers(WidgetKey $key): array
    {
        return ['X-Widget-Key' => $key->public_key, 'Origin' => 'https://allowed.test'];
    }

    /** @return array<string,mixed> */
    private function snapshot(string $locale): array
    {
        return [
            'page' => ['url' => 'https://allowed.test/orders', 'title' => 'Orders'],
            'regions' => [],
            'fields' => [],
            'actions' => [],
            'messages' => [],
            'active_context' => ['locale' => $locale],
            'locales_available' => ['it'],
            'page_outline' => ['headings' => [], 'buttons_unannotated' => [], 'inputs_unannotated' => []],
        ];
    }

    private function widgetSession(WidgetKey $key): WidgetSession
    {
        return WidgetSession::create([
            'tenant_id' => $key->tenant_id,
            'widget_key_id' => $key->id,
            'project_key' => $key->project_key,
            'public_session_id' => Str::uuid()->toString(),
            'status' => WidgetSession::STATUS_ACTIVE,
            'skill' => $key->skill,
            'locale' => 'it-IT',
        ]);
    }

    private function agentRun(WidgetSession $session): AgentRun
    {
        return AgentRun::create([
            'run_id' => Str::uuid()->toString(),
            'tenant_id' => $session->tenant_id,
            'project_key' => $session->project_key,
            'widget_session_id' => $session->id,
            'channel' => 'widget',
            'actor_type' => 'anonymous_widget',
            'actor_id' => null,
            'locale' => $session->locale,
            'timezone' => 'Europe/Rome',
            'status' => AgentRun::STATUS_COMPLETED,
        ]);
    }
}
