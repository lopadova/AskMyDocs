<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
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

        $projects = $user->projectMemberships()
            ->get(['project_key', 'role', 'scope_allowlist'])
            ->map(fn ($membership) => [
                'project_key' => $membership->project_key,
                'role' => $membership->role,
                'scope' => $membership->scope_allowlist ?? [],
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
