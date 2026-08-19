<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Ai\AiManager;
use App\Ai\AiProviderInterface;
use App\Ai\AiResponse;
use App\Ai\Tools\ChatToolInvocationResult;
use App\Ai\Tools\ChatToolSourceContract;
use App\Ai\Tools\ChatToolSourceRegistry;
use App\Mcp\Client\McpToolCallingService;
use App\Models\User;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

final class ChatToolCallingServiceTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function test_it_executes_a_registered_source_and_feeds_fresh_result_to_the_final_turn(): void
    {
        $source = new class implements ChatToolSourceContract
        {
            /** @var list<array<string, mixed>> */
            public array $calls = [];

            public function key(): string
            {
                return 'mcp';
            }

            public function catalog(User $user, ?string $projectKey = null): array
            {
                return [[
                    'name' => 'knowledge_search',
                    'description' => 'Fresh knowledge',
                    'inputSchema' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string']]],
                    'provenance' => ['connection_id' => 'connection-1'],
                ]];
            }

            public function invoke(array $tool, array $arguments, User $user, array $context = []): ChatToolInvocationResult
            {
                $this->calls[] = ['arguments' => $arguments, 'context' => $context];

                return new ChatToolInvocationResult(
                    'completed',
                    ['artifact' => [
                        'text' => 'Fresh MCP evidence.',
                        'app' => ['id' => '01APPHANDLE', 'resource_uri' => 'ui://fresh/result.html'],
                    ]],
                    [
                        'source' => 'mcp',
                        'connection_id' => 'connection-1',
                        'app' => ['id' => '01APPHANDLE', 'resource_uri' => 'ui://fresh/result.html'],
                    ],
                );
            }
        };
        $provider = Mockery::mock(AiProviderInterface::class);
        $provider->shouldReceive('name')->once()->andReturn('openai');
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('provider')->once()->andReturn($provider);
        $ai->shouldReceive('chatWithHistory')
            ->once()
            ->withArgs(static fn (string $system, array $messages, array $options): bool => $system === 'system'
                && count($messages) === 1
                && data_get($options, 'tools.0.function.name') === 'knowledge_search')
            ->andReturn(new AiResponse(
                '',
                'openai',
                'test-model',
                toolCalls: [[
                    'id' => 'call-1',
                    'type' => 'function',
                    'function' => ['name' => 'knowledge_search', 'arguments' => '{"query":"latest"}'],
                ]],
            ));
        $ai->shouldReceive('chatWithHistory')
            ->once()
            ->withArgs(static fn (string $system, array $messages, array $options): bool => $system === 'system'
                && count($messages) === 3
                && $messages[2]['role'] === 'tool'
                && str_contains($messages[2]['content'], 'Fresh MCP evidence.'))
            ->andReturn(new AiResponse('__NO_GROUNDED_ANSWER__', 'openai', 'test-model'));

        $service = new McpToolCallingService($ai, new ChatToolSourceRegistry([$source]));
        $response = $service->chatWithTools(
            'system',
            [['role' => 'user', 'content' => 'What is new?']],
            user: new User,
            context: ['conversation_id' => '7', 'project_key' => 'project-a'],
        );

        $this->assertSame('Fresh MCP evidence.', $response->content);
        $this->assertSame('completed', $response->toolCalls[0]['status']);
        $this->assertSame('connection-1', $response->toolCalls[0]['provenance']['connection_id']);
        $this->assertSame('01APPHANDLE', $response->toolCalls[0]['app']['id']);
        $this->assertSame(['query' => 'latest'], $source->calls[0]['arguments']);
        $this->assertSame('project-a', $source->calls[0]['context']['project_key']);
    }

    public function test_it_suspends_the_tool_loop_while_a_remote_task_is_running(): void
    {
        $source = new class implements ChatToolSourceContract
        {
            public function key(): string
            {
                return 'mcp';
            }

            public function catalog(User $user, ?string $projectKey = null): array
            {
                return [[
                    'name' => 'report_generate',
                    'description' => 'Generate report',
                    'inputSchema' => ['type' => 'object'],
                ]];
            }

            public function invoke(array $tool, array $arguments, User $user, array $context = []): ChatToolInvocationResult
            {
                return new ChatToolInvocationResult(
                    'task_accepted',
                    ['status' => 'task_accepted', 'task_id' => 'task-local-1'],
                    [
                        'source' => 'mcp',
                        'task_id' => 'task-local-1',
                        'task' => ['status' => 'working', 'poll_interval_ms' => 1000],
                        'prompt' => ['message' => 'The MCP task is running.'],
                    ],
                );
            }
        };
        $provider = Mockery::mock(AiProviderInterface::class);
        $provider->shouldReceive('name')->once()->andReturn('openai');
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('provider')->once()->andReturn($provider);
        $ai->shouldReceive('chatWithHistory')->once()->andReturn(new AiResponse(
            '',
            'openai',
            'test-model',
            toolCalls: [[
                'id' => 'call-task-1',
                'type' => 'function',
                'function' => ['name' => 'report_generate', 'arguments' => '{}'],
            ]],
        ));

        $service = new McpToolCallingService($ai, new ChatToolSourceRegistry([$source]));
        $response = $service->chatWithTools(
            'system',
            [['role' => 'user', 'content' => 'Generate it']],
            user: new User,
            context: ['conversation_id' => '7'],
        );

        $this->assertSame('tool_interaction', $response->finishReason);
        $this->assertSame('The MCP task is running.', $response->content);
        $this->assertSame('task_accepted', $response->toolCalls[0]['status']);
        $this->assertSame('task-local-1', $response->toolCalls[0]['task_id']);
    }
}
