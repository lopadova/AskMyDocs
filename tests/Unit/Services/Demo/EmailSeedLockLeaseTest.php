<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Demo;

use App\Services\Demo\EmailSeedLockLease;
use App\Services\Demo\EmailSeedLockLeaseExpiredException;
use RuntimeException;
use Tests\TestCase;

final class EmailSeedLockLeaseTest extends TestCase
{
    public function test_owner_safe_refresh_failure_stops_the_lease(): void
    {
        $refreshCalls = 0;
        $lease = EmailSeedLockLease::bounded(
            clock: static fn (): float => 1_000.0,
            startedAt: 1_000.0,
            ttlSeconds: 10,
            safetyMarginSeconds: 2,
            mailboxKey: 'mailbox-a',
            refreshLock: static function () use (&$refreshCalls): bool {
                $refreshCalls++;

                return false;
            },
            ownsLock: static fn (): bool => true,
        );

        try {
            $lease->refresh();
            $this->fail('A failed owner-safe refresh must stop the seed lease.');
        } catch (EmailSeedLockLeaseExpiredException $exception) {
            $this->assertStringContainsString('ownership persa', $exception->getMessage());
        }

        $this->assertSame(1, $refreshCalls);
    }

    public function test_hard_deadline_interrupts_blocking_io_and_restores_signal_state(): void
    {
        if (! $this->pcntlAvailable()) {
            $this->markTestSkipped('PCNTL/SIGALRM not available in this PHP runtime.');
        }

        $originalAsync = pcntl_async_signals();
        $originalHandler = pcntl_signal_get_handler(SIGALRM);
        $originalAlarm = pcntl_alarm(0);

        try {
            $this->assertTrue(pcntl_signal(SIGALRM, SIG_IGN));
            pcntl_async_signals(false);

            $clock = static fn (): float => hrtime(true) / 1_000_000_000;
            $lease = EmailSeedLockLease::bounded(
                clock: $clock,
                startedAt: $clock(),
                ttlSeconds: 4,
                safetyMarginSeconds: 2,
                mailboxKey: 'mailbox-a',
                refreshLock: static fn (): bool => true,
                ownsLock: static fn (): bool => true,
            );

            $startedAt = hrtime(true);
            try {
                $lease->runGuarded(static function (): void {
                    usleep(3_000_000);
                });
                $this->fail('The hard lease deadline must interrupt blocking I/O.');
            } catch (EmailSeedLockLeaseExpiredException $exception) {
                $this->assertStringContainsString(
                    'oltre il limite temporale owner-safe',
                    $exception->getMessage(),
                );
            }

            $elapsedSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;
            $this->assertLessThan(3.0, $elapsedSeconds);
            $this->assertSame(SIG_IGN, pcntl_signal_get_handler(SIGALRM));
            $this->assertFalse(pcntl_async_signals());
            $this->assertSame(0, pcntl_alarm(0), 'lease cleanup must leave no alarm behind');
        } finally {
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, $originalHandler);
            pcntl_async_signals($originalAsync);
            if ($originalAlarm > 0) {
                pcntl_alarm($originalAlarm);
            }
        }
    }

    public function test_preexisting_alarm_is_rejected_and_restored_without_global_leak(): void
    {
        if (! $this->pcntlAvailable()) {
            $this->markTestSkipped('PCNTL/SIGALRM not available in this PHP runtime.');
        }

        $originalAsync = pcntl_async_signals();
        $originalHandler = pcntl_signal_get_handler(SIGALRM);
        $originalAlarm = pcntl_alarm(0);

        try {
            $this->assertTrue(pcntl_signal(SIGALRM, SIG_IGN));
            pcntl_async_signals(false);
            pcntl_alarm(10);

            $lease = EmailSeedLockLease::bounded(
                clock: static fn (): float => 1_000.0,
                startedAt: 1_000.0,
                ttlSeconds: 10,
                safetyMarginSeconds: 2,
                mailboxKey: 'mailbox-a',
                refreshLock: static fn (): bool => true,
                ownsLock: static fn (): bool => true,
            );

            try {
                $lease->runGuarded(static fn (): bool => true);
                $this->fail('A pre-existing process alarm must be rejected.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('SIGALRM è già in uso', $exception->getMessage());
            }

            $this->assertSame(SIG_IGN, pcntl_signal_get_handler(SIGALRM));
            $this->assertFalse(pcntl_async_signals());
            $this->assertGreaterThan(0, pcntl_alarm(0), 'previous alarm must be restored');
        } finally {
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, $originalHandler);
            pcntl_async_signals($originalAsync);
            if ($originalAlarm > 0) {
                pcntl_alarm($originalAlarm);
            }
        }
    }

    private function pcntlAvailable(): bool
    {
        return defined('SIGALRM')
            && function_exists('pcntl_alarm')
            && function_exists('pcntl_async_signals')
            && function_exists('pcntl_signal')
            && function_exists('pcntl_signal_get_handler');
    }
}
