<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware: reject the request when the authenticated user has
 * no membership in the project referenced by the current request.
 *
 * Project key resolution order (first hit wins):
 *   1. Route parameter (default name `project_key`, overridable).
 *   2. Request input (`$request->input($paramName)`).
 *   3. Header `X-Project-Key`.
 *
 * Pass-through policy (fail-open, NOT fail-closed):
 *   - RBAC_ENFORCED=false → pass.
 *   - Unauthenticated request → pass (let the upstream auth middleware
 *     produce the 401; mixing responsibilities here would hide bugs).
 *   - No project key in the request → pass (used on shared routes that
 *     don't care about project scoping).
 *   - User holds `kb.read.any` → pass.
 *
 * Otherwise: 403 with a structured JSON payload the React SPA can render.
 */
class EnsureProjectAccess
{
    public function handle(Request $request, Closure $next, string $paramName = 'project_key'): Response
    {
        if (! config('rbac.enforced', true)) {
            return $next($request);
        }

        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        $projectKey = $this->resolveProjectKey($request, $paramName);

        if ($projectKey === null) {
            return $next($request);
        }

        if ($user->can('kb.read.any')) {
            return $next($request);
        }

        if ($this->userHasProject($user, $projectKey)) {
            return $next($request);
        }

        return response()->json([
            'message' => "You do not have access to project {$projectKey}.",
            'project_key' => $projectKey,
        ], 403);
    }

    private function resolveProjectKey(Request $request, string $paramName): ?string
    {
        $routeValue = $request->route($paramName);

        if (is_string($routeValue) && $routeValue !== '') {
            return $routeValue;
        }

        $inputValue = $request->input($paramName);

        if (is_string($inputValue) && $inputValue !== '') {
            return $inputValue;
        }

        $headerValue = $request->header('X-Project-Key');

        if (is_string($headerValue) && $headerValue !== '') {
            return $headerValue;
        }

        return null;
    }

    private function userHasProject(User $user, string $projectKey): bool
    {
        return in_array($projectKey, $user->allowedProjects(), true);
    }
}
