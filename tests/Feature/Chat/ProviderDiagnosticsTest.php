<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Agent\AgentAnswerPresenter;
use App\Agent\AgentAnswerSynthesizer;
use App\Agent\AgentExecutionContext;
use App\Agent\AgentLoopOutcome;
use App\Agent\AgentRunCancelledException;
use App\Agent\Artifacts\AgentTableArtifactFactory;
use App\Agent\Debug\ProviderDiagnostics;
use App\Agent\Evidence\AgentEvidenceFactory;
use App\Agent\Grounding\AgentClaimGroundingValidator;
use App\Agent\Grounding\DecisionOnce;
use App\Ai\AiManager;
use App\Ai\AiResponse;
use App\Models\AgentDiagnosticSpan;
use App\Models\AgentRun;
use App\Services\Widget\WidgetPiiMasker;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

final class ProviderDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        app(TenantContext::class)->set('default');
    }

    public function test_provider_request_is_open_during_callback_and_records_only_technical_response_metadata(): void
    {
        $run = $this->runFixture();
        $response = new AiResponse('Private document body', 'fake', 'fake-model', toolCalls: [['arguments' => ['token' => 'private-token']]]);
        $result = app(ProviderDiagnostics::class)->measure($run, 'provider.synthesis', function () use ($run, $response) {
            $span = AgentDiagnosticSpan::query()->where('agent_run_id', $run->id)->sole();
            $this->assertSame('started', $span->status);
            $this->assertNull($span->ended_at);
            $this->assertSame('provider_response', $span->metadata_json['wait_for']);
            return $response;
        }, ['operation' => 'structured_draft', 'wait_for' => 'provider_response', 'payload' => ['password' => 'private-password']]);

        $this->assertSame($response, $result);
        $span = AgentDiagnosticSpan::query()->where('agent_run_id', $run->id)->sole();
        $this->assertSame('completed', $span->status);
        $this->assertSame('fake', $span->metadata_json['provider']);
        $this->assertSame('fake-model', $span->metadata_json['model']);
        $this->assertNotNull($span->duration_ms);
        $this->assertStringNotContainsString('private', json_encode($span->toArray()));
        $this->assertStringNotContainsString('Private document body', json_encode($span->toArray()));
    }

    public function test_structured_submission_retry_records_parsing_failure_separately_from_provider_completion(): void
    {
        $run = $this->runFixture();
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->once()->andReturn(new AiResponse('', 'fake', 'fake-model',
            toolCalls: [['name' => 'submit_agent_answer', 'arguments' => '{"claims":[']]));
        $ai->shouldReceive('chatWithHistory')->once()->andReturn(new AiResponse('', 'fake', 'fake-model',
            toolCalls: [['name' => 'submit_agent_answer', 'arguments' => ['claims' => []]]]));
        $synthesizer = new AgentAnswerSynthesizer($ai, app(WidgetPiiMasker::class), app(AgentTableArtifactFactory::class), app(AgentClaimGroundingValidator::class));
        [, $payload] = (new \ReflectionMethod($synthesizer, 'structuredDraft'))->invoke($synthesizer,
            'Private question', $this->context(), new AgentLoopOutcome('answer', app(AgentEvidenceFactory::class)->empty(), []),
            null, null, null, false, $run);

        $this->assertSame(['claims' => []], $payload);
        $attempts = AgentDiagnosticSpan::query()->where('agent_run_id', $run->id)->where('phase', 'synthesis.attempt')->get()
            ->keyBy(fn ($span) => $span->metadata_json['attempt']);
        $this->assertSame('failed', $attempts[1]->status);
        $this->assertTrue($attempts[1]->metadata_json['error_handled']);
        $this->assertSame(\UnexpectedValueException::class, $attempts[1]->metadata_json['error_class']);
        $this->assertSame('completed', $attempts[2]->status);
        $providers = AgentDiagnosticSpan::query()->where('phase', 'provider.synthesis')->where('status', 'completed')->get();
        $this->assertCount(2, $providers);
        foreach ($providers as $provider) {
            $this->assertSame($attempts[$provider->metadata_json['attempt']]->id, $provider->parent_span_id);
        }
        $this->assertStringNotContainsString('Private question', json_encode($attempts->toArray()));
    }

    public function test_decision_failure_preserves_dispatch_guard_without_replaying_and_omits_exception_content(): void
    {
        $run = $this->runFixture();
        $exception = new \RuntimeException('Authorization: Bearer private-token', previous: new \RuntimeException('private-source'));
        $calls = 0;
        $call = function () use (&$calls, $exception) {
            $calls++;
            throw $exception;
        };
        try {
            app(DecisionOnce::class)->run($run->run_id, 'signature', $call, tenantId: 'default');
            $this->fail('Expected the original provider exception.');
        } catch (\RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }
        try {
            app(DecisionOnce::class)->run($run->run_id, 'signature', $call, tenantId: 'default');
            $this->fail('Expected the existing replay guard.');
        } catch (\RuntimeException $caught) {
            $this->assertStringContainsString('already dispatched', $caught->getMessage());
        }
        $this->assertSame(1, $calls);
        $this->assertSame('dispatched', $run->fresh()->result_json['focused_decision']['status']);
        $provider = AgentDiagnosticSpan::query()->where('phase', 'provider.decision')->sole();
        $this->assertSame('failed', $provider->status);
        $this->assertTrue($provider->metadata_json['error_handled']);
        $this->assertSame('failed', AgentDiagnosticSpan::query()->where('phase', 'validation.decision_dispatch')->sole()->status);
        $this->assertStringNotContainsString('private', json_encode(AgentDiagnosticSpan::all()->toArray()));
    }

    public function test_unexpected_synthesis_failure_is_logged_and_rethrown_without_a_new_retry(): void
    {
        $run = $this->runFixture();
        $exception = new \RuntimeException('private-source');
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->once()->andThrow($exception);
        $synthesizer = new AgentAnswerSynthesizer($ai, app(WidgetPiiMasker::class), app(AgentTableArtifactFactory::class), app(AgentClaimGroundingValidator::class));
        try {
            (new \ReflectionMethod($synthesizer, 'structuredDraft'))->invoke($synthesizer,
                'Private question', $this->context(), new AgentLoopOutcome('answer', app(AgentEvidenceFactory::class)->empty(), []),
                null, null, null, false, $run);
            $this->fail('Expected the original provider exception.');
        } catch (\RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }
        $attempt = AgentDiagnosticSpan::query()->where('phase', 'synthesis.attempt')->sole();
        $this->assertSame('failed', $attempt->status);
        $this->assertArrayNotHasKey('error_handled', $attempt->metadata_json);
        $this->assertSame('failed', AgentDiagnosticSpan::query()->where('phase', 'provider.synthesis')->sole()->status);
    }

    public function test_cancellation_is_recorded_without_converting_the_original_exception(): void
    {
        $run = $this->runFixture();
        $exception = new AgentRunCancelledException($run->run_id);
        try {
            app(ProviderDiagnostics::class)->measure($run, 'provider.synthesis', fn () => throw $exception);
            $this->fail('Expected cancellation.');
        } catch (AgentRunCancelledException $caught) {
            $this->assertSame($exception, $caught);
        }
        $span = AgentDiagnosticSpan::query()->where('phase', 'provider.synthesis')->sole();
        $this->assertSame('cancelled', $span->status);
        $this->assertSame('cancelled', $span->metadata_json['error_kind']);
    }

    public function test_presentation_failure_remains_a_safe_fallback_and_records_the_absorbed_provider_error(): void
    {
        $run = $this->runFixture();
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->once()->andThrow(new \RuntimeException('private-source'));
        $result = (new AgentAnswerPresenter($ai))->present('Question', $this->context(), [
            ['text' => 'Source-bound fact.', 'quote' => 'Source-bound fact.'],
        ], $run->run_id);

        $this->assertNull($result['paragraphs']);
        $this->assertSame('unavailable', $result['trace']['status']);
        $this->assertSame('failed', AgentDiagnosticSpan::query()->where('phase', 'presentation.attempt')->sole()->status);
        $provider = AgentDiagnosticSpan::query()->where('phase', 'provider.presentation')->sole();
        $this->assertSame('failed', $provider->status);
        $this->assertTrue($provider->metadata_json['error_handled']);
        $this->assertStringNotContainsString('private-source', json_encode(AgentDiagnosticSpan::all()->toArray()));
    }

    public function test_resolution_is_tenant_scoped_and_missing_diagnostic_storage_cannot_change_the_call_result(): void
    {
        $run = $this->runFixture();
        $diagnostics = app(ProviderDiagnostics::class);
        $this->assertNull($diagnostics->resolve($run->run_id, 'another-tenant'));
        $this->assertSame($run->id, $diagnostics->resolve($run->run_id, 'default')->id);
        Schema::drop('agent_diagnostic_spans');
        $this->assertSame('original-result', $diagnostics->measure($run, 'provider.synthesis', fn () => 'original-result'));
    }

    private function runFixture(): AgentRun
    {
        return AgentRun::create(['run_id' => (string) Str::uuid(), 'tenant_id' => 'default', 'project_key' => 'demo',
            'channel' => 'chat', 'actor_type' => 'user', 'actor_id' => '1', 'locale' => 'it', 'timezone' => 'UTC',
            'status' => 'running', 'input_json' => ['question' => 'Test']]);
    }

    private function context(): AgentExecutionContext
    {
        return new AgentExecutionContext('context-run', 'default', 'demo', 'chat', 'user', '1', 'it', 'UTC');
    }
}
