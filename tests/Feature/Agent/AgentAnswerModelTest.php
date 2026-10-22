<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Agent\AgentAnswerModel;
use App\Agent\AgentAnswerSynthesizer;
use App\Agent\AgentExecutionContext;
use App\Agent\AgentLoopOutcome;
use App\Agent\Evidence\AgentEvidenceFactory;
use App\Ai\AiManager;
use App\Ai\AiResponse;
use App\Services\Admin\AppSettingsResolver;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

final class AgentAnswerModelTest extends TestCase
{
    use RefreshDatabase;

    private function context(string $tenant = 'warehouse', string $project = 'orders'): AgentExecutionContext
    {
        app(TenantContext::class)->set($tenant);

        return new AgentExecutionContext('run-id', $tenant, $project, 'chat', 'user', '3', 'it', 'Europe/Rome');
    }

    public function test_absent_overrides_preserve_each_stage_default(): void
    {
        config(['agent.synthesis.model' => 'default-answer', 'agent.synthesis.repair_model' => 'default-repair',
            'agent.presentation.model' => 'explicit-presentation']);
        $model = app(AgentAnswerModel::class);
        $context = $this->context();
        $this->assertSame('default-answer', $model->synthesis($context));
        $this->assertSame('default-repair', $model->synthesis($context, true));
        $this->assertSame('explicit-presentation', $model->presentation($context));
        config(['agent.synthesis.model' => null, 'agent.synthesis.repair_model' => null, 'agent.presentation.model' => null]);
        $this->assertNull($model->synthesis($context));
        $this->assertNull($model->synthesis($context, true));
        $this->assertNull($model->presentation($context));
    }

    public function test_tenant_and_project_models_do_not_leak_in_a_shared_process(): void
    {
        config(['agent.synthesis.model' => 'global-answer']);
        $context = $this->context();
        $settings = app(AppSettingsResolver::class);
        $settings->set('agent.synthesis.model', 'tenant-answer', 'warehouse');
        $settings->set('agent.synthesis.model', 'project-answer', 'warehouse', 'orders');
        $model = app(AgentAnswerModel::class);
        $this->assertSame('project-answer', $model->synthesis($context));
        $this->assertSame('tenant-answer', $model->synthesis($this->context(project: 'returns')));
        $this->assertSame('global-answer', $model->synthesis($this->context('other')));
        $this->assertSame('project-answer', $model->synthesis($this->context()));
    }

    public function test_repair_and_presentation_inherit_only_when_their_own_default_is_absent(): void
    {
        config(['agent.synthesis.repair_model' => null, 'agent.presentation.model' => null]);
        $context = $this->context();
        $settings = app(AppSettingsResolver::class);
        $settings->set('agent.synthesis.model', 'tenant-answer', 'warehouse');
        $model = app(AgentAnswerModel::class);
        $this->assertSame('tenant-answer', $model->synthesis($context, true));
        $this->assertSame('tenant-answer', $model->presentation($context));
        $settings->set('agent.synthesis.repair_model', 'tenant-repair', 'warehouse');
        $this->assertSame('tenant-repair', $model->synthesis($context, true));
        config(['agent.presentation.model' => 'explicit-presentation']);
        $this->assertSame('explicit-presentation', $model->presentation($context));
    }

    public function test_the_effective_models_reach_actual_draft_and_repair_provider_options(): void
    {
        $context = $this->context();
        $settings = app(AppSettingsResolver::class);
        $settings->set('agent.synthesis.model', 'tenant-answer', 'warehouse');
        $settings->set('agent.synthesis.repair_model', 'tenant-repair', 'warehouse');
        $seen = [];
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->twice()->withArgs(function ($system, $history, $options) use (&$seen): bool {
            $seen[] = $options['model'] ?? null;
            return true;
        })->andReturn(new AiResponse('', 'fake', 'test'));
        $this->app->instance(AiManager::class, $ai);
        $outcome = new AgentLoopOutcome('answer', app(AgentEvidenceFactory::class)->empty(), []);
        $method = new \ReflectionMethod(AgentAnswerSynthesizer::class, 'draft');
        $synthesizer = app(AgentAnswerSynthesizer::class);
        $method->invoke($synthesizer, 'Read ORDER-A', $context, $outcome, null);
        $method->invoke($synthesizer, 'Read ORDER-A', $context, $outcome, null, null, null, true);
        $this->assertSame(['tenant-answer', 'tenant-repair'], $seen);
    }

    public function test_a_local_probe_can_compare_models_without_mutating_tenant_settings(): void
    {
        $context = $this->context();
        $settings = app(AppSettingsResolver::class);
        $settings->set('agent.synthesis.model', 'tenant-answer', 'warehouse');
        $probe = new AgentAnswerModel($settings, ['agent.synthesis.model' => 'probe-answer']);
        $this->assertSame('probe-answer', $probe->synthesis($context));
        $this->assertSame('tenant-answer', app(AgentAnswerModel::class)->synthesis($context));
        $this->assertSame('tenant-answer', $settings->effective('agent.synthesis.model', 'warehouse'));
    }
}
