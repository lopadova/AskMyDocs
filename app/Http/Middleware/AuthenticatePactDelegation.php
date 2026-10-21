<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Auth\OAuthAccessPolicy;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Padosoft\AiActCompliance\MultiTenancy\Services\TenantContext as ComplianceTenantContext;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext as ConnectorTenantContext;
use Symfony\Component\HttpFoundation\Response;

/** PACT accepts only its bound Bearer grant, never a session or legacy API key. */
final class AuthenticatePactDelegation
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = app(TenantContext::class);
        $previous = $tenant->current();
        $connectorTenant = app(ConnectorTenantContext::class);
        $previousConnector = $connectorTenant->current();
        $complianceTenant = app()->bound(ComplianceTenantContext::class) ? app(ComplianceTenantContext::class) : null;
        $previousCompliance = $complianceTenant?->current();
        $guard = Auth::getDefaultDriver();
        $resolver = $request->getUserResolver();
        try {
            abort_if((int) $request->server('CONTENT_LENGTH', 0) > 1048576 || strlen($request->getContent()) > 1048576, 413);
            $token = $request->bearerToken() ? PersonalAccessToken::findToken($request->bearerToken()) : null;
            $user = $token?->tokenable;
            $ttl = (int) config('sanctum.expiration');
            $valid = $token !== null && $token->expires_at !== null && $token->expires_at->gt(now())
                && ($ttl <= 0 || $token->created_at->gt(now()->subMinutes($ttl)));
            if (! $user instanceof User || ! in_array(OAuthAccessPolicy::TOKEN_MARKER, $token->abilities ?? [], true)
                || ! app(OAuthAccessPolicy::class)->validate($token, $valid, $request)
                || $request->attributes->get('pact.grant') === null) {
                return response()->json(['error' => 'invalid_token'], 401,
                    ['WWW-Authenticate' => 'Bearer realm="AskMyDocs PactAuth", error="invalid_token"', 'Cache-Control' => 'no-store']);
            }
            Auth::shouldUse('sanctum');
            Auth::guard('sanctum')->setUser($user->withAccessToken($token));
            $request->setUserResolver(fn () => $user);
            $token->forceFill(['last_used_at' => now()])->save();
            return $next($request);
        } finally {
            $tenant->set($previous);
            $connectorTenant->set($previousConnector);
            $complianceTenant?->set($previousCompliance);
            Auth::guard('sanctum')->forgetUser();
            Auth::shouldUse($guard);
            $request->setUserResolver($resolver);
        }
    }
}
