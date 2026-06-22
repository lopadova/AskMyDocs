<?php

declare(strict_types=1);

namespace App\Services\Invite\Support;

use App\Models\AbuseSignal;

/**
 * The detector's verdict (docs/10-anti-abuse.md GateDecision). `action` is one
 * of the AbuseSignal action constants (none|flag|throttle|block). The tripped
 * signal_type is intentionally NOT exposed to the caller — the gate surfaces
 * only a generic rate_limited, never a probing oracle.
 */
final class AbuseDecision
{
    /**
     * @param  array<int, array<string, mixed>>  $signals  diagnostic only (never returned to the client)
     */
    public function __construct(
        public readonly string $action,
        public readonly int $totalScore = 0,
        public readonly ?int $retryAfter = null,
        public readonly array $signals = [],
    ) {
    }

    public static function none(): self
    {
        return new self(AbuseSignal::ACTION_NONE);
    }

    /**
     * The gate refuses the action when throttled or blocked. flag/none proceed.
     */
    public function blocked(): bool
    {
        return $this->action === AbuseSignal::ACTION_THROTTLE
            || $this->action === AbuseSignal::ACTION_BLOCK;
    }
}
