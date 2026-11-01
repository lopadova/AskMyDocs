<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\TransientToken;
use Symfony\Component\HttpFoundation\Response;

/** A chat automation token is bound to one tenant, independently of membership. */
final class ChatApiTokenAccess
{
    public function handle(Request $request, Closure $next, string $authentication = 'bearer'): Response
    {
        $token = $request->user()?->currentAccessToken();
        $session = $authentication === 'session' && $request->bearerToken() === null && $token instanceof TransientToken;
        $bearer = $token instanceof PersonalAccessToken
            && $request->bearerToken() !== null && $token->can('chat:use')
            && $token->can('chat:tenant:'.app(TenantContext::class)->current());
        abort_unless($request->user()?->is_active && ($session || $bearer), 403);

        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }
}
