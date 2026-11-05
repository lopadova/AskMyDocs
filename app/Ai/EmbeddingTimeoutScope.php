<?php

declare(strict_types=1);

namespace App\Ai;

/** Temporarily bounds an embedding request without changing other workloads. */
final class EmbeddingTimeoutScope
{
    private static ?int $seconds = null;

    public static function within(int $seconds, callable $callback): mixed
    {
        $previous = self::$seconds;
        self::$seconds = max(1, $seconds);
        try {
            return $callback();
        } finally {
            self::$seconds = $previous;
        }
    }

    public static function seconds(): ?int
    {
        return self::$seconds;
    }
}
