<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Requests\Auth\LoginRequest;
use App\Models\KnowledgeDocument;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * JSON-native login / logout / me endpoints for the React SPA. Paired with
 * Sanctum stateful sessions, so callers must first hit GET /sanctum/csrf-cookie
 * to prime the XSRF-TOKEN cookie before POSTing here.
 *
 * The legacy Blade flow in App\Http\Controllers\Auth\LoginController keeps
 * working for the existing UI and will be retired in a later phase.
 */
class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $key = $request->throttleKey();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);

            throw ValidationException::withMessages([
                'email' => [__('auth.throttle', ['seconds' => $seconds])],
            ])->status(429);
        }

        if (! Auth::guard('web')->attempt($request->credentials(), $request->boolean('remember'))) {
            RateLimiter::hit($key);

            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        $user = $request->user();

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'abilities' => [],
        ], 200);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(null, 204);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Real per-project doc counts, scoped to the active tenant (R30).
        // One grouped query keyed by project_key → O(1) lookup in the map().
        $tenantId = app(TenantContext::class)->current();
        $docCounts = KnowledgeDocument::query()
            ->forTenant($tenantId)
            ->selectRaw('project_key, count(*) as aggregate')
            ->groupBy('project_key')
            ->pluck('aggregate', 'project_key');

        $projects = $user->projectMemberships()
            ->get(['project_key', 'role', 'scope_allowlist'])
            ->map(fn ($membership) => [
                'project_key' => $membership->project_key,
                // Human label derived BE-side so the FE no longer needs the
                // hard-coded seed mock (R18 — derive from the real domain).
                'label' => Str::headline($membership->project_key),
                'role' => $membership->role,
                'scope' => $membership->scope_allowlist ?? [],
                'doc_count' => (int) ($docCounts[$membership->project_key] ?? 0),
            ])
            ->values();

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at,
            ],
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
            'projects' => $projects,
            'preferences' => [
                'theme' => 'dark',
                'density' => 'balanced',
                'language' => 'en',
            ],
        ], 200);
    }
}
