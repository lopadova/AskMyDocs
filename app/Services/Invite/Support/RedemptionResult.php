<?php

declare(strict_types=1);

namespace App\Services\Invite\Support;

use App\Models\Redemption;
use App\Models\Referral;

/**
 * The redemption result value object (docs/07-redemption-flow.md):
 *
 *   { ok: true,  redemption, already: bool, referral?: Referral }
 *   { ok: false, error: RedemptionError }
 *
 * Immutable; built only through the named constructors so an `ok` result
 * always carries a Redemption and a failure always carries an error.
 */
final class RedemptionResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly ?Redemption $redemption = null,
        public readonly bool $already = false,
        public readonly ?RedemptionError $error = null,
        public readonly ?Referral $referral = null,
    ) {
    }

    public static function success(Redemption $redemption, bool $already = false, ?Referral $referral = null): self
    {
        return new self(ok: true, redemption: $redemption, already: $already, referral: $referral);
    }

    public static function failure(RedemptionError $error): self
    {
        return new self(ok: false, error: $error);
    }

    /**
     * Attach a referral to an already-built success result (the referral is
     * attributed AFTER the claim commits — Phase 3).
     */
    public function withReferral(?Referral $referral): self
    {
        if (! $this->ok || $referral === null) {
            return $this;
        }

        return new self(ok: true, redemption: $this->redemption, already: $this->already, error: null, referral: $referral);
    }
}
