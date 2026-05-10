<?php

declare(strict_types=1);

namespace Tests\Feature\Flow;

use App\Flow\Definitions\PruneChatLogsFlow;
use App\Models\ChatLog;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\LaravelFlow\Facades\Flow;
use Padosoft\LaravelFlow\FlowExecutionOptions;
use Padosoft\LaravelFlow\FlowRun;
use Tests\TestCase;

final class PruneChatLogsFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $this->app->make(TenantContext::class)->reset();
        parent::tearDown();
    }

    public function test_deletes_only_target_tenants_old_rows(): void
    {
        $this->seedRow('tenant-a', 'old', 60);
        $this->seedRow('tenant-a', 'fresh', 5);
        $this->seedRow('tenant-b', 'old', 60);

        $cutoff = CarbonImmutable::now()->subDays(30);
        $run = Flow::execute(
            PruneChatLogsFlow::NAME,
            ['tenant_id' => 'tenant-a', 'cutoff_iso' => $cutoff->toIso8601String()],
            FlowExecutionOptions::make(correlationId: 'tenant-a'),
        );

        $this->assertSame(FlowRun::STATUS_SUCCEEDED, $run->status);
        $this->assertSame(1, ChatLog::where('tenant_id', 'tenant-a')->count());
        $this->assertSame(1, ChatLog::where('tenant_id', 'tenant-b')->count());
    }

    public function test_dry_run_records_plan_without_deleting(): void
    {
        $this->seedRow('default', 'old', 60);
        $cutoff = CarbonImmutable::now()->subDays(30);

        $run = Flow::dryRun(
            PruneChatLogsFlow::NAME,
            ['tenant_id' => 'default', 'cutoff_iso' => $cutoff->toIso8601String()],
            FlowExecutionOptions::make(correlationId: 'default'),
        );

        $this->assertSame(FlowRun::STATUS_SUCCEEDED, $run->status);
        $this->assertSame(1, ChatLog::count());
        $count = $run->stepResults['count-stale-chat-logs'];
        $this->assertSame(1, $count->output['planned_count']);
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
