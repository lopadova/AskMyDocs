<?php

namespace Tests\Unit\Agent;

use App\Agent\AgentProgress;
use App\Agent\Planning\AgentPlan;
use App\Agent\Planning\AgentPlannedAction;
use App\Agent\Planning\AgentPlanValidationException;
use App\Agent\Planning\AgentRequestedSourceCoverage;
use App\Agent\Tools\AgentToolDefinition;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class AgentRequestedSourceCoverageTest extends TestCase
{
    public function test_document_only_plan_cannot_satisfy_an_explicit_live_comparison(): void
    {
        $this->fake(.99);
        $this->expectException(AgentPlanValidationException::class);
        $this->expectExceptionMessage('explicitly requested operational data from Warehouse ERP');
        app(AgentRequestedSourceCoverage::class)->validate('Compare the email with ORDER-A in Warehouse ERP',
            new AgentPlan('tools', [], new AgentProgress), $this->tools(), []);
    }

    public function test_mentioning_a_system_in_an_archived_message_does_not_force_a_lookup(): void
    {
        $this->fake(.01);
        app(AgentRequestedSourceCoverage::class)->validate('Translate the archived email mentioning Warehouse ERP.',
            new AgentPlan('answer', [], new AgentProgress), $this->tools(), []);
        Http::assertSentCount(1);
    }

    public function test_an_accepted_or_failed_attempt_covers_the_source_without_replaying_it(): void
    {
        Http::preventStrayRequests();
        foreach (['completed', 'failed', 'skipped'] as $status) {
            app(AgentRequestedSourceCoverage::class)->validate('Compare ORDER-A in Warehouse ERP',
                new AgentPlan('answer', [], new AgentProgress), $this->tools(), [['tool' => 'erp_search', 'status' => $status]]);
        }
        app(AgentRequestedSourceCoverage::class)->validate('Compare ORDER-A in Warehouse ERP',
            new AgentPlan('tools', [new AgentPlannedAction('erp', 'erp_search', [], [], 'Read ERP')], new AgentProgress), $this->tools(), []);
        Http::assertNothingSent();
    }

    public function test_provider_failure_or_uncertainty_does_not_invent_a_source_obligation(): void
    {
        foreach ([.5, null] as $probability) {
            $this->fake($probability);
            app(AgentRequestedSourceCoverage::class)->validate('What does this email say?',
                new AgentPlan('answer', [], new AgentProgress), $this->tools(), []);
        }
        $this->assertSame(.8, (float) config('reasoning.threshold'));
    }

    private function fake(?float $probability): void
    {
        config(['ai.providers.openrouter.key' => 'test', 'reasoning.threshold' => .8]);
        Http::preventStrayRequests();
        Http::fake(fn () => Http::response(['model' => 'jev-test', 'answers' => $probability === null ? []
            : ['required_0' => ['type' => 'noul', 'noul' => $probability]]]));
    }

    private function tools(): array
    {
        return ['erp_search' => new AgentToolDefinition('erp_search', 'Order search', 'Read orders', 'api', [], true, true, 1, 1, 1,
            metadata: ['source_key' => 'api:2', 'source_name' => 'Warehouse ERP'])];
    }
}
