<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Demo;

use App\Services\Demo\EmailSeedMailboxLock;
use App\Services\Demo\MailboxTarget;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class EmailSeedMailboxLockTest extends TestCase
{
    #[DataProvider('interruptSignals')]
    public function test_interrupt_releases_lock_and_restores_signal_handlers(
        int $signal,
        string $signalName,
    ): void {
        if (
            ! function_exists('pcntl_async_signals')
            || ! function_exists('pcntl_signal')
            || ! function_exists('pcntl_signal_get_handler')
        ) {
            $this->markTestSkipped('PCNTL is required for the interrupt regression test.');
        }

        config()->set('cache.default', 'array');
        config()->set('connectors.imap.serialize_connections', true);
        config()->set('connectors.imap.mailbox_lock.wait_seconds', 0);
        config()->set('connectors.imap.mailbox_lock.seed_ttl_seconds', 60);
        config()->set('connectors.imap.mailbox_lock.seed_safety_margin_seconds', 2);
        Cache::purge('array');

        $previousAsync = pcntl_async_signals();
        $previousInterrupt = pcntl_signal_get_handler(SIGINT);
        $previousTerminate = pcntl_signal_get_handler(SIGTERM);
        $lock = new EmailSeedMailboxLock;
        $target = $this->target();

        try {
            $lock->run($target, function () use ($signal): never {
                $handler = pcntl_signal_get_handler($signal);
                $this->assertIsCallable($handler);

                $handler($signal);
            });

            $this->fail("{$signalName} must interrupt the seed operation.");
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString(
                "Seeding IMAP interrotto da {$signalName}",
                $exception->getMessage(),
            );
        }

        $this->assertSame($previousAsync, pcntl_async_signals());
        $this->assertSame($previousInterrupt, pcntl_signal_get_handler(SIGINT));
        $this->assertSame($previousTerminate, pcntl_signal_get_handler(SIGTERM));

        $this->assertSame(
            'reacquired',
            $lock->run($target, static fn (): string => 'reacquired'),
            'The same physical mailbox lock must be immediately reusable after interruption.',
        );
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function interruptSignals(): iterable
    {
        yield 'SIGINT' => [SIGINT, 'SIGINT'];
        yield 'SIGTERM' => [SIGTERM, 'SIGTERM'];
    }

    private function target(): MailboxTarget
    {
        return new MailboxTarget(
            mailboxKey: 'interrupt-test',
            projectKey: 'interrupt-project',
            companyName: 'Interrupt Test',
            email: 'interrupt@example.test',
            host: 'imap.example.test',
            port: 993,
            encryption: 'ssl',
            validateCert: true,
            secret: 'secret',
            folder: 'interrupt-test',
        );
    }
}
