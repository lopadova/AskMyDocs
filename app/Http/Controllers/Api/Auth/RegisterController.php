<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use App\Support\DesktopToken;
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
 * JSON-native, invite-only sign-up. Two HTTP surfaces over ONE shared core
 * (R44), differing only in how they hand the caller a credential:
 *
 *   - register()       — POST /api/auth/register (the React SPA). Sits in the
 *                        `web` middleware group, so it opens a Sanctum stateful
 *                        SESSION (the SPA primes XSRF-TOKEN via
 *                        GET /sanctum/csrf-cookie first).
 *   - registerToken()  — POST /api/auth/register-token (the Tauri desktop app).
 *                        Stateless, NO session/CSRF — mints a least-privilege
 *                        Bearer token instead, exactly like POST /api/auth/token
 *                        is the Bearer counterpart of POST /api/auth/login.
 *
 * Shared core — {@see provisionInvitedAccount()}:
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
 *      already provisioned — GRANT-never-revoke) and fire the standard
 *      `Registered` event.
 *
 * Every invite-code failure is surfaced as a 422 field error on `invite_code`
 * (R14 — never 200-with-empty) so the client shows it under the code input; the
 * machine-readable RedemptionError stays English (R24), only the body is
 * human-facing.
 *
 * R44 — registration ships PHP-callable core (the invite services) + the two
 * HTTP surfaces above. There is deliberately no MCP/Artisan "register" tool:
 * it mints an interactive end-user credential (session or Bearer), which has no
 * meaningful agent/operator analogue (operator account creation lives on the
 * admin user API).
 */
class RegisterController extends Controller
{
    public function __construct(
        private readonly CodeValidator $validator,
        private readonly RedemptionService $redemption,
        private readonly TenantResolver $tenant,
    ) {}

    /**
     * SPA sign-up: provision the invited account, then open a stateful session.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = $this->provisionInvitedAccount($request);

        // Auth::login fires the Login event (the invitations
        // CompletePendingRedemption listener is a safe no-op — we redeemed
        // directly, nothing was stashed).
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

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
     * Desktop (Tauri) sign-up: provision the invited account, then mint a
     * least-privilege Bearer token. Stateless — no session is opened (the route
     * sits OUTSIDE the `web` group), mirroring POST /api/auth/token.
     */
    public function registerToken(RegisterRequest $request): JsonResponse
    {
        $user = $this->provisionInvitedAccount($request);

        $token = DesktopToken::mint($user, $request->deviceName())->plainTextToken;

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

    /**
     * Shared invite-only provisioning core for both sign-up surfaces (R44).
     * Returns the freshly-created, role-floored user, or throws a 422
     * ValidationException on any invite-code failure. See the class docblock for
     * why redeem runs outside a transaction and why the account is force-deleted
     * on a post-validation redeem failure.
     */
    private function provisionInvitedAccount(RegisterRequest $request): User
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
        try {
            $user = User::create([
                'name' => (string) $data['name'],
                'email' => (string) $data['email'],
                'password' => Hash::make((string) $data['password']),
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // Concurrency guard: another request may create the same email between validation and insert.
            if (User::where('email', (string) $data['email'])->exists()) {
                throw ValidationException::withMessages([
                    'email' => [__('validation.unique', ['attribute' => 'email'])],
                ]);
            }
            throw $e;
        }

        // 3. Authoritatively redeem the invite code.
        $result = $this->redemption->redeem($code, $user, [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
        if (! $result->ok && ! $result->already) {
            // Exhausted-between-checks race: drop the brand-new account so the
            // invite-only invariant never leaves a user who consumed no code.
            if (! $user->forceDelete()) {
                throw new \RuntimeException('Failed to clean up newly-created account after failed invite redemption.');
            }
            throw $this->inviteCodeError($result->error);
        }

        // 4. Floor the account at 'viewer' (layered on any grant role redeem
        // already provisioned — GRANT-never-revoke). Done post-redeem so the
        // failure path above never has a role pivot to clean up. The Registered
        // event fires the registration listeners; the invitations
        // CompletePendingRedemption listener reads the session store in-memory
        // and finds nothing stashed, so it is safe on the stateless path too.
        $user->assignRole('viewer');
        event(new Registered($user));

        return $user;
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
            RedemptionError::Expired => (string) __('register.invite_code.expired'),
            RedemptionError::Exhausted => (string) __('register.invite_code.exhausted'),
            RedemptionError::Revoked => (string) __('register.invite_code.revoked'),
            RedemptionError::Ineligible => (string) __('register.invite_code.ineligible'),
            RedemptionError::RateLimited => (string) __('register.invite_code.rate_limited'),
            default => (string) __('register.invite_code.invalid'),
        };
    }
}
