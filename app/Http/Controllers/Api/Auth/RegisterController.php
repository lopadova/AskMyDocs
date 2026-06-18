<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Auth;

use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use App\Services\Invite\CodeValidator;
use App\Services\Invite\RedemptionService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Public self-registration, invite-gated. Paired with the Sanctum stateful
 * session like AuthController@login: the caller primes the XSRF cookie via
 * GET /sanctum/csrf-cookie, then POSTs here.
 *
 * Flow:
 *   1. RegisterRequest enforces the `invitation_required` gate — the code field
 *      is mandatory (and shape-checked) when the gate is on, so it can never be
 *      bypassed by omission.
 *   2. Advisory pre-check (CodeValidator) BEFORE creating any user — an invalid
 *      / expired / revoked code returns 422 on the `code` field with no orphan
 *      account left behind.
 *   3. Inside ONE transaction: create the user, then atomically redeem the code
 *      via RedemptionService (which also provisions role + project access from
 *      the key's grant). If the redeem loses the race (the last seat went
 *      between the pre-check and here), roll the whole thing back and surface
 *      the redemption error with its own status — never a half-created account.
 *   4. Log the new user in and regenerate the session, mirroring login.
 *
 * Tenant note: registration runs under the resolved TenantContext (the
 * v3-compatible `default` tenant unless the request resolves another). Invite
 * codes are looked up within that tenant — a tenant-routing layer for
 * multi-tenant public signup is a separate concern.
 */
final class RegisterController extends Controller
{
    public function __construct(
        private readonly RedemptionService $redemption,
        private readonly CodeValidator $validator,
        private readonly TenantContext $tenant,
    ) {
    }

    public function register(RegisterRequest $request): JsonResponse
    {
        $rawCode = $request->inviteCode();
        $tenantId = $this->tenant->current();

        // (2) Pre-check before touching the users table. Each failure maps to
        // its own status via RedemptionError::httpStatus() (R14) — invalid 422,
        // expired/revoked 410, exhausted 409 — never a blanket 422.
        if ($rawCode !== null) {
            $pre = $this->validator->validate($rawCode, $tenantId);
            if (! $pre->ok) {
                return $this->codeError($pre->error);
            }
        }

        // (3) Create + redeem atomically; roll back on a redemption race.
        DB::beginTransaction();
        try {
            $user = User::create([
                'name' => (string) $request->string('name'),
                'email' => (string) $request->string('email'),
                'password' => Hash::make((string) $request->string('password')),
                'is_active' => true,
            ]);

            if ($rawCode !== null) {
                $result = $this->redemption->redeem($rawCode, $user, [
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ]);

                if (! $result->ok) {
                    DB::rollBack();

                    return $this->codeError($result->error);
                }
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        // (4) Sign in, mirroring AuthController@login.
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ], 201);
    }

    /**
     * Build the field-shaped error response for a failed code, with the status
     * that matches the failure (RedemptionError::httpStatus()). The `errors.code`
     * shape lets the SPA render the message next to the code input.
     */
    private function codeError(\App\Services\Invite\Support\RedemptionError $error): JsonResponse
    {
        $message = match ($error->value) {
            'invalid' => 'This invite code is not valid.',
            'expired' => 'This invite code has expired.',
            'exhausted' => 'This invite code has no remaining uses.',
            'revoked' => 'This invite code has been revoked.',
            'ineligible' => 'This invite code cannot be used for this account.',
            'rate_limited' => 'Too many attempts — please try again shortly.',
            default => 'This invite code could not be redeemed.',
        };

        return response()->json([
            'message' => $message,
            'errors' => ['code' => [$message]],
        ], $error->httpStatus());
    }
}
