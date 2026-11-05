<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Requests\Auth\TwoFactorRequest;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class TwoFactorController extends Controller
{
    public function __construct(private readonly TwoFactorService $twoFactor) {}

    public function enable(Request $request): JsonResponse
    {
        if (! $this->isEnabled()) {
            return $this->notImplemented();
        }

        $data = $this->twoFactor->begin($this->user($request));

        return response()->json([
            'status' => 'pending',
            ...$data,
        ]);
    }

    public function verify(TwoFactorRequest $request): JsonResponse
    {
        if (! $this->isEnabled()) {
            return $this->notImplemented();
        }

        $user = $this->user($request);
        $code = (string) $request->validated('code');

        if ($user->two_factor_enabled_at !== null) {
            abort_unless($this->twoFactor->verify($user, $code), 422, 'The provided two-factor code is invalid.');

            return response()->json(['status' => 'verified']);
        }

        $codes = $this->twoFactor->confirm($user, $code);

        return response()->json([
            'status' => 'enabled',
            'recovery_codes' => $codes,
        ]);
    }

    public function disable(Request $request): JsonResponse
    {
        if (! $this->isEnabled()) {
            return $this->notImplemented();
        }

        $code = $request->input('code');
        if (! is_string($code) || ! preg_match('/^[0-9A-Za-z _-]{1,32}$/', $code)) {
            return response()->json([
                'message' => 'The provided two-factor code is invalid.',
            ], 422);
        }

        $this->twoFactor->disable($this->user($request), $code);

        return response()->json(['status' => 'disabled']);
    }

    private function isEnabled(): bool
    {
        return (bool) config('auth.two_factor.enabled', false);
    }

    private function notImplemented(): JsonResponse
    {
        return response()->json([
            'message' => 'Two-factor authentication is not yet available.',
        ], 501);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
