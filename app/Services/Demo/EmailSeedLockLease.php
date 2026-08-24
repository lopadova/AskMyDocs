<?php

declare(strict_types=1);

namespace App\Services\Demo;

use Closure;
use RuntimeException;
use Throwable;

/**
 * Renewable, owner-checked lease for one acquired physical-mailbox seed lock.
 *
 * Every IMAP operation runs under a SIGALRM wall-clock guard. The first alarm
 * fires strictly before the safety boundary; a cleanup alarm then remains
 * inside the cache-lock TTL so a stuck disconnect cannot silently overlap a
 * second seed/sync owner.
 */
final class EmailSeedLockLease
{
    private bool $guardActive = false;

    private bool $timedOut = false;

    private function __construct(
        private readonly Closure $clock,
        private readonly ?Closure $refreshLock,
        private readonly ?Closure $ownsLock,
        private ?float $expiresAt,
        private readonly int $ttlSeconds,
        private readonly int $safetyMarginSeconds,
        private readonly string $mailboxKey,
    ) {}

    /**
     * @param  Closure(): bool  $refreshLock
     * @param  Closure(): bool  $ownsLock
     */
    public static function bounded(
        Closure $clock,
        float $startedAt,
        int $ttlSeconds,
        int $safetyMarginSeconds,
        string $mailboxKey,
        Closure $refreshLock,
        Closure $ownsLock,
    ): self {
        if ($ttlSeconds < 1) {
            throw new RuntimeException('Il TTL della lease IMAP deve essere almeno 1 secondo.');
        }
        if ($safetyMarginSeconds < 2 || $safetyMarginSeconds >= $ttlSeconds) {
            throw new RuntimeException(
                'Il margine della lease IMAP deve essere almeno 2 secondi e inferiore al TTL.',
            );
        }
        if (! is_finite($startedAt)) {
            throw new RuntimeException('Clock monotono non valido per la lease IMAP.');
        }

        return new self(
            clock: $clock,
            refreshLock: $refreshLock,
            ownsLock: $ownsLock,
            expiresAt: $startedAt + $ttlSeconds,
            ttlSeconds: $ttlSeconds,
            safetyMarginSeconds: $safetyMarginSeconds,
            mailboxKey: $mailboxKey,
        );
    }

    public static function unlimited(Closure $clock, string $mailboxKey): self
    {
        return new self(
            clock: $clock,
            refreshLock: null,
            ownsLock: null,
            expiresAt: null,
            ttlSeconds: 0,
            safetyMarginSeconds: 0,
            mailboxKey: $mailboxKey,
        );
    }

    /**
     * Atomically renews the cache lock only while this process still owns it.
     */
    public function refresh(): void
    {
        if ($this->expiresAt === null) {
            return;
        }
        if ($this->timedOut) {
            throw $this->expired('lease già interrotta dal limite temporale');
        }

        // A lock that has reached its local TTL is no longer renewable: even if
        // a lagging cache store still returned true, another process may have
        // acquired it according to the declared contract.
        $startedAt = $this->now();
        if ($startedAt >= $this->expiresAt) {
            throw $this->expired('TTL superato prima del rinnovo');
        }

        try {
            $refreshed = ($this->refreshLock)();
        } catch (Throwable $exception) {
            throw new EmailSeedLockLeaseExpiredException(
                "Rinnovo owner-safe del lock IMAP fallito per {$this->mailboxKey}.",
                previous: $exception,
            );
        }

        if ($refreshed !== true) {
            throw $this->expired('ownership persa durante il rinnovo');
        }

        // Conservative anchor: the real refresh happens at or after startedAt,
        // therefore this local deadline can never outlive the refreshed lock.
        $this->expiresAt = $startedAt + $this->ttlSeconds;

        if ($this->guardActive) {
            $this->armHardDeadline();
        }
    }

    public function mustStop(): bool
    {
        return $this->expiresAt !== null
            && $this->now() >= $this->expiresAt - $this->safetyMarginSeconds;
    }

    public function assertCanAppend(): void
    {
        if ($this->expiresAt === null) {
            return;
        }
        $this->assertOwned();
        if (! $this->mustStop()) {
            return;
        }

        throw new EmailSeedLockLeaseExpiredException(
            "Lease del lock IMAP in scadenza per {$this->mailboxKey}: "
            .'APPEND interrotto prima del TTL; riprendi con --resume.',
        );
    }

    /**
     * Checkpoint writes are allowed inside the safety margin, but never after
     * the actual lease deadline, where another process may own the mailbox.
     */
    public function assertCanPersistCheckpoint(): void
    {
        if ($this->expiresAt === null) {
            return;
        }
        $this->assertOwned();
        if ($this->now() < $this->expiresAt) {
            return;
        }

        throw new EmailSeedLockLeaseExpiredException(
            "Lease del lock IMAP scaduta per {$this->mailboxKey}: "
            .'checkpoint non scritto per evitare una race; riprendi con --resume.',
        );
    }

    /**
     * Maximum Webklex socket timeout that still ends before the hard guard.
     */
    public function ioTimeoutSeconds(int $configuredMaximum = 30): int
    {
        if ($this->expiresAt === null) {
            return max(1, $configuredMaximum);
        }
        $this->assertCanAppend();

        $seconds = $this->secondsBeforeSafetyAlarm();
        if ($seconds < 1) {
            throw $this->expired('budget I/O esaurito prima della safety boundary');
        }

        return min(max(1, $configuredMaximum), $seconds);
    }

    /**
     * Runs blocking IMAP/local-checkpoint I/O behind a process-wide hard wall.
     *
     * The CLI command deliberately fails closed when PCNTL is unavailable:
     * socket inactivity timeouts alone cannot bound a continuously-progressing
     * APPEND that outlives the distributed lock.
     *
     * @template T
     * @param  Closure(): T  $operation
     * @return T
     */
    public function runGuarded(Closure $operation): mixed
    {
        if ($this->expiresAt === null) {
            return $operation();
        }
        if ($this->guardActive) {
            throw new RuntimeException('Le guardie temporali della lease IMAP non sono annidabili.');
        }

        $this->requirePcntl();

        $previousAsync = pcntl_async_signals();
        $previousHandler = pcntl_signal_get_handler(SIGALRM);
        $previousAlarm = pcntl_alarm(0);
        $handlerInstalled = false;
        $operationError = null;
        $result = null;

        try {
            if ($previousAlarm > 0) {
                throw new RuntimeException(
                    'SIGALRM è già in uso: impossibile installare in sicurezza '
                    .'la guardia del seeding IMAP.',
                );
            }

            pcntl_async_signals(true);
            $handlerInstalled = pcntl_signal(
                SIGALRM,
                function (): void {
                    $this->timedOut = true;

                    // A second alarm bounds best-effort socket cleanup strictly
                    // before the real cache-lock expiry.
                    pcntl_alarm(max(1, $this->safetyMarginSeconds - 1));

                    throw $this->expired(
                        'operazione IMAP oltre il limite temporale owner-safe',
                    );
                },
            );
            if (! $handlerInstalled) {
                throw new RuntimeException('Installazione della guardia SIGALRM IMAP fallita.');
            }

            $this->guardActive = true;
            $this->armHardDeadline();
            $this->assertCanAppend();
            $result = $operation();
            $this->assertCanPersistCheckpoint();
        } catch (Throwable $exception) {
            $operationError = $exception;
        } finally {
            $this->guardActive = false;
            pcntl_alarm(0);

            $cleanupError = null;
            if ($handlerInstalled && ! pcntl_signal(SIGALRM, $previousHandler)) {
                $cleanupError = new RuntimeException(
                    'Ripristino del precedente handler SIGALRM fallito.',
                );
            }
            pcntl_async_signals($previousAsync);
            if ($previousAlarm > 0) {
                pcntl_alarm($previousAlarm);
            }

            if ($cleanupError !== null) {
                throw new RuntimeException(
                    $cleanupError->getMessage(),
                    previous: $operationError ?? $cleanupError,
                );
            }
        }

        if ($operationError !== null) {
            throw $operationError;
        }

        return $result;
    }

    private function assertOwned(): void
    {
        if ($this->timedOut) {
            throw $this->expired('lease già interrotta dal limite temporale');
        }

        try {
            $owned = ($this->ownsLock)();
        } catch (Throwable $exception) {
            throw new EmailSeedLockLeaseExpiredException(
                "Verifica ownership del lock IMAP fallita per {$this->mailboxKey}.",
                previous: $exception,
            );
        }

        if ($owned !== true) {
            throw $this->expired('ownership non più detenuta dal processo');
        }
    }

    private function requirePcntl(): void
    {
        foreach ([
            'pcntl_alarm',
            'pcntl_async_signals',
            'pcntl_signal',
            'pcntl_signal_get_handler',
        ] as $function) {
            if (! function_exists($function)) {
                throw new RuntimeException(
                    "Il seeding IMAP serializzato richiede PCNTL ({$function} assente).",
                );
            }
        }
        if (! defined('SIGALRM')) {
            throw new RuntimeException(
                'Il seeding IMAP serializzato richiede il segnale SIGALRM.',
            );
        }
    }

    private function armHardDeadline(): void
    {
        $seconds = $this->secondsBeforeSafetyAlarm();
        if ($seconds < 1) {
            throw $this->expired('safety boundary troppo vicina per avviare I/O');
        }

        pcntl_alarm($seconds);
    }

    /**
     * Integer alarm chosen strictly before the monotonic safety boundary.
     */
    private function secondsBeforeSafetyAlarm(): int
    {
        if ($this->expiresAt === null) {
            return PHP_INT_MAX;
        }

        $remaining = ($this->expiresAt - $this->safetyMarginSeconds) - $this->now();

        return max(0, (int) ceil($remaining) - 1);
    }

    private function expired(string $reason): EmailSeedLockLeaseExpiredException
    {
        return new EmailSeedLockLeaseExpiredException(
            "Lease del lock IMAP non più sicura per {$this->mailboxKey}: {$reason}; "
            .'checkpoint fermato, riprendi con --resume.',
        );
    }

    private function now(): float
    {
        $now = ($this->clock)();
        if ((! is_int($now) && ! is_float($now)) || ! is_finite((float) $now)) {
            throw new RuntimeException('Clock monotono non valido per la lease IMAP.');
        }

        return (float) $now;
    }
}
