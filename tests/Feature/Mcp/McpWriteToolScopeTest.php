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
     * Copilot review PR #497 (pullrequestreview-5257728388) — last_used_at
     * used to update only inside the tools/call branch. Since round 8 made
     * every protocol method fully authenticated and scope-checked, a caller
     * that only ever calls initialize/tools/list would never update its
     * token's last_used_at, under-reporting real traffic in the admin token
     * list.
     */
    public function test_last_used_at_is_updated_for_a_non_tools_call_method(): void
    {
        $token = McpTenantToken::query()->create([
            'tenant_id' => app(TenantContext::class)->current(),
            'label' => 'test',
            'token_hash' => hash('sha256', 'plain-test-token'),
            'token_last4' => 'oken',
            'scopes_json' => ['mcp:read'],
        ]);
        $this->assertNull($token->last_used_at);

        $request = Request::create('/mcp/kb', 'POST', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer plain-test-token',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['method' => 'initialize', 'params' => []]));

        $response = app(EnforceMcpScope::class)->handle($request, fn () => response('ok', 200));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotNull($token->fresh()->last_used_at);
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
     * Copilot review PR #497 (pullrequestreview-5257132403,
     * discussion_r4054304571) — TenantContext is a process-scoped
     * singleton; this bare route has no `tenant.resolve` middleware to
     * overwrite it on whatever request runs next in the same process.
     * EnforceMcpScope sets it from the token's tenant but must restore
     * the pre-request value before returning, on EVERY return path — not
     * only the happy one — or a long-running worker (Octane) would leak
     * the MCP tenant into whatever it handles next. Proves both halves:
     * downstream code sees the token's tenant WHILE the request runs, and
     * the pre-request tenant is back once it's done.
     */
    public function test_tenant_context_is_restored_after_a_successful_request(): void
    {
        app(TenantContext::class)->set('pre-existing-tenant');

        McpTenantToken::query()->create([
            'tenant_id' => 'mcp-token-tenant',
            'label' => 'test',
            'token_hash' => hash('sha256', 'restore-success-token'),
            'token_last4' => 'oken',
            'scopes_json' => ['mcp:read'],
        ]);

        $request = Request::create('/mcp/kb', 'POST', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer restore-success-token',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['method' => 'initialize', 'params' => []]));

        $seenTenantDuringRequest = null;
        app(EnforceMcpScope::class)->handle($request, function () use (&$seenTenantDuringRequest) {
            $seenTenantDuringRequest = app(TenantContext::class)->current();

            return response('ok', 200);
        });

        $this->assertSame('mcp-token-tenant', $seenTenantDuringRequest);
        $this->assertSame('pre-existing-tenant', app(TenantContext::class)->current());
    }

    /**
     * Same fix as above, but exercises the scope-denied 403 EARLY-RETURN
     * branch specifically (before `$next($request)` is ever reached) —
     * not the happy path. A naive fix that restores the tenant only right
     * before the final `return $next($request)` at the end of handle()
     * would pass a happy-path-only test while still leaking the tenant on
     * every rejected request, which is most of what an attacker sends.
     */
    public function test_tenant_context_is_restored_after_an_early_return_denial(): void
    {
        app(TenantContext::class)->set('pre-existing-tenant');

        McpTenantToken::query()->create([
            'tenant_id' => 'mcp-token-tenant',
            'label' => 'test',
            'token_hash' => hash('sha256', 'restore-denial-token'),
            'token_last4' => 'oken',
            'scopes_json' => ['mcp:tools:propose'],
        ]);

        $request = Request::create('/mcp/kb', 'POST', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer restore-denial-token',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['method' => 'initialize', 'params' => []]));

        $response = app(EnforceMcpScope::class)->handle($request, fn () => response('unreached', 500));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('pre-existing-tenant', app(TenantContext::class)->current());
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
     * Copilot review PR #497 (pullrequestreview-5257033036,
     * discussion_r4054237132) — the `mcp` limiter's key used to append a
     * `TenantContext::current()` segment. Because `throttle:mcp` runs
     * before `mcp.scope` (this route sets the tenant, nothing upstream
     * does), and `TenantContext` is a process singleton, that segment
     * could carry state left over from a DIFFERENT prior request handled
     * by the same worker — so the same bearer token could land in a
     * different bucket than its own previous requests, resetting its
     * throttle count. Simulates exactly that drift (mutate TenantContext
     * mid-test, standing in for "another request touched this process")
     * and asserts the SAME token is still throttled: the fix keys on the
     * token hash alone, which cannot be perturbed by tenant-context state
     * it never reads.
     */
    public function test_rate_limit_bucket_is_stable_across_tenant_context_drift(): void
    {
        config(['mcp.server.rate_limit_per_minute' => 2]);

        $makeRequest = fn () => $this->withHeader('Authorization', 'Bearer definitely-not-a-real-token')
            ->postJson('/mcp/kb', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'initialize',
                'params' => [],
            ]);

        app(TenantContext::class)->set('tenant-a');
        $first = $makeRequest();
        $second = $makeRequest();

        // Stand in for a different request having run on this same worker
        // between $second and $third and left TenantContext pointing at a
        // different tenant — the pre-fix key would hash this 3rd request
        // into a brand-new "tenant-b" bucket with zero prior hits.
        app(TenantContext::class)->set('tenant-b');
        $third = $makeRequest();

        $this->assertSame(401, $first->getStatusCode());
        $this->assertSame(401, $second->getStatusCode());

        // Same bearer token, only TenantContext drifted -> must still be
        // the SAME rate-limit bucket. A 401 here would mean the tenant
        // segment reset the count, exactly the bypass this fix closes.
        $this->assertSame(429, $third->getStatusCode());
    }

    /**
     * Copilot review PR #497 (pullrequestreview-5257061609,
     * discussion_r4054262640) — after round 8 the `mcp` limiter keyed
     * ONLY on the bearer token hash, which is bypassable: a caller
     * sending a DIFFERENT token value on every request lands each one in
     * a fresh, empty bucket, so the per-token limit never trips no
     * matter how many requests it sends. The fix adds a second limit
     * keyed by source IP. This test rotates the token on every request
     * (so the per-token limit alone — set generous here — never fires)
     * and asserts the IP-keyed limit still throttles by the 3rd request.
     */
    public function test_rate_limit_still_applies_when_bearer_token_is_rotated_every_request(): void
    {
        config([
            'mcp.server.rate_limit_per_minute' => 1000,
            'mcp.server.rate_limit_ip_per_minute' => 2,
        ]);

        $makeRequest = fn (string $token) => $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/mcp/kb', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'initialize',
                'params' => [],
            ]);

        $first = $makeRequest('token-one-never-reused');
        $second = $makeRequest('token-two-never-reused');
        $third = $makeRequest('token-three-never-reused');

        $this->assertSame(401, $first->getStatusCode());
        $this->assertSame(401, $second->getStatusCode());

        // Every request used a DIFFERENT token, so the per-token limit
        // (1000/min) never comes close to tripping. Only the IP-keyed
        // limit (2/min, same test client IP for all three) can explain a
        // 429 here — proving token rotation no longer bypasses throttling.
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
