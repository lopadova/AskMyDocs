<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Tests\TestCase;

final class McpToolScopeMiddlewareCoverageTest extends TestCase
{
    public function test_mcp_route_includes_scope_middleware(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../../routes/ai.php');
        $this->assertStringContainsString("->middleware(['auth:sanctum', 'mcp.scope', 'throttle:api'])", $src);
    }

    public function test_every_kb_tool_is_registered_on_kb_server(): void
    {
        $serverSrc = (string) file_get_contents(__DIR__ . '/../../app/Mcp/Servers/KnowledgeBaseServer.php');
        preg_match_all('/\\b(Kb[A-Za-z0-9]+Tool)::class\\b/', $serverSrc, $matches);
        $tools = array_map(static fn (string $class): string => 'App\\Mcp\\Tools\\' . $class, $matches[1] ?? []);

        $toolFiles = glob(__DIR__ . '/../../app/Mcp/Tools/Kb*Tool.php') ?: [];
        $toolClasses = array_map(
            static fn (string $path): string => 'App\\Mcp\\Tools\\' . basename($path, '.php'),
            $toolFiles
        );

        sort($tools);
        sort($toolClasses);

        $this->assertSame($toolClasses, $tools, 'Every MCP KB tool must pass through the same scoped MCP server route.');
    }
}
