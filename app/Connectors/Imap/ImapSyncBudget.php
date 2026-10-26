<?php

declare(strict_types=1);

namespace App\Connectors\Imap;

/** One monotonic deadline shared by all connections and retries in a sync job. */
final class ImapSyncBudget
{
    private ?float $deadline = null;

    public function start(float $seconds): void
    {
        $this->deadline = hrtime(true) / 1e9 + max(0, $seconds);
    }

    public function stop(): void
    {
        $this->deadline = null;
    }

    public function remainingSeconds(): ?float
    {
        return $this->deadline === null ? null : max(0, $this->deadline - hrtime(true) / 1e9);
    }

    public function throwIfExpired(): void
    {
        if ($this->remainingSeconds() === 0.0) {
            $this->exhaust();
        }
    }

    public function exhaust(): never
    {
        $this->deadline = hrtime(true) / 1e9;
        throw new ImapSyncBudgetExceeded('IMAP sync time budget exhausted; resume from the confirmed UID checkpoint.');
    }
}
