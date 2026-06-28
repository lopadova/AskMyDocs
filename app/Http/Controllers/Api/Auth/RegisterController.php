<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Padosoft\Invitations\Contracts\TenantResolver;
use Padosoft\Invitations\Services\CodeValidator;
use Padosoft\Invitations\Services\RedemptionService;
use Padosoft\Invitations\Support\RedemptionError;

/**
 * JSON-native, invite-only sign-up for the React SPA.
 *
 * Paired with Sanctum stateful sessions (the route lives in the `web`
 * middleware group, see routes/api.php), so the SPA primes the XSRF-TOKEN
 * cookie via GET /sanctum/csrf-cookie before POSTing here.
 *
 * Flow (a thin HTTP adapter over the shared invite core — R44):
 *   1. Pre-validate the invite code against {@see CodeValidator} BEFORE
 *      creating the account, so an invalid / expired / exhausted code never
 *      mints an orphan user.
 *   2. Create the user (no role yet).
 *   3. Authoritatively redeem the code via {@see RedemptionService} — an atomic
 *      conditional UPDATE plus the tagged provisioners (Spatie role + project
 *      membership from the invite grant). Redeem runs OUTSIDE any DB
 *      transaction BY DESIGN: the package's compensation path issues follow-up
 *      queries after catching a UNIQUE-violation QueryException, and a wrapping
 *      transaction would poison those on PostgreSQL (a constraint violation
 *      aborts the connection for the rest of the transaction). The package's
 *      own RedemptionController calls redeem() with no surrounding transaction
 *      for exactly this reason. On an exhausted-between-checks race the
 *      brand-new account is force-deleted so the invite-only invariant holds
 *      (no role/membership is provisioned on the failure path, and User has no
 *      create-observer, so the row is the only artifact).
 *   4. Floor the account at `viewer` (layered on any grant role the redeem
 *      already provisioned — GRANT-never-revoke), then open the session
 *      (login + regenerate) and fire the standard `Registered` event.
 *
 * Every invite-code failure is surfaced as a 422 field error on `invite_code`
 * (R14 — never 200-with-empty) so the SPA shows it under the code input; the
 * machine-readable RedemptionError stays English (R24), only the body is
 * human-facing.
 *
 * R44 — registration is intentionally HTTP-only: it mints a stateful browser
 * session, which has no meaningful Artisan/MCP analogue. The invite core it
 * drives ({@see CodeValidator} / {@see RedemptionService}) already exposes the
 * PHP + MCP surfaces, and operator-side account creation lives on the admin
 * user API.
 */
class RegisterController extends Controller
{
    public function __construct(
        private readonly CodeValidator $validator,
        private readonly RedemptionService $redemption,
        private readonly TenantResolver $tenant,
    ) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();
        $code = (string) $data['invite_code'];
        $tenant = $this->tenant->current();

        // 1. Invite-only gate — fail fast on a bad code before touching `users`.
        $check = $this->validator->validate($code, $tenant);
        if (! $check->ok) {
            throw $this->inviteCodeError($check->error);
        }

        // 2. Create the account (no role yet — see step 4).
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        // 3. Authoritatively redeem, OUTSIDE any transaction (see class
        // docblock). `already` is the idempotent-success branch, not a failure.
        $result = $this->redemption->redeem($code, $user, [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
        if (! $result->ok && ! $result->already) {
            // Exhausted-between-checks race: drop the brand-new account so the
            // invite-only invariant never leaves a user who consumed no code.
            $user->forceDelete();
            throw $this->inviteCodeError($result->error);
        }

        // 4. Floor the account at 'viewer' (layered on any grant role redeem
        // already provisioned — GRANT-never-revoke). Done post-redeem so the
        // failure path above never has a role pivot to clean up.
        $user->assignRole('viewer');

        // Open the SPA session. Auth::login fires the Login event and the
        // explicit Registered event fires the registration listeners; the
        // invitations CompletePendingRedemption listener is a safe no-op here
        // because we redeemed directly (no code was stashed → read-and-forget).
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        event(new Registered($user));

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'abilities' => [],
        ], 201);
    }

    /**
     * Map a RedemptionError onto a 422 validation error attached to the
     * `invite_code` field. The status is always 422 (the submitted code is the
     * unprocessable input on a registration FORM); the granular invite statuses
     * (410/409/403) the package's own redeem API uses are intentionally folded
     * here so the SPA renders a single field-level message.
     */
    private function inviteCodeError(?RedemptionError $error): ValidationException
    {
        return ValidationException::withMessages([
            'invite_code' => [$this->inviteCodeMessage($error)],
        ]);
    }

    private function inviteCodeMessage(?RedemptionError $error): string
    {
        return match ($error) {
            RedemptionError::Expired => 'This invite code has expired.',
            RedemptionError::Exhausted => 'This invite code has already been fully used.',
            RedemptionError::Revoked => 'This invite code has been revoked.',
            RedemptionError::Ineligible => 'This invite code is not valid for this account.',
            RedemptionError::RateLimited => 'Too many attempts with this invite code. Please try again later.',
            default => 'Invalid invite code.',
        };
    }
}
