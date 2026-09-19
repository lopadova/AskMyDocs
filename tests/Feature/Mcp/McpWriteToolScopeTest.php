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
     * v8.37/W3b round 8 (Copilot PR #496 must-fix) — KbProposeTextCorrectionTool
     * was reachable ONLY with `mcp:tools:write` (absent #[IsReadOnly] and not
     * in PROPOSE_TOOL_NAMES), but a newly-minted McpTenantToken defaults to
     * `['mcp:read', 'mcp:tools:propose']` (McpTenantTokenController::store())
     * — never `mcp:tools:write`. A default token could never call it.
     */
    public function test_propose_scoped_token_passes_the_scope_gate_for_kb_propose_text_correction_tool(): void
    {
        $this->mintToken(['mcp:read', 'mcp:tools:propose']);

        $passed = false;
        $response = $this->callTool((new \App\Mcp\Tools\KbProposeTextCorrectionTool())->name(), function () use (&$passed) {
            $passed = true;

            return response('ok', 200);
        });

        $this->assertTrue($passed, 'a propose-scoped token must clear the scope gate for the propose tool');
        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * v8.37/W3b round 8 (Copilot PR #496 must-fix) — proves the OTHER 4
     * pre-existing PROPOSE_TOOL_NAMES entries are genuinely enforced now,
     * not just this PR's new one. Before the "Tool" suffix fix, none of the
     * 4 keys ever matched a real tool name, so a read-only-scoped token
     * (which should be REFUSED — proposing is an elevated capability) was
     * silently accepted for every one of them.
     */
    public function test_read_scoped_token_is_denied_an_existing_propose_tool(): void
    {
        $this->mintToken(['mcp:read']);

        $response = $this->callTool((new \App\Mcp\Tools\KbListDanglingWikilinksTool())->name());

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('mcp_scope_missing', (string) $response->getContent());
        $this->assertStringContainsString('mcp:tools:propose', (string) $response->getContent());
    }

    public function test_propose_scoped_token_passes_the_scope_gate_for_an_existing_propose_tool(): void
    {
        $this->mintToken(['mcp:read', 'mcp:tools:propose']);

        $passed = false;
        $response = $this->callTool((new \App\Mcp\Tools\KbListDanglingWikilinksTool())->name(), function () use (&$passed) {
            $passed = true;

            return response('ok', 200);
        });

        $this->assertTrue($passed);
        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * v8.37/W3b round 8 (Copilot PR #496 must-fix) — the previous handle()
     * only validated the bearer token for `tools/call`. Any OTHER MCP
     * protocol method (`initialize`, `tools/list`, ...) passed through
     * completely unauthenticated once `auth:sanctum` was removed from
     * routes/ai.php in round 7 — the token is now required for every
     * method.
     */
    public function test_a_non_tools_call_method_still_requires_a_valid_token(): void
    {
        $request = Request::create('/mcp/kb', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['method' => 'initialize', 'params' => []]));

        $response = app(EnforceMcpScope::class)->handle($request, fn () => response('unreached', 500));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertStringContainsString('mcp_token_required', (string) $response->getContent());
    }

    /**
     * v8.37/W3b round 8 (Copilot PR #496 must-fix) — this bare route has no
     * tenant.resolve middleware, so TenantContext::current() was ALWAYS
     * 'default' before this middleware ran; comparing a real token's tenant
     * against that meant every non-default-tenant token was rejected with
     * mcp_tenant_mismatch. The token itself must establish tenant identity.
     */
    public function test_a_non_default_tenant_token_establishes_that_tenant_context(): void
    {
        McpTenantToken::query()->create([
            'tenant_id' => 'other-tenant',
            'label' => 'test',
            'token_hash' => hash('sha256', 'other-tenant-token'),
            'token_last4' => 'oken',
            'scopes_json' => ['mcp:read'],
        ]);

        $request = Request::create('/mcp/kb', 'POST', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer other-tenant-token',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['method' => 'initialize', 'params' => []]));

        $seenTenant = null;
        app(EnforceMcpScope::class)->handle($request, function () use (&$seenTenant) {
            $seenTenant = app(TenantContext::class)->current();

            return response('ok', 200);
        });

        $this->assertSame('other-tenant', $seenTenant);
    }

    /**
     * Copilot review PR #497 (pullrequestreview-5256772155) — before this
     * fix, `mcp:read` was checked ONLY inside the `tools/call` branch, so a
     * token minted with an elevated-but-not-baseline scope (or an empty/
     * misconfigured scopes_json that happened to still be a valid row)
     * could reach `initialize`/`tools/list`/every other protocol method
     * regardless of its scopes. `mcp:read` is now the baseline for the
     * transport as a whole.
     */
    public function test_a_token_without_mcp_read_scope_is_denied_a_non_tools_call_protocol_method(): void
    {
        $this->mintToken(['mcp:tools:propose']);

        $request = Request::create('/mcp/kb', 'POST', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer plain-test-token',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['method' => 'initialize', 'params' => []]));

        $response = app(EnforceMcpScope::class)->handle($request, fn () => response('unreached', 500));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('mcp_scope_missing', (string) $response->getContent());
        $this->assertStringContainsString('mcp:read', (string) $response->getContent());
    }

    /**
     * McpConnectCommand sends an explicit X-Tenant-Id header alongside the
     * bearer token (its `--tenant=` option) — kept as a defense-in-depth
     * sanity check: a caller pointed at the wrong token/tenant pairing is
     * rejected outright rather than silently proceeding under the token's
     * tenant.
     */
    public function test_a_mismatched_x_tenant_id_header_is_rejected(): void
    {
        $this->mintToken(['mcp:read']);

        $request = Request::create('/mcp/kb', 'POST', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer plain-test-token',
            'HTTP_X_TENANT_ID' => 'a-different-tenant',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['method' => 'initialize', 'params' => []]));

        $response = app(EnforceMcpScope::class)->handle($request, fn () => response('unreached', 500));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('mcp_tenant_mismatch', (string) $response->getContent());
    }

    /**
     * Copilot review PR #497 (pullrequestreview-5256955613) — `throttle:mcp`
     * used to run AFTER `mcp.scope` in routes/ai.php, so a request
     * `EnforceMcpScope` rejects (invalid token here) short-circuited the
     * pipeline before the rate limiter middleware ever executed —
     * unthrottled token-guessing against this route. Drives real HTTP
     * requests through the actual route (`postJson`, not `EnforceMcpScope`
     * invoked standalone like every other test in this file) so the
     * middleware ORDER itself is what's under test, not just the handler.
     */
    public function test_rejected_requests_are_rate_limited_not_bypassed(): void
    {
        config(['mcp.server.rate_limit_per_minute' => 2]);

        $makeRequest = fn () => $this->withHeader('Authorization', 'Bearer definitely-not-a-real-token')
            ->postJson('/mcp/kb', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'initialize',
                'params' => [],
            ]);

        $first = $makeRequest();
        $second = $makeRequest();
        $third = $makeRequest();

        $this->assertSame(401, $first->getStatusCode());
        $this->assertStringContainsString('mcp_token_invalid', (string) $first->getContent());
        $this->assertSame(401, $second->getStatusCode());

        // Same bearer token on every call -> same rate-limit bucket. If
        // throttle:mcp ran AFTER mcp.scope (the pre-fix order), this 3rd
        // request would still be 401 — EnforceMcpScope rejecting it before
        // the limiter middleware ever got a chance to count it, let alone
        // block it.
        $this->assertSame(429, $third->getStatusCode());
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
