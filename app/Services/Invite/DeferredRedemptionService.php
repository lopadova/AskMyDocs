<?php

declare(strict_types=1);

namespace App\Services\Invite;

use App\Models\User;
use App\Services\Invite\Support\RedemptionResult;
use App\Support\TenantContext;
use Illuminate\Contracts\Session\Session;

/**
 * Deferred (guest) redemption (docs/07-redemption-flow.md). A visitor can
 * present a code while unauthenticated; we park the NORMALIZED candidate in
 * the session and complete the claim immediately after auth (login OR
 * register).
 *
 * The idempotency invariant is read-and-forget: complete() PULLs the key
 * (read + remove) BEFORE calling redeem(), so a double-fired auth event finds
 * nothing on the second pass and cannot double-claim. Even if the same code is
 * replayed via URL, the redemption idempotency (UNIQUE(code_id, redeemer_id))
 * absorbs it.
 */
final class DeferredRedemptionService
{
    public function __construct(
        private readonly CodeValidator $validator,
        private readonly RedemptionService $redemption,
        private readonly TenantContext $tenant,
    ) {
    }

    /**
     * Park a guest's code candidate. Only stashes if it currently validates —
     * a junk code never occupies the slot. Returns whether it was stashed.
     */
    public function stash(Session $session, string $rawCode): bool
    {
        $validation = $this->validator->validate($rawCode, $this->tenant->current());
        if (! $validation->ok) {
            return false;
        }

        // Stash the canonical code string (not a claim).
        $session->put($this->key(), $validation->code->code);

        return true;
    }

    /**
     * Complete a parked redemption after authentication. READ-AND-FORGET:
     * pull() removes the key before redeem() runs, so a re-fired event is a
     * no-op. Returns null when nothing was parked.
     */
    public function complete(Session $session, User $user, array $context = []): ?RedemptionResult
    {
        $token = $session->pull($this->key());

        if (! is_string($token) || $token === '') {
            return null;
        }

        return $this->redemption->redeem($token, $user, $context);
    }

    private function key(): string
    {
        return (string) config('invite.pending_session_key', 'invite.pending_redemption');
    }
}
