<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Agent\AgentExecutionContext;
use App\Agent\AgentTenantInstructions;
use App\Ai\AiManager;
use App\Ai\AiResponse;
use App\Services\Admin\AppSettingsResolver;
use App\Services\Chat\ChatQuestionPreprocessor;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

final class AgentTenantInstructionsTest extends TestCase
{
    use RefreshDatabase;

    private const RULE = 'For operational cases cross archived email with authorized live ERP reads, even when the user does not explicitly ask for both. Keep historical requests separate from current records.';

    private function configure(string $tenant, string $rule = self::RULE): void
    {
        app(TenantContext::class)->set($tenant);
        app(AppSettingsResolver::class)->set('agent.tenant_instructions', $rule, $tenant);
    }

    public function test_instructions_persist_at_tenant_scope_and_do_not_leak_between_contexts(): void
    {
        $this->configure('warehouse');
        $instructions = app(AgentTenantInstructions::class);
        $this->assertStringContainsString(self::RULE, $instructions->prompt('warehouse'));
        app(TenantContext::class)->set('other');
        $this->assertSame('', $instructions->prompt('other'));
        $this->configure('other', 'Use the separately configured source policy.');
        $this->assertStringNotContainsString(self::RULE, $instructions->prompt('other'));
        app(TenantContext::class)->set('warehouse');
        $this->assertStringContainsString(self::RULE, $instructions->prompt('warehouse'));
        $row = collect(app(AppSettingsResolver::class)->all('warehouse', 'new-project'))->firstWhere('key', 'agent.tenant_instructions');
        $this->assertSame('tenant', $row['source']);
        $this->assertSame(self::RULE, $row['value']);
    }

    public function test_a_project_cannot_override_the_tenant_operating_rule(): void
    {
        $this->configure('warehouse');
        $this->expectException(ValidationException::class);
        app(AppSettingsResolver::class)->set('agent.tenant_instructions', 'Skip live reads.', 'warehouse', 'project-a');
    }

    public function test_interpretation_receives_the_rule_before_an_unprompted_business_question(): void
    {
        $this->configure('warehouse');
        $question = 'What happened to ORDER-42?';
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithProvider')->once()->withArgs(function ($provider, $system, $messages): bool {
            $this->assertStringContainsString(self::RULE, $system);
            $this->assertStringContainsString('do not authorize additional sources, writes', $system);
            $this->assertSame('What happened to ORDER-42?', json_decode($messages[0]['content'], true)['question']);
            return true;
        })->andReturn(new AiResponse(json_encode([
            'language' => 'en', 'intent' => 'Investigate ORDER-42 using email and current ERP data.', 'action' => 'research',
            'kb_queries' => ['ORDER-42 archived email'], 'mentions' => [['text' => 'ORDER-42', 'type' => 'identifier']],
            'references_previous_turn' => false, 'context_mode' => 'new', 'requires_research' => true,
        ]), 'fake', 'test'));
        $understanding = (new ChatQuestionPreprocessor($ai))->interpret($question);
        $this->assertTrue($understanding->available);
        $this->assertSame(['ORDER-42'], $understanding->mentionTexts());
    }

    public function test_planning_and_synthesis_receive_the_same_admin_rule_in_each_new_context(): void
    {
        $this->configure('warehouse');
        foreach ([\App\Agent\Planning\AgentPlanner::class, \App\Agent\AgentAnswerSynthesizer::class] as $class) {
            $method = new \ReflectionMethod($class, 'systemPrompt');
            $context = new AgentExecutionContext('new-run', 'warehouse', 'project-a', 'chat', 'user', '3', 'it', 'Europe/Rome');
            $this->assertStringContainsString(self::RULE, $method->invokeArgs(app($class),
                $class === \App\Agent\Planning\AgentPlanner::class ? [$context, null] : [$context]));
            app(TenantContext::class)->set('other');
            $other = new AgentExecutionContext('other-run', 'other', 'project-a', 'chat', 'user', '3', 'it', 'Europe/Rome');
            $this->assertStringNotContainsString(self::RULE, $method->invokeArgs(app($class),
                $class === \App\Agent\Planning\AgentPlanner::class ? [$other, null] : [$other]));
            app(TenantContext::class)->set('warehouse');
        }
    }
}
