<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Auth\OAuthAccessPolicy;
use App\Services\Mcp\ConnectedProjectWorkspace;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateMcpProject
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = app(TenantContext::class);
        $previous = $tenant->current();
        $guard = Auth::getDefaultDriver();
        $audit = null;
        try {
            abort_if((int) $request->server('CONTENT_LENGTH', 0) > 14000000 || strlen($request->getContent()) > 14000000, 413);
            $token = $request->bearerToken() ? PersonalAccessToken::findToken($request->bearerToken()) : null;
            $user = $token?->tokenable;
            $valid = $token !== null && ($token->expires_at === null || $token->expires_at->gt(now()));
            $ttl = (int) config('sanctum.expiration');
            $valid = $valid && ($ttl <= 0 || $token->created_at->gt(now()->subMinutes($ttl)));
            if (! $user instanceof User || ! in_array(OAuthAccessPolicy::TOKEN_MARKER, $token->abilities ?? [], true)
                || ! app(OAuthAccessPolicy::class)->validate($token, $valid, $request)) {
                $url = rtrim((string) config('app.url'), '/').'/.well-known/oauth-protected-resource/'.$request->path();
                return response()->json(['error' => 'invalid_token'], 401, [
                    'WWW-Authenticate' => 'Bearer resource_metadata="'.$url.'", scope="mcp:read mcp:ingest"',
                    'Cache-Control' => 'no-store',
                ]);
            }
            // A dedicated guard prevents session cookies from satisfying MCP auth.
            Auth::shouldUse('sanctum');
            Auth::guard('sanctum')->setUser($user->withAccessToken($token));
            $request->setUserResolver(fn () => $user);
            $method = (string) $request->input('method', '');
            $action = $request->route('action');
            if ($action !== null) {
                $scope = $action === 'imports' ? 'mcp:ingest' : 'mcp:read';
                abort_unless(in_array($scope, $request->attributes->get('mcp.grant')->scopes, true), 403);
                $audit = app(ConnectedProjectWorkspace::class)->audit('mcp.api.'.$action, 'started');
            }
            if ($method === 'tools/call') {
                $tool = (string) $request->input('params.name', '');
                $allowed = ConnectedProjectWorkspace::TOOL_SCOPES[$tool] ?? null;
                $grant = $request->attributes->get('mcp.grant');
                if ($allowed === null || ! in_array($allowed, $grant->scopes, true)) {
                    app(ConnectedProjectWorkspace::class)->audit('mcp.tool.denied', 'rejected', ['tool_hash' => hash('sha256', $tool)]);
                    return response()->json(['error' => 'insufficient_scope'], 403);
                }
                $audit = app(ConnectedProjectWorkspace::class)->audit('mcp.'.$tool, 'started');
            }
            $token->forceFill(['last_used_at' => now()])->save();
            $response = $next($request);
            if ($audit) {
                $body = json_decode($response->getContent() ?: '{}', true);
                $failed = ! $response->isSuccessful() || isset($body['error']) || ($body['result']['isError'] ?? false);
                $audit->update(['status' => $failed ? 'failed' : 'completed', 'completed_at' => now()]);
            }
            return $response;
        } catch (\Throwable $exception) {
            if ($audit) $audit->update(['status' => 'failed', 'completed_at' => now()]);
            throw $exception;
        } finally {
            $tenant->set($previous);
            Auth::guard('sanctum')->forgetUser();
            Auth::shouldUse($guard);
        }
    }
}
