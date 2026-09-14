<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Agent\AgentEventPublisher;
use App\Agent\AgentProgress;
use App\Models\AgentRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

final class AgentEventPublisherTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_assigns_monotonic_sequences_and_uses_the_pinned_locale(): void
    {
        $run = $this->makeRun('it-IT');
        $publisher = app(AgentEventPublisher::class);
        App::setLocale('en');

        $first = $publisher->publish($run, 'run.started', 'run.started');
        $second = $publisher->publish($run, 'tool.started', 'tool.started', ['tool' => 'ERP']);

        $this->assertSame(1, $first->sequence);
        $this->assertSame(2, $second->sequence);
        $this->assertSame('Sto chiamando ERP.', $second->message);
        $this->assertSame(2, $run->fresh()->last_sequence);
    }

    public function test_serialized_event_contains_normalized_progress_and_safe_data(): void
    {
        $run = $this->makeRun('en-US');
        $publisher = app(AgentEventPublisher::class);
        $progress = new AgentProgress(
            logicalCompleted: 1,
            logicalMinimum: 2,
            logicalLikely: 3,
            logicalMaximum: 5,
            physicalCompleted: 4,
            physicalMinimum: 5,
            physicalLikely: 12,
            physicalMaximum: 50,
            etaMs: 2300,
        );

        $event = $publisher->publish(
            $run,
            'tool.progress',
            'tool.progress',
            ['completed' => 4, 'estimated' => 12],
            ['tool' => 'orders'],
            $progress,
        );
        $serialized = $publisher->serialize($event->load('run'));

        $this->assertSame('en-US', $serialized['locale']);
        $this->assertSame(12, data_get($serialized, 'progress.physical.estimated.likely'));
        $this->assertSame(2300, data_get($serialized, 'progress.eta_ms'));
        $this->assertSame('orders', data_get($serialized, 'data.tool'));
        $this->assertTrue($serialized['can_cancel']);
    }

    public function test_progress_rejects_incoherent_estimates(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AgentProgress(logicalMinimum: 5, logicalLikely: 3, logicalMaximum: 4);
    }

    public function test_event_messages_and_payloads_are_masked_before_persistence(): void
    {
        $run = $this->makeRun('it-IT');
        $event = app(AgentEventPublisher::class)->publish(
            $run,
            'tool.started',
            'tool.started',
            ['tool' => 'ERP admin@example.com'],
            ['diagnostic' => 'Bearer eyJabcdefghijk.abcdefghijklmnopqrstu'],
        );

        $this->assertSame('Sto chiamando ERP [EMAIL].', $event->message);
        $this->assertSame('ERP [EMAIL]', $event->message_params['tool']);
        $this->assertSame('Bearer [TOKEN]', data_get($event->payload_json, 'data.diagnostic'));
        $this->assertStringNotContainsString('admin@example.com', json_encode($event->toArray()));
    }

    public function test_local_mcp_debug_payload_is_key_redacted_before_persistence(): void
    {
        config()->set('app.env', 'local');
        $run = $this->makeRun('it-IT');

        $event = app(AgentEventPublisher::class)->publish(
            $run,
            'tool.completed',
            'tool.completed',
            ['tool' => 'Gescat'],
            [
                'mcp_debug' => [
                    'method' => 'tools/call',
                    'parameters' => [
                        'authorization' => 'Bearer super-secret-token',
                        'nested' => ['access_token' => 'secret', 'email' => 'admin@example.com'],
                    ],
                    'response' => ['ok' => true],
                ],
            ],
        );

        $this->assertSame('[REDACTED]', data_get($event->payload_json, 'data.mcp_debug.parameters.authorization'));
        $this->assertSame('[REDACTED]', data_get($event->payload_json, 'data.mcp_debug.parameters.nested.access_token'));
        $this->assertSame('[EMAIL]', data_get($event->payload_json, 'data.mcp_debug.parameters.nested.email'));
        $this->assertStringNotContainsString('super-secret-token', (string) json_encode($event->payload_json));
    }

    public function test_mcp_debug_payload_is_not_persisted_outside_local_or_stage(): void
    {
        config()->set('app.env', 'production');
        $run = $this->makeRun('en-US');

        $event = app(AgentEventPublisher::class)->publish(
            $run,
            'tool.completed',
            'tool.completed',
            ['tool' => 'Gescat'],
            ['tool' => 'gescat', 'mcp_debug' => ['parameters' => ['secret' => 'must-not-persist']]],
        );

        $this->assertSame('gescat', data_get($event->payload_json, 'data.tool'));
        $this->assertArrayNotHasKey('mcp_debug', $event->payload_json['data']);
        $this->assertStringNotContainsString('must-not-persist', (string) json_encode($event->payload_json));
    }

    private function makeRun(string $locale): AgentRun
    {
        return AgentRun::create([
            'run_id' => \Illuminate\Support\Str::uuid()->toString(),
            'tenant_id' => 'test-tenant',
            'channel' => 'chat',
            'actor_type' => 'user',
            'actor_id' => '1',
            'locale' => $locale,
            'timezone' => 'UTC',
        ]);
    }
}
