<?php

declare(strict_types=1);

namespace App\Ai\Tools\Sources;

use App\Ai\Tools\ChatToolInvocationResult;
use App\Ai\Tools\ChatToolSourceContract;
use App\Mcp\Client\McpToolAuthorizer;
use App\Mcp\Client\Registry\McpServerRegistry;
use App\Mcp\Client\ToolInvoker;
use App\Mcp\Runtime\McpRuntimeGate;
use App\Models\McpServer;
use App\Models\User;

final readonly class LegacyMcpChatToolSource implements ChatToolSourceContract
{
    public function __construct(
        private McpServerRegistry $registry,
        private ToolInvoker $invoker,
        private McpToolAuthorizer $authorizer,
        private McpRuntimeGate $runtime,
    ) {}

    public function key(): string
    {
        return 'legacy_mcp';
    }

    public function catalog(User $user, ?string $projectKey = null): array
    {
        if (! config('mcp.enabled', false) || ! $this->runtime->usesLegacy()) {
            return [];
        }

        $catalog = [];
        foreach ($this->registry->activeServersForTenant() as $server) {
            if (! $server instanceof McpServer) {
                continue;
            }
            $enabled = $server->enabled_tools_json;
            if (! is_array($enabled) || $enabled === []) {
                continue;
            }
            foreach ($this->tools($server) as $tool) {
                $name = is_string($tool['name'] ?? null) ? $tool['name'] : '';
                if ($name === '' || ($enabled !== ['*'] && ! in_array($name, $enabled, true))) {
                    continue;
                }
                if (! $this->authorizer->canInvoke($user, $server, $name)) {
                    continue;
                }
                $catalog[] = [
                    'name' => $name,
                    'description' => is_string($tool['description'] ?? null) ? $tool['description'] : '',
                    'inputSchema' => $this->inputSchema($tool),
                    'server' => $server,
                    'provenance' => [
                        'source' => 'legacy_mcp',
                        'server_id' => $server->id,
                        'server_name' => $server->name,
                        'tool_remote_name' => $name,
                    ],
                ];
            }
        }

        return $catalog;
    }

    public function invoke(array $tool, array $arguments, User $user, array $context = []): ChatToolInvocationResult
    {
        if (! $this->runtime->usesLegacy()) {
            throw new \RuntimeException('The legacy MCP runtime is not active for this tenant.');
        }

        $server = $tool['server'] ?? null;
        $name = $tool['name'] ?? null;
        if (! $server instanceof McpServer || ! is_string($name) || ! $this->authorizer->canInvoke($user, $server, $name)) {
            throw new \RuntimeException('Legacy MCP tool is no longer available.');
        }
        $result = $this->invoker->invoke($user, $server, $name, $arguments, $context);

        return new ChatToolInvocationResult('completed', $result, [
            'source' => $this->key(),
            'server_id' => $server->id,
            'server_name' => $server->name,
            'tool_remote_name' => $name,
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function tools(McpServer $server): array
    {
        $handshake = $server->handshake_response_json;
        if (! is_array($handshake)) {
            return [];
        }
        $candidate = data_get($handshake, 'tools')
            ?? data_get($handshake, 'capabilities.tools')
            ?? data_get($handshake, 'tool.list');
        if (! is_array($candidate)) {
            return [];
        }
        if (array_is_list($candidate)) {
            return array_values(array_filter($candidate, 'is_array'));
        }

        $tools = [];
        foreach ($candidate as $key => $tool) {
            if (is_array($tool)) {
                $tool['name'] ??= (string) $key;
                $tools[] = $tool;
            } elseif (is_string($tool)) {
                $tools[] = ['name' => $tool];
            }
        }

        return $tools;
    }

    /** @param array<string, mixed> $tool */
    private function inputSchema(array $tool): array
    {
        $schema = $tool['inputSchema'] ?? $tool['input_schema'] ?? $tool['parameters'] ?? [];

        return is_array($schema) ? $schema : [];
    }
}
