<?php

declare(strict_types=1);

namespace App\Services\Invite\Support;

/**
 * The canonical redemption error codes (docs/07-redemption-flow.md, lines
 * 78–88). All lowercase snake_case — the machine-readable identifier NEVER
 * localizes (R24); only a human-visible body would. Each maps to a stable
 * HTTP status at the controller boundary.
 *
 * `already_redeemed` is NOT an error in the result: it is the idempotent
 * success branch (ok=true, already=true). It is intentionally absent here.
 */
enum RedemptionError: string
{
    case Invalid = 'invalid';
    case Expired = 'expired';
    case Exhausted = 'exhausted';
    case Revoked = 'revoked';
    case Ineligible = 'ineligible';
    case RateLimited = 'rate_limited';

    /**
     * Stable HTTP status for this error at the API boundary (R14 — surface
     * failures with the correct semantic, never 200-with-empty).
     */
    public function httpStatus(): int
    {
        return match ($this) {
            self::Invalid => 422,
            self::Expired => 410,
            self::Exhausted => 409,
            self::Revoked => 410,
            self::Ineligible => 403,
            self::RateLimited => 429,
        };
    }
}
