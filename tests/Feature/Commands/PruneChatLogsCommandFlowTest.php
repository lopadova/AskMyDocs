<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Models\ChatLog;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * End-to-end coverage for the v4.2 Flow refactor of `chat-log:prune` —
 * tenant fan-out + dry-run.
 */
final class PruneChatLogsCommandFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $this->app->make(TenantContext::class)->reset();
        parent::tearDown();
    }

    public function test_fans_out_per_tenant(): void
    {
        $this->seedRow('tenant-a', 'old', 60);
        $this->seedRow('tenant-b', 'old', 60);

        $this->artisan('chat-log:prune', ['--days' => 30])
            ->expectsOutputToContain('[tenant-a] Deleted 1 chat_logs rows')
            ->expectsOutputToContain('[tenant-b] Deleted 1 chat_logs rows')
            ->assertSuccessful();

        $this->assertSame(0, ChatLog::count());
    }

    public function test_tenant_filter_restricts_to_one_tenant(): void
    {
        $this->seedRow('tenant-a', 'old', 60);
        $this->seedRow('tenant-b', 'old', 60);

        $this->artisan('chat-log:prune', ['--days' => 30, '--tenant' => 'tenant-a'])
            ->assertSuccessful();

        $this->assertSame(0, ChatLog::where('tenant_id', 'tenant-a')->count());
        $this->assertSame(1, ChatLog::where('tenant_id', 'tenant-b')->count());
    }

    public function test_dry_run_records_plan_without_deleting(): void
    {
        $this->seedRow('default', 'old', 60);

        $this->artisan('chat-log:prune', ['--days' => 30, '--dry-run' => true])
            ->expectsOutputToContain('Would delete 1')
            ->assertSuccessful();

        $this->assertSame(1, ChatLog::count());
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
