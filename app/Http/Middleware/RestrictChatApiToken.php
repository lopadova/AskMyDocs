<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/** Chat automation credentials cannot inherit the user's other API surfaces. */
final class RestrictChatApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('api/chat/*') && ($bearer = $request->bearerToken()) !== null) {
            $token = PersonalAccessToken::findToken($bearer);
            abort_if($token !== null && in_array('chat:use', $token->abilities ?? [], true), 403);
        }
        return $next($request);
    }
}
