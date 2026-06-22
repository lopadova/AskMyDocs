<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * EnforceTokenAbility — least-privilege ability gate for Bearer personal
 * access tokens (PATs) on DUAL-AUTH routes.
 *
 * The /kb/* routes are reached by BOTH:
 *   - the cookie-based SPA (Sanctum stateful session → currentAccessToken()
 *     is a `TransientToken`, which is NOT a PersonalAccessToken), and
 *   - non-browser Bearer PATs (the Tauri desktop demo).
 *
 * Sanctum's stock `ability` / `abilities` middleware throws as soon as
 * currentAccessToken() is null or transient, so it cannot guard a route the
 * session SPA also reaches — it would 401 every cookie-authenticated request.
 *
 * This guard is PAT-scoped by design: it constrains ONLY a request that
 * authenticated with a real `PersonalAccessToken`, and passes every session /
 * transient / unauthenticated request through untouched (those are already
 * governed by the route's own `auth:sanctum` + `tenant.authorize` + RBAC
 * stack). A desktop PAT minted without the required ability is rejected with
 * 403 — so a token scoped to `kb:read` + `kb:chat` (see
 * AuthController::token()) cannot reach a route gated on a different ability.
 *
 * Usage: `->middleware('token.ability:kb:read')`. Any ONE of the listed
 * abilities satisfies the gate (mirrors Sanctum's `CheckForAnyAbility`); a
 * wildcard `*` token passes every check (HasAbilities::can()).
 */
final class EnforceTokenAbility
{
    public function handle(Request $request, Closure $next, string ...$abilities): Response
    {
        $token = $request->user()?->currentAccessToken();

        // Only real Bearer PATs are scoped here. Session/SPA (TransientToken)
        // and unauthenticated requests fall through to the route's own gates.
        if (! $token instanceof PersonalAccessToken) {
            return $next($request);
        }

        foreach ($abilities as $ability) {
            if ($token->can($ability)) {
                return $next($request);
            }
        }

        return response()->json([
            'error' => 'token_ability_forbidden',
            'message' => 'This access token is not scoped for the requested action.',
        ], Response::HTTP_FORBIDDEN);
    }
}
