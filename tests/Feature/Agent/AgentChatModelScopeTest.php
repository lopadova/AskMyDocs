<?php

namespace Tests\Feature\Agent;

use App\Agent\AgentExecutionContext;
use App\Ai\AgentChatModelScope;
use App\Services\Admin\AppSettingsResolver;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentChatModelScopeTest extends TestCase
{
    use RefreshDatabase;

    private function context(string $project): AgentExecutionContext
    {
        return new AgentExecutionContext('run', 'warehouse', $project, 'chat', 'user', '3', 'it', 'Europe/Rome');
    }

    public function test_all_text_stages_use_the_project_profile_and_nested_scopes_restore_even_after_errors(): void
    {
        app(TenantContext::class)->set('warehouse');
        app(AppSettingsResolver::class)->set('ai.chat_model', 'economical/model', 'warehouse', 'orders');
        app(AppSettingsResolver::class)->set('ai.chat_routing', 'economy', 'warehouse', 'orders');
        $scope = app(AgentChatModelScope::class);
        $stage = ['model' => 'previous-expensive-stage', 'temperature' => 0];
        $this->assertSame($stage, $scope->options($stage));
        $scope->within($this->context('orders'), function () use ($scope, $stage): void {
            $this->assertSame('economical/model', $scope->options($stage)['model']);
            $this->assertSame(['prompt' => .12, 'completion' => .60], $scope->options($stage)['provider']['max_price']);
            try {
                $scope->within($this->context('other-project'), function () use ($scope, $stage): void {
                    $this->assertSame($stage, $scope->options($stage));
                    throw new \RuntimeException('failed stage');
                });
            } catch (\RuntimeException) {
                $this->assertSame('economical/model', $scope->options($stage)['model']);
            }
        });
        $this->assertSame($stage, $scope->options($stage));
    }

    public function test_another_tenant_cannot_inherit_an_active_profile(): void
    {
        app(TenantContext::class)->set('other');
        $this->expectException(\DomainException::class);
        app(AgentChatModelScope::class)->within($this->context('orders'),
            fn () => app(AgentChatModelScope::class)->options([]));
    }

    public function test_every_ai_manager_text_entry_point_overrides_an_explicit_old_stage_model(): void
    {
        app(TenantContext::class)->set('warehouse');
        app(AppSettingsResolver::class)->set('ai.chat_model', 'economical/model', 'warehouse', 'orders');
        $provider = \Mockery::mock(\App\Ai\AiProviderInterface::class);
        $provider->shouldReceive('chat')->once()->withArgs(function ($system, $question, $options) {
            return $options['model'] === 'economical/model';
        })->andReturn(new \App\Ai\AiResponse('answer', 'openrouter', 'economical/model'));
        $provider->shouldReceive('chatWithHistory')->twice()->withArgs(function ($system, $history, $options) {
            return $options['model'] === 'economical/model';
        })->andReturn(new \App\Ai\AiResponse('answer', 'openrouter', 'economical/model'));
        $manager = \Mockery::mock(\App\Ai\AiManager::class)->makePartial();
        $manager->shouldReceive('provider')->andReturn($provider);
        config(['ai-finops.enabled' => false]);
        app(AgentChatModelScope::class)->within($this->context('orders'), function () use ($manager) {
            $options = ['model' => 'previous-expensive-stage'];
            $manager->chat('system', 'question', $options);
            $manager->chatWithHistory('system', [['role' => 'user', 'content' => 'question']], $options);
            $manager->chatWithProvider('openrouter', 'system', [['role' => 'user', 'content' => 'question']], $options);
        });
        $this->addToAssertionCount(3);
    }
}
