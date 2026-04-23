<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Requests\Auth\TwoFactorRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Stub controller for two-factor authentication. PR2 (Phase B) only wires
 * the contract: a feature flag guards the endpoints so the frontend can
 * surface "2FA coming soon" states without the backend blowing up.
 *
 * When AUTH_2FA_ENABLED=false (default) each endpoint returns 501 with a
 * stable error shape. A later PR replaces the stubs with the real TOTP
 * flow (enrollment, recovery codes, challenge/verify).
 */
class TwoFactorController extends Controller
{
    public function enable(Request $request): JsonResponse
    {
        if (! $this->isEnabled()) {
            return $this->notImplemented();
        }

        // Placeholder — real enrollment lands in a later PR.
        return response()->json([
            'status' => 'pending',
            'message' => 'Two-factor enrollment is not yet implemented.',
        ], 501);
    }

    public function verify(TwoFactorRequest $request): JsonResponse
    {
        if (! $this->isEnabled()) {
            return $this->notImplemented();
        }

        return response()->json([
            'status' => 'pending',
            'message' => 'Two-factor verification is not yet implemented.',
        ], 501);
    }

    public function disable(Request $request): JsonResponse
    {
        if (! $this->isEnabled()) {
            return $this->notImplemented();
        }

        return response()->json([
            'status' => 'pending',
            'message' => 'Two-factor disable is not yet implemented.',
        ], 501);
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
}
