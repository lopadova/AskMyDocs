<?php

namespace Tests\Feature\Commands;

use App\Models\ChatLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PruneChatLogsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function seedRow(string $session, \DateTimeInterface $createdAt): void
    {
        ChatLog::create([
            'session_id' => $session,
            'user_id' => null,
            'question' => 'q',
            'answer' => 'a',
            'project_key' => null,
            'ai_provider' => 'openai',
            'ai_model' => 'gpt-4o',
            'chunks_count' => 0,
            'sources' => [],
            'latency_ms' => 100,
            'created_at' => $createdAt,
        ]);
    }

    public function test_uses_config_retention_when_days_option_missing(): void
    {
        config()->set('chat-log.retention_days', 30);

        $this->seedRow('11111111-1111-4111-8111-111111111111', now()->subDays(60));
        $this->seedRow('22222222-2222-4222-8222-222222222222', now()->subDays(45));
        $this->seedRow('33333333-3333-4333-8333-333333333333', now()->subDays(10));

        $this->artisan('chat-log:prune')
            ->expectsOutputToContain('Deleted 2 chat_logs rows older than 30 days')
            ->assertSuccessful();

        $this->assertSame(1, ChatLog::count());
    }

    public function test_days_cli_option_overrides_config(): void
    {
        config()->set('chat-log.retention_days', 365);

        $this->seedRow('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', now()->subDays(45));
        $this->seedRow('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', now()->subDay());

        $this->artisan('chat-log:prune', ['--days' => 30])
            ->expectsOutputToContain('Deleted 1')
            ->assertSuccessful();

        $this->assertSame(1, ChatLog::count());
    }

    public function test_retention_zero_is_a_noop(): void
    {
        $this->seedRow('cccccccc-cccc-4ccc-8ccc-cccccccccccc', now()->subYears(5));

        $this->artisan('chat-log:prune', ['--days' => 0])
            ->expectsOutputToContain('skipping rotation')
            ->assertSuccessful();

        $this->assertSame(1, ChatLog::count());
    }
}
