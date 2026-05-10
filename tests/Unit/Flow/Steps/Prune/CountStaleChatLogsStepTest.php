<?php

declare(strict_types=1);

namespace Tests\Unit\Flow\Steps\Prune;

use App\Flow\Steps\Prune\CountStaleChatLogsStep;
use App\Models\ChatLog;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\LaravelFlow\Exceptions\FlowInputException;
use Padosoft\LaravelFlow\FlowContext;
use Tests\TestCase;

final class CountStaleChatLogsStepTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $this->app->make(TenantContext::class)->reset();
        parent::tearDown();
    }

    public function test_counts_only_rows_older_than_cutoff(): void
    {
        $this->seedRow('tenant-a', 'old', 60);
        $this->seedRow('tenant-a', 'fresh', 5);

        $step = $this->app->make(CountStaleChatLogsStep::class);
        $cutoff = CarbonImmutable::now()->subDays(30);
        $result = $step->execute($this->context('tenant-a', $cutoff->toIso8601String()));

        $this->assertSame(1, $result->output['planned_count']);
    }

    public function test_throws_on_missing_tenant_id(): void
    {
        $step = $this->app->make(CountStaleChatLogsStep::class);
        $context = new FlowContext(
            flowRunId: 'r',
            definitionName: 'kb.prune-chat-logs',
            input: ['cutoff_iso' => CarbonImmutable::now()->toIso8601String()],
        );

        $this->expectException(FlowInputException::class);
        $step->execute($context);
    }

    public function test_tenant_isolation_does_not_count_other_tenants_rows(): void
    {
        $this->seedRow('tenant-a', 'old', 60);
        $this->seedRow('tenant-b', 'old', 60);

        $step = $this->app->make(CountStaleChatLogsStep::class);
        $cutoff = CarbonImmutable::now()->subDays(30);
        $result = $step->execute($this->context('tenant-a', $cutoff->toIso8601String()));

        $this->assertSame(1, $result->output['planned_count']);
    }

    private function context(string $tenantId, string $cutoffIso): FlowContext
    {
        return new FlowContext(
            flowRunId: 'r',
            definitionName: 'kb.prune-chat-logs',
            input: ['tenant_id' => $tenantId, 'cutoff_iso' => $cutoffIso],
        );
    }

    private function seedRow(string $tenantId, string $session, int $createdDaysAgo): void
    {
        $tc = $this->app->make(TenantContext::class);
        $tc->set($tenantId);
        ChatLog::create([
            'session_id' => str_pad($session, 36, '0', STR_PAD_RIGHT),
            'user_id' => null,
            'question' => 'q',
            'answer' => 'a',
            'project_key' => null,
            'ai_provider' => 'openai',
            'ai_model' => 'gpt-4o',
            'chunks_count' => 0,
            'sources' => [],
            'latency_ms' => 100,
            'created_at' => CarbonImmutable::now()->subDays($createdDaysAgo),
        ]);
    }
}
