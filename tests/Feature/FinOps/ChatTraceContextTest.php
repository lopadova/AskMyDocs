<?php

declare(strict_types=1);

namespace Tests\Feature\FinOps;

use App\FinOps\ChatTraceContext;
use Padosoft\LaravelAiFinOps\Support\TraceContext;
use Tests\TestCase;

/**
 * v8.16/W3 — the chat-turn trace id exists EXACTLY when a usage-ledger row does
 * (finops metering ON), so chat_logs.trace_id is never a dangling join key.
 */
final class ChatTraceContextTest extends TestCase
{
    public function test_new_trace_id_is_null_when_metering_off(): void
    {
        config()->set('ai-finops.enabled', true);
        config()->set('ai-finops.metering', false);

        $this->assertNull(ChatTraceContext::newTraceId());
    }

    public function test_new_trace_id_is_a_uuid_when_metering_on(): void
    {
        config()->set('ai-finops.enabled', true);
        config()->set('ai-finops.metering', true);

        $id = ChatTraceContext::newTraceId();

        $this->assertNotNull($id);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id);
    }

    public function test_within_is_a_passthrough_on_null_trace_id(): void
    {
        $this->assertSame('ok', ChatTraceContext::within(null, fn (): string => 'ok'));
    }

    public function test_within_sets_the_ambient_trace_when_enabled(): void
    {
        config()->set('ai-finops.enabled', true);
        config()->set('ai-finops.metering', true);

        $seen = ChatTraceContext::within('trace-xyz', fn (): ?string => app(TraceContext::class)->traceId());

        $this->assertSame('trace-xyz', $seen);
        // Context is restored after the callback returns.
        $this->assertNull(app(TraceContext::class)->traceId());
    }

    public function test_within_does_not_set_ambient_trace_when_metering_off(): void
    {
        config()->set('ai-finops.enabled', true);
        config()->set('ai-finops.metering', false);

        $seen = ChatTraceContext::within('trace-xyz', fn (): ?string => app(TraceContext::class)->traceId());

        $this->assertNull($seen, 'no ambient trace when tracing is disabled');
    }
}
