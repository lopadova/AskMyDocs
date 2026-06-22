<?php

declare(strict_types=1);

namespace App\Services\Invite\Support;

use App\Models\InviteCode;

/**
 * Advisory validation outcome from CodeValidator. Carries the resolved
 * InviteCode on success so the redemption service does not have to look it up
 * a second time.
 */
final class ValidationResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly ?InviteCode $code = null,
        public readonly ?RedemptionError $error = null,
    ) {
    }

    public static function valid(InviteCode $code): self
    {
        return new self(ok: true, code: $code);
    }

    public static function invalid(RedemptionError $error, ?InviteCode $code = null): self
    {
        return new self(ok: false, code: $code, error: $error);
    }
}
