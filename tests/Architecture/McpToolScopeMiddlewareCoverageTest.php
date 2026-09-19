<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Tests\TestCase;

final class McpToolScopeMiddlewareCoverageTest extends TestCase
{
    public function test_mcp_route_includes_scope_middleware(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../../routes/ai.php');
        // v8.37/W3b round 7 — `auth:sanctum` was stale scaffolding that
        // predates EnforceMcpScope/McpTenantToken (the real, self-sufficient
        // auth mechanism). It rejected every real `askmd_...` McpTenantToken
        // bearer token before `mcp.scope` ever ran, making the route
        // unreachable for real MCP clients. Assert it is gone, not present.
        $this->assertStringNotContainsString('auth:sanctum', $src, 'the MCP route must not require a Sanctum session; EnforceMcpScope is self-sufficient');
        // `throttle:api` was also stale/wrong: no "api" limiter has ever
        // been registered anywhere in this app (the `api` middleware GROUP
        // doesn't throttle by default — Laravel only adds that when
        // `->throttleApi()` is called, which bootstrap/app.php never
        // does). A real request would 500 with "Rate limiter [api] is not
        // defined". `mcp` is a dedicated, registered limiter
        // (AppServiceProvider::registerRateLimiters, config/mcp.php).
        $this->assertStringNotContainsString("throttle:api", $src, 'the "api" rate limiter is never registered in this app; use the dedicated "mcp" limiter');
        $this->assertStringContainsString("->middleware(['mcp.scope', 'throttle:mcp'])", $src);
    }

    /**
     * v8.37/W3b round 7 — routes/ai.php is NOT required anywhere in
     * bootstrap/app.php or elsewhere in the host app: production loading
     * is delegated entirely to `laravel/mcp`'s own McpServiceProvider,
     * which `Route::group([], base_path('routes/ai.php'))`s the file from
     * its own boot() when Composer auto-discovers it. That auto-discovery
     * is what this test needs to protect — if `laravel/mcp` were ever
     * added to composer.json's `extra.laravel.dont-discover`, or dropped
     * as a dependency entirely, `/mcp/kb` would silently stop being
     * registered with no test catching it (every other MCP test invokes
     * the tool class or EnforceMcpScope directly, never the real route —
     * see the two tests above and KbProposeTextCorrectionToolTest's
     * end-to-end case).
     *
     * `laravel/mcp` is deliberately in `require-dev` + `suggest`, NOT
     * `require` (per CLAUDE.md §1) — whether every real deploy of this
     * app actually installs dev dependencies, and therefore whether
     * `/mcp/kb` is reachable outside CI/local, is a separate,
     * pre-existing question this test does not attempt to answer or
     * change; it only guards the classification that IS declared.
     */
    public function test_laravel_mcp_package_auto_discovery_is_not_disabled(): void
    {
        $composerJson = json_decode(
            (string) file_get_contents(__DIR__ . '/../../composer.json'),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        $dontDiscover = (array) ($composerJson['extra']['laravel']['dont-discover'] ?? []);
        $declaredIn = array_filter(
            ['require', 'require-dev'],
            static fn (string $section): bool => array_key_exists('laravel/mcp', (array) ($composerJson[$section] ?? []))
        );

        $this->assertNotEmpty(
            $declaredIn,
            'laravel/mcp must be a Composer dependency (require or require-dev) — its McpServiceProvider is the only thing that loads routes/ai.php (/mcp/kb)'
        );
        $this->assertNotContains(
            'laravel/mcp',
            $dontDiscover,
            'disabling package auto-discovery for laravel/mcp silently 404s /mcp/kb — nothing else loads routes/ai.php'
        );
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
