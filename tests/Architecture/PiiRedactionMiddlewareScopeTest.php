<?php

declare(strict_types=1);

namespace Tests\Architecture;

use App\Http\Middleware\RedactChatPii;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Tests\TestCase;

/**
 * Architecture invariant for the W4.1 PII redaction integration:
 *
 * The `redact-chat-pii` middleware (registered in `bootstrap/app.php`,
 * implemented in `App\Http\Middleware\RedactChatPii`) MUST be bound
 * ONLY to the two chat-message persistence endpoints:
 *
 *   - POST /conversations/{conversation}/messages         (sync)
 *   - POST /conversations/{conversation}/messages/stream  (SSE)
 *
 * It MUST NOT leak onto:
 *
 *   - /admin/*               — admin-curated forms (RBAC dialogs, settings)
 *   - /api/admin/*           — admin REST API (insights, dashboards, log viewer)
 *   - /api/kb/ingest         — operator-curated canonical content (R10)
 *   - /api/kb/ingest-folder
 *   - /api/kb/delete         — destructive paths (deleting redacted text
 *                              wouldn't match the original-text doc id)
 *   - /api/kb/promotion/*    — canonical promotion staging
 *   - /testing/*             — env-gated test harness routes
 *
 * Redacting curator-supplied content would silently corrupt the canonical
 * KB pipeline (`KbIngestController` writes to disk, `DocumentIngestor`
 * computes content_hash on the redacted bytes, etc.). Redacting admin
 * forms would mangle role names / project keys / config values. The
 * narrow scope is therefore part of the contract and gated by this
 * test on every CI run.
 */
final class PiiRedactionMiddlewareScopeTest extends TestCase
{
    private const FORBIDDEN_PREFIXES = [
        'admin/',
        'api/admin/',
        'api/kb/ingest',
        'api/kb/delete',
        'api/kb/promotion',
        'testing/',
    ];

    public function test_redact_chat_pii_middleware_is_bound_to_exactly_two_chat_routes(): void
    {
        $boundRoutes = $this->routesWithMiddleware(RedactChatPii::class);

        $this->assertNotEmpty(
            $boundRoutes,
            'Expected redact-chat-pii middleware to be bound to at least one route — '
            .'route binding may have been lost during a refactor.'
        );

        $uris = array_map(static fn (Route $r): string => $r->uri(), $boundRoutes);
        sort($uris);

        $this->assertSame(
            [
                'conversations/{conversation}/messages',
                'conversations/{conversation}/messages/stream',
            ],
            $uris,
            'redact-chat-pii must be bound to EXACTLY the two chat-message routes — '
            .'any additional binding risks redacting curator/admin content.'
        );
    }

    public function test_redact_chat_pii_middleware_is_not_bound_to_forbidden_routes(): void
    {
        $boundRoutes = $this->routesWithMiddleware(RedactChatPii::class);

        // Pin the assertion count so a regression that empties the binding
        // map doesn't slip through as a "no forbidden bindings → vacuous pass".
        $this->assertNotEmpty(
            $boundRoutes,
            'Expected at least one route to carry redact-chat-pii. Empty bindings would pass this test vacuously.'
        );

        foreach ($boundRoutes as $route) {
            $uri = $route->uri();
            foreach (self::FORBIDDEN_PREFIXES as $forbidden) {
                $this->assertStringStartsNotWith(
                    $forbidden,
                    $uri,
                    sprintf(
                        'Route [%s] carries redact-chat-pii but matches forbidden prefix [%s]. '
                        .'Curator/admin content must NEVER be redacted; '
                        .'the redactor is for end-user chat input only.',
                        $uri,
                        $forbidden,
                    ),
                );
            }
        }
    }

    /**
     * Find every registered Route bound to the given middleware. We
     * check BOTH the raw alias name (`redact-chat-pii`) AND the
     * fully-qualified class name — under Testbench `gatherMiddleware()`
     * does not always resolve aliases through the kernel's
     * MiddlewareAliases registry, so the route's middleware list can
     * still carry the alias string verbatim. Production deployment
     * resolves to FQCN; checking both gives a unified test that passes
     * in either environment.
     *
     * @return list<Route>
     */
    private function routesWithMiddleware(string $fqcn): array
    {
        $alias = 'redact-chat-pii';

        /** @var Router $router */
        $router = $this->app->make('router');

        $matches = [];
        foreach ($router->getRoutes() as $route) {
            /** @var Route $route */
            $explicit = (array) $route->middleware();
            $gathered = (array) $route->gatherMiddleware();
            $bag = array_merge($explicit, $gathered);
            if (in_array($alias, $bag, true) || in_array($fqcn, $bag, true)) {
                $matches[] = $route;
            }
        }

        return $matches;
    }
}
