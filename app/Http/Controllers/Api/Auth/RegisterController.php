<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
 *   2. Create the user + assign the baseline `viewer` role. The invite's own
 *      grant (Spatie role + project membership) is layered on by the tagged
 *      redemption provisioners, which are GRANT-never-revoke — so this floor is
 *      only ever raised.
 *   3. Authoritatively redeem the code via {@see RedemptionService} (re-checks
 *      atomically + runs the provisioners). On a race where the code was
 *      exhausted between the pre-check and the redeem, roll the brand-new
 *      account back so the invite-only invariant holds.
 *   4. Open the session (login + regenerate) and fire the standard `Registered`
 *      event.
 *
 * Every invite-code failure is surfaced as a 422 field error on `invite_code`
 * (R14 — never 200-with-empty) so the SPA shows it under the code input; the
 * machine-readable RedemptionError stays English (R24), only the body is
 * human-facing.
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

        // 2. Create the account + baseline role, then 3. redeem. Wrapped so a
        // redemption failure (exhausted-between-checks race) rolls the user
        // back: the invite-only invariant must never leave an account that
        // never actually consumed a valid code.
        $user = DB::transaction(function () use ($data, $code, $request): User {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
            ]);
            $user->assignRole('viewer');

            $result = $this->redemption->redeem($code, $user, [
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            // `already` is the idempotent-success branch, not a failure.
            if (! $result->ok && ! $result->already) {
                throw $this->inviteCodeError($result->error);
            }

            return $user;
        });

        // 4. Open the SPA session. Auth::login fires the Login event and the
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
