<?php

declare(strict_types=1);

namespace App\Services\Demo;

use App\Connectors\Imap\MailboxBusyException;
use App\Connectors\Imap\MailboxLockKey;
use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

/**
 * Holds the same cross-tenant physical-mailbox lock used by connector sync for
 * the complete seed transaction: purge, checkpoint read, APPEND and checkpoint
 * commit. This prevents concurrent seeders from racing the checkpoint and
 * prevents seed delivery from opening a second connection beside a sync.
 */
final class EmailSeedMailboxLock
{
    /**
     * @param  (Closure(): (int|float))|null  $monotonicClock
     */
    public function __construct(
        private readonly ?Closure $monotonicClock = null,
    ) {}

    /**
     * @template T
     * @param  Closure(EmailSeedLockLease): T  $operation
     * @return T
     */
    public function run(MailboxTarget $target, Closure $operation): mixed
    {
        $clock = $this->clock();
        if (config('connectors.imap.serialize_connections', true) !== true) {
            return $operation(EmailSeedLockLease::unlimited($clock, $target->mailboxKey));
        }

        $key = MailboxLockKey::forConnection([
            'host' => $target->host,
            'port' => $target->port,
            'username' => $target->email,
        ]);
        if ($key === null) {
            throw new RuntimeException(
                "Impossibile calcolare il lock fisico per la mailbox {$target->mailboxKey}.",
            );
        }

        $store = Cache::store()->getStore();
        if (! $store instanceof LockProvider) {
            throw new RuntimeException(
                'Il seeding IMAP richiede un cache store con lock atomici quando '
                .'CONNECTOR_IMAP_SERIALIZE_CONNECTIONS=true.',
            );
        }

        $waitSeconds = max(
            0,
            (int) config('connectors.imap.mailbox_lock.wait_seconds', 15),
        );
        $ttlSeconds = max(
            1,
            (int) config('connectors.imap.mailbox_lock.seed_ttl_seconds', 14_400),
        );
        $safetyMarginSeconds = max(
            1,
            (int) config('connectors.imap.mailbox_lock.seed_safety_margin_seconds', 30),
        );
        if ($safetyMarginSeconds < 2 || $safetyMarginSeconds >= $ttlSeconds) {
            throw new RuntimeException(
                'CONNECTOR_IMAP_SEED_LOCK_SAFETY_MARGIN deve essere almeno 2 '
                .'secondi e inferiore a CONNECTOR_IMAP_SEED_LOCK_TTL.',
            );
        }

        $lock = $store->lock($key, $ttlSeconds);
        $operationError = null;
        // Conservative anchor: acquisition happens at or after this instant,
        // therefore this deadline can never outlive the real cache-lock TTL.
        $acquisitionStartedAt = $this->now($clock);

        try {
            try {
                $lock->block($waitSeconds);
            } catch (LockTimeoutException $exception) {
                throw new MailboxBusyException(
                    'Mailbox busy: seed e sync condividono lo stesso lock fisico.',
                    previous: $exception,
                );
            }

            if (
                ! is_callable([$lock, 'refresh'])
                || ! is_callable([$lock, 'isOwnedByCurrentProcess'])
            ) {
                throw new RuntimeException(
                    'Il cache lock configurato non supporta refresh owner-safe: '
                    .'seeding IMAP interrotto.',
                );
            }

            $lease = EmailSeedLockLease::bounded(
                clock: $clock,
                startedAt: $acquisitionStartedAt,
                ttlSeconds: $ttlSeconds,
                safetyMarginSeconds: $safetyMarginSeconds,
                mailboxKey: $target->mailboxKey,
                refreshLock: static fn (): bool => $lock->refresh($ttlSeconds) === true,
                ownsLock: static fn (): bool => $lock->isOwnedByCurrentProcess() === true,
            );

            return $this->runWithInterruptGuard(
                $target->mailboxKey,
                static fn (): mixed => $operation($lease),
            );
        } catch (Throwable $exception) {
            $operationError = $exception;

            throw $exception;
        } finally {
            try {
                $released = $lock->release();
                if (! $released && $operationError === null) {
                    throw new RuntimeException(
                        "Rilascio del lock IMAP fallito per {$target->mailboxKey}.",
                    );
                }
            } catch (Throwable $releaseError) {
                // Preserve the actual purge/APPEND failure. The lock TTL remains
                // the recovery backstop, but a release failure on an otherwise
                // successful operation is surfaced loudly.
                if ($operationError === null) {
                    throw $releaseError;
                }
            }
        }
    }

    /**
     * @return Closure(): (int|float)
     */
    private function clock(): Closure
    {
        return $this->monotonicClock
            ?? static fn (): float => hrtime(true) / 1_000_000_000;
    }

    private function now(Closure $clock): float
    {
        $now = $clock();
        if ((! is_int($now) && ! is_float($now)) || ! is_finite((float) $now)) {
            throw new RuntimeException('Clock monotono non valido per il lock IMAP.');
        }

        return (float) $now;
    }

    /**
     * Convert SIGINT/SIGTERM into a normal exception while the acquired lock is
     * owned. This lets the outer finally release it instead of leaving a
     * four-hour stale Redis lock after Ctrl-C or process termination.
     *
     * @template T
     * @param  Closure(): T  $operation
     * @return T
     */
    private function runWithInterruptGuard(string $mailboxKey, Closure $operation): mixed
    {
        foreach ([
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
        if (! defined('SIGINT') || ! defined('SIGTERM')) {
            throw new RuntimeException(
                'Il seeding IMAP serializzato richiede i segnali SIGINT e SIGTERM.',
            );
        }

        $previousAsync = pcntl_async_signals();
        $previousHandlers = [
            SIGINT => pcntl_signal_get_handler(SIGINT),
            SIGTERM => pcntl_signal_get_handler(SIGTERM),
        ];
        $installedSignals = [];
        $operationError = null;
        $result = null;

        try {
            pcntl_async_signals(true);

            foreach ([SIGINT, SIGTERM] as $signal) {
                $installed = pcntl_signal(
                    $signal,
                    static function (int $receivedSignal) use ($mailboxKey): void {
                        $signalName = $receivedSignal === SIGINT ? 'SIGINT' : 'SIGTERM';

                        throw new RuntimeException(
                            "Seeding IMAP interrotto da {$signalName} per {$mailboxKey}; "
                            .'lock in rilascio, riprendi con --resume.',
                        );
                    },
                );
                if (! $installed) {
                    throw new RuntimeException(
                        "Installazione handler del segnale {$signal} fallita.",
                    );
                }

                $installedSignals[] = $signal;
            }

            $result = $operation();
        } catch (Throwable $exception) {
            $operationError = $exception;
        } finally {
            $restoreError = null;

            foreach (array_reverse($installedSignals) as $signal) {
                if (! pcntl_signal($signal, $previousHandlers[$signal])) {
                    $restoreError ??= new RuntimeException(
                        "Ripristino handler del segnale {$signal} fallito.",
                    );
                }
            }

            pcntl_async_signals($previousAsync);

            if ($restoreError !== null) {
                throw new RuntimeException(
                    $restoreError->getMessage(),
                    previous: $operationError ?? $restoreError,
                );
            }
        }

        if ($operationError !== null) {
            throw $operationError;
        }

        return $result;
    }
}
