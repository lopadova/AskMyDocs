<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use Illuminate\Auth\Events\PasswordReset as PasswordResetEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * JSON-native forgot-password / reset-password for the React SPA.
 *
 * The forgot flow always returns 204 regardless of whether the email is
 * registered. Leaking "user not found" would let attackers enumerate valid
 * accounts. The throttle applied on the route limits brute-force attempts.
 */
class PasswordResetController extends Controller
{
    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        Password::broker()->sendResetLink($request->only('email'));

        // Anti-enumeration: respond identically whether the email exists
        // or not. The broker either dispatches the notification or no-ops.
        return response()->json(null, 204);
    }

    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::broker()->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordResetEvent($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json(null, 204);
        }

        throw ValidationException::withMessages([
            'email' => [__($status)],
        ]);
    }
}
