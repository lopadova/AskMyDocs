<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Ai\Tools\ChatToolInvocationResult;
use App\Ai\Tools\ChatToolSourceContract;
use App\Ai\Tools\ChatToolSourceRegistry;
use App\Models\User;
use PHPUnit\Framework\TestCase;

final class ChatToolSourceRegistryTest extends TestCase
{
    public function test_it_keeps_first_name_and_generates_stable_collision_safe_names(): void
    {
        $first = $this->source('mcp', 'search');
        $second = $this->source('api', 'search');
        $user = new User;

        $index = (new ChatToolSourceRegistry([$first, $second]))->toolIndex($user);
        $names = array_keys($index);

        $this->assertSame('search', $names[0]);
        $this->assertMatchesRegularExpression('/^api_search_[a-f0-9]{8}$/', $names[1]);
        $this->assertSame($names, array_keys((new ChatToolSourceRegistry([$first, $second]))->toolIndex($user)));
        $this->assertSame('mcp', $index['search']['source_key']);
        $this->assertSame('api', $index[$names[1]]['source_key']);
    }

    private function source(string $key, string $name): ChatToolSourceContract
    {
        return new class($key, $name) implements ChatToolSourceContract
        {
            public function __construct(private readonly string $keyName, private readonly string $toolName) {}

            public function key(): string
            {
                return $this->keyName;
            }

            public function catalog(User $user, ?string $projectKey = null): array
            {
                return [[
                    'name' => $this->toolName,
                    'description' => 'Search',
                    'inputSchema' => ['type' => 'object'],
                ]];
            }

            public function invoke(array $tool, array $arguments, User $user, array $context = []): ChatToolInvocationResult
            {
                return new ChatToolInvocationResult('completed', []);
            }
        };
    }
}
