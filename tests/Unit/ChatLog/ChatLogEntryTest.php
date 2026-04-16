<?php

namespace Tests\Unit\ChatLog;

use App\Services\ChatLog\ChatLogEntry;
use PHPUnit\Framework\TestCase;

class ChatLogEntryTest extends TestCase
{
    public function test_captures_all_fields(): void
    {
        $entry = new ChatLogEntry(
            sessionId: '00000000-0000-4000-8000-000000000001',
            userId: 7,
            question: 'Come configuro OAuth?',
            answer: 'Secondo i documenti...',
            projectKey: 'erp-core',
            aiProvider: 'openai',
            aiModel: 'gpt-4o',
            chunksCount: 3,
            sources: ['docs/auth/setup.md', 'docs/auth/oauth.md'],
            promptTokens: 120,
            completionTokens: 80,
            totalTokens: 200,
            latencyMs: 2345,
            clientIp: '127.0.0.1',
            userAgent: 'phpunit',
            extra: ['few_shot_count' => 2],
        );

        $this->assertSame('00000000-0000-4000-8000-000000000001', $entry->sessionId);
        $this->assertSame(7, $entry->userId);
        $this->assertSame('Come configuro OAuth?', $entry->question);
        $this->assertSame('Secondo i documenti...', $entry->answer);
        $this->assertSame('erp-core', $entry->projectKey);
        $this->assertSame('openai', $entry->aiProvider);
        $this->assertSame('gpt-4o', $entry->aiModel);
        $this->assertSame(3, $entry->chunksCount);
        $this->assertSame(['docs/auth/setup.md', 'docs/auth/oauth.md'], $entry->sources);
        $this->assertSame(200, $entry->totalTokens);
        $this->assertSame(2345, $entry->latencyMs);
        $this->assertSame('127.0.0.1', $entry->clientIp);
        $this->assertSame('phpunit', $entry->userAgent);
        $this->assertSame(['few_shot_count' => 2], $entry->extra);
    }

    public function test_allows_anonymous_session(): void
    {
        $entry = new ChatLogEntry(
            sessionId: 'sess-1',
            userId: null,
            question: 'q',
            answer: 'a',
            projectKey: null,
            aiProvider: 'gemini',
            aiModel: 'gemini-2.0-flash',
            chunksCount: 0,
            sources: [],
            promptTokens: null,
            completionTokens: null,
            totalTokens: null,
            latencyMs: 0,
            clientIp: null,
            userAgent: null,
        );

        $this->assertNull($entry->userId);
        $this->assertNull($entry->projectKey);
        $this->assertSame([], $entry->extra);
    }

    public function test_is_immutable(): void
    {
        $reflection = new \ReflectionClass(ChatLogEntry::class);
        $this->assertTrue($reflection->isReadOnly());
        $this->assertTrue($reflection->isFinal());
    }
}
