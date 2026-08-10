<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Http\Middleware\EnforceMcpScope;
use App\Mcp\Servers\KnowledgeBaseServer;
use App\Models\McpTenantToken;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use ReflectionClass;
use Tests\TestCase;

/**
 * Locks the MCP server-path write-tool scope gate (SEC-AI-ACT-001, F-03).
 *
 * A read-scoped (`mcp:read`) token must not be able to invoke a mutating tool;
 * write-capable tools require `mcp:tools:write`. The write set is derived from
 * the `#[IsReadOnly]` annotation, so the gate can never silently drift from the
 * registered tool population (bidirectional, R32-style).
 */
class McpWriteToolScopeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<int, class-string>
     */
    private function registeredTools(): array
    {
        /** @var array<int, class-string> $tools */
        $tools = (new ReflectionClass(KnowledgeBaseServer::class))
            ->getProperty('tools')
            ->getDefaultValue();

        return $tools;
    }

    /**
     * Resolve a real registered tool name by its read-only status, rather than
     * hard-coding a literal that could drift from Tool::name() (Copilot #412).
     */
    private function toolName(bool $readOnly): string
    {
        foreach ($this->registeredTools() as $toolClass) {
            $reflection = new ReflectionClass($toolClass);
            $isReadOnly = $reflection->getAttributes(IsReadOnly::class) !== [];
            if ($isReadOnly === $readOnly) {
                return $reflection->newInstanceWithoutConstructor()->name();
            }
        }

        $this->fail('no '.($readOnly ? 'read-only' : 'write-capable').' tool is registered');
    }

    public function test_every_write_capable_registered_tool_requires_the_write_scope(): void
    {
        $writeNames = EnforceMcpScope::writeToolNames();
        $this->assertNotEmpty($writeNames, 'expected the server to register write-capable tools');

        $seen = 0;
        foreach ($this->registeredTools() as $toolClass) {
            $reflection = new ReflectionClass($toolClass);
            if ($reflection->getAttributes(IsReadOnly::class) !== []) {
                continue;
            }
            $seen++;
            $name = $reflection->newInstanceWithoutConstructor()->name();
            $normalized = strtolower((string) preg_replace('/[^a-z0-9]/', '', $name));
            $this->assertArrayHasKey(
                $normalized,
                $writeNames,
                "write-capable tool {$toolClass} ({$name}) is not in the write-scope set",
            );
        }

        $this->assertSame(
            $seen,
            count($writeNames),
            'write-scope set must match the registered write-capable tools exactly (no drift)',
        );
    }

    public function test_read_only_tool_does_not_require_the_write_scope(): void
    {
        // A real registered read tool must never appear in the write-scope set.
        $writeNames = EnforceMcpScope::writeToolNames();
        $readToolNormalized = strtolower((string) preg_replace(
            '/[^a-z0-9]/',
            '',
            $this->toolName(readOnly: true),
        ));

        $this->assertArrayNotHasKey($readToolNormalized, $writeNames);
    }

    public function test_read_scoped_token_is_denied_a_write_tool(): void
    {
        $this->mintToken(['mcp:read']);

        $response = $this->callTool($this->toolName(readOnly: false));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('mcp_scope_missing', (string) $response->getContent());
        $this->assertStringContainsString('mcp:tools:write', (string) $response->getContent());
    }

    public function test_write_scoped_token_passes_the_scope_gate_for_a_write_tool(): void
    {
        $this->mintToken(['mcp:read', 'mcp:tools:write']);

        $passed = false;
        $response = $this->callTool($this->toolName(readOnly: false), function () use (&$passed) {
            $passed = true;

            return response('ok', 200);
        });

        $this->assertTrue($passed, 'write-scoped token must clear the scope gate');
        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_read_scoped_token_passes_for_a_read_tool(): void
    {
        $this->mintToken(['mcp:read']);

        $passed = false;
        $response = $this->callTool($this->toolName(readOnly: true), function () use (&$passed) {
            $passed = true;

            return response('ok', 200);
        });

        $this->assertTrue($passed, 'read-scoped token must clear the gate for a read tool');
        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * @param  array<int, string>  $scopes
     */
    private function mintToken(array $scopes): void
    {
        McpTenantToken::query()->create([
            'tenant_id' => app(TenantContext::class)->current(),
            'label' => 'test',
            'token_hash' => hash('sha256', 'plain-test-token'),
            'token_last4' => 'oken',
            'scopes_json' => $scopes,
        ]);
    }

    private function callTool(string $toolName, ?callable $next = null): \Symfony\Component\HttpFoundation\Response
    {
        $request = Request::create('/mcp/kb', 'POST', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer plain-test-token',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'method' => 'tools/call',
            'params' => ['name' => $toolName, 'arguments' => ['project_key' => 'demo']],
        ]));

        return app(EnforceMcpScope::class)->handle(
            $request,
            $next ?? fn () => response('unreached', 500),
        );
    }
}
