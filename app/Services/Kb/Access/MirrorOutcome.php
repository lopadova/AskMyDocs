<?php

declare(strict_types=1);

namespace App\Services\Kb\Access;

/**
 * What one reconciliation pass did, in terms a caller can act on.
 *
 * `skipped` is not a failure and is the most important state here: it means
 * the source did not tell us enough to act, so the previous mirror was left
 * exactly as it was. Reporting that as "nothing to do" would hide the one
 * case where a stale restriction persists.
 */
final class MirrorOutcome
{
    private function __construct(
        public readonly bool $skipped,
        public readonly string $reason,
        public readonly int $granted = 0,
        public readonly int $denied = 0,
        public readonly int $revoked = 0,
        public readonly int $unmapped = 0,
    ) {}

    public static function skipped(string $reason): self
    {
        return new self(true, $reason);
    }

    public static function applied(int $granted, int $denied, int $revoked, int $unmapped): self
    {
        return new self(false, 'applied', $granted, $denied, $revoked, $unmapped);
    }

    /**
     * Whether this document is now restricted to the mirrored subjects.
     *
     * A complete list with nothing in it still restricts: "nobody may read
     * this" is a permission list, not an absence of one.
     */
    public function restricts(): bool
    {
        return ! $this->skipped;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'skipped' => $this->skipped,
            'reason' => $this->reason,
            'granted' => $this->granted,
            'denied' => $this->denied,
            'revoked' => $this->revoked,
            'unmapped' => $this->unmapped,
        ];
    }
}
