<?php

declare(strict_types=1);

namespace App\Connectors\Imap\Backfill;

use Throwable;

/** Temporary, production-safe diagnostics for the IMAP full-history investigation. */
final class ImapBackfillDiagnostics
{
    /** @return array<string,mixed> */
    public static function runtime(): array
    {
        return [
            'hostname' => gethostname() ?: null,
            'pid' => getmypid() ?: null,
            'php_version' => PHP_VERSION,
            'memory_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'peak_memory_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            'cache_driver' => (string) config('cache.default'),
            'queue_connection' => (string) config('queue.default'),
        ];
    }

    /** @return list<array{type:string,message:string,code:int|string}> */
    public static function exceptionChain(Throwable $exception): array
    {
        $chain = [];
        $current = $exception;

        while ($current !== null && count($chain) < 8) {
            $chain[] = [
                'type' => $current::class,
                'message' => mb_substr($current->getMessage(), 0, 2000),
                'code' => $current->getCode(),
            ];
            $current = $current->getPrevious();
        }

        return $chain;
    }

    public static function mailboxHash(string $mailbox): string
    {
        return substr(hash('sha256', $mailbox), 0, 16);
    }

    public static function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
