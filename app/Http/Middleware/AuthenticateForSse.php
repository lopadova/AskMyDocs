<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Http\JsonResponse;

/**
 * v4.0/W3.1 — `auth` middleware variant for SSE streaming routes.
 *
 * The default `Authenticate` middleware emits a 302 redirect to
 * `/login` for any request that doesn't `expectsJson()` (Accept
 * containing `application/json`). Server-sent-event clients send
 * `Accept: text/event-stream`, which DOES NOT imply JSON, so a
 * session-expired streaming request would land as a 302 + HTML
 * redirect that the client can't parse and may follow as a stream
 * of HTML.
 *
 * This middleware runs the normal `web` guard auth check and:
 *  - on success: passes through (the controller takes over);
 *  - on failure: returns a deterministic JSON 401 the streaming
 *    client can parse and react to (typically by triggering the
 *    SPA's auth bootstrap to re-establish the session and retry).
 *
 * Apply via the route alias `auth.sse` (registered in
 * `bootstrap/app.php`'s middleware aliases). The streaming route
 * uses this middleware INSTEAD OF `auth`; all other conversation
 * routes keep `auth` because they're hit by the synchronous SPA
 * request layer that handles the 302 → /login redirect natively.
 */
final class AuthenticateForSse extends Authenticate
{
    /**
     * Override `redirectTo()` to return null. When this middleware's
     * `authenticate()` throws `AuthenticationException` and Laravel's
     * exception handler picks it up, it checks `redirectTo()` for a
     * URL — null causes the handler to emit a 401 JSON response
     * instead of issuing a redirect. This is the cleanest way to
     * reach the JSON-401 path without monkey-patching the global
     * exception handler.
     */
    protected function redirectTo($request): ?string
    {
        return null;
    }

    /**
     * Belt-and-braces: even if a future Laravel handler change starts
     * issuing redirects despite a null `redirectTo()`, this catches
     * the `AuthenticationException` directly and returns the JSON
     * 401 response ourselves. The middleware contract becomes:
     * "unauthenticated SSE request → 401 JSON, period".
     */
    public function handle($request, Closure $next, ...$guards): mixed
    {
        try {
            return parent::handle($request, $next, ...$guards);
        } catch (AuthenticationException) {
            return new JsonResponse(['message' => 'Unauthenticated.'], 401);
        }
    }
}
