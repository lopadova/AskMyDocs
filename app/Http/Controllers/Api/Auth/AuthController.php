<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\TokenRequest;
use App\Models\User;
use App\Services\Auth\UserTeamsResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

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

    /**
     * Issue a Sanctum personal access token for a non-browser client (the
     * Tauri desktop demo). Verifies the credentials WITHOUT opening a session,
     * then returns the plaintext token. The client stores it and authenticates
     * every subsequent call with `Authorization: Bearer <token>`.
     *
     * Mirrors login's failure-only throttle (hit on bad credentials, clear on
     * success) on a separate bucket so the two flows don't interfere.
     */
    public function token(TokenRequest $request): JsonResponse
    {
        $key = $request->throttleKey();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);

            throw ValidationException::withMessages([
                'email' => [__('auth.throttle', ['seconds' => $seconds])],
            ])->status(429);
        }

        $user = User::where('email', (string) $request->validated('email'))->first();

        if ($user === null || ! Hash::check((string) $request->validated('password'), $user->password)) {
            RateLimiter::hit($key);

            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        RateLimiter::clear($key);

        $token = $user->createToken($request->deviceName())->plainTextToken;

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ], 201);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(null, 204);
    }

    /**
     * Revoke the personal access token the caller authenticated with — the
     * Bearer-flow counterpart of logout(). Stateless (no web session/CSRF), so
     * the desktop client can sign out without an XSRF cookie. Session-based
     * callers carry a TransientToken (no delete()), so they no-op safely.
     */
    public function revokeToken(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->json(null, 204);
    }

    public function me(Request $request, UserTeamsResolver $teams): JsonResponse
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
            // R27 — additive: the legacy cross-tenant `projects` list above
            // stays untouched; `teams` groups the same memberships per
            // tenant for the SPA team switcher.
            'teams' => $teams->resolve($user),
            'preferences' => [
                'theme' => 'dark',
                'density' => 'balanced',
                'language' => 'en',
            ],
        ], 200);
    }
}
