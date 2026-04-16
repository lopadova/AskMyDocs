<?php

namespace Tests\Feature\ChatLog;

use App\Models\ChatLog;
use App\Services\ChatLog\ChatLogEntry;
use App\Services\ChatLog\ChatLogManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ChatLogManagerTest extends TestCase
{
    use RefreshDatabase;

    private function entry(array $overrides = []): ChatLogEntry
    {
        return new ChatLogEntry(
            sessionId: $overrides['sessionId'] ?? '00000000-0000-4000-8000-000000000000',
            userId: $overrides['userId'] ?? null,
            question: $overrides['question'] ?? 'q',
            answer: $overrides['answer'] ?? 'a',
            projectKey: $overrides['projectKey'] ?? null,
            aiProvider: $overrides['aiProvider'] ?? 'openai',
            aiModel: $overrides['aiModel'] ?? 'gpt-4o',
            chunksCount: $overrides['chunksCount'] ?? 0,
            sources: $overrides['sources'] ?? [],
            promptTokens: $overrides['promptTokens'] ?? null,
            completionTokens: $overrides['completionTokens'] ?? null,
            totalTokens: $overrides['totalTokens'] ?? null,
            latencyMs: $overrides['latencyMs'] ?? 100,
            clientIp: $overrides['clientIp'] ?? null,
            userAgent: $overrides['userAgent'] ?? null,
            extra: $overrides['extra'] ?? [],
        );
    }

    public function test_enabled_reflects_config(): void
    {
        config()->set('chat-log.enabled', true);
        $this->assertTrue((new ChatLogManager())->enabled());

        config()->set('chat-log.enabled', false);
        $this->assertFalse((new ChatLogManager())->enabled());
    }

    public function test_log_is_noop_when_disabled(): void
    {
        config()->set('chat-log.enabled', false);

        (new ChatLogManager())->log($this->entry());

        $this->assertSame(0, ChatLog::count());
    }

    public function test_log_persists_entry_via_database_driver(): void
    {
        config()->set('chat-log.enabled', true);
        config()->set('chat-log.driver', 'database');

        (new ChatLogManager())->log($this->entry([
            'sessionId' => '11111111-1111-4111-8111-111111111111',
            'question' => 'Come configuro OAuth?',
            'answer' => 'Vedi docs',
            'projectKey' => 'erp-core',
            'aiProvider' => 'openai',
            'aiModel' => 'gpt-4o',
            'chunksCount' => 4,
            'sources' => ['docs/a.md', 'docs/b.md'],
            'totalTokens' => 123,
            'latencyMs' => 500,
            'clientIp' => '127.0.0.1',
            'userAgent' => 'phpunit',
            'extra' => ['few_shot_count' => 1],
        ]));

        $this->assertSame(1, ChatLog::count());

        $row = ChatLog::first();
        $this->assertSame('11111111-1111-4111-8111-111111111111', $row->session_id);
        $this->assertSame('Come configuro OAuth?', $row->question);
        $this->assertSame('erp-core', $row->project_key);
        $this->assertSame(['docs/a.md', 'docs/b.md'], $row->sources);
        $this->assertSame(['few_shot_count' => 1], $row->extra);
    }

    public function test_log_swallows_driver_errors(): void
    {
        config()->set('chat-log.enabled', true);
        config()->set('chat-log.driver', 'unknown-driver');

        Log::shouldReceive('error')->once();

        // Should NOT throw — errors are caught and logged
        (new ChatLogManager())->log($this->entry());

        $this->assertSame(0, ChatLog::count());
    }
}
