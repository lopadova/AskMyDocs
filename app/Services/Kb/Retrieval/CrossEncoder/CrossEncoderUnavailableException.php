<?php

declare(strict_types=1);

namespace App\Services\Kb\Retrieval\CrossEncoder;

use RuntimeException;

/**
 * v8.43/W3 — the cross-encoder could not produce a trustworthy answer. Carries
 * a short machine `reason` code (safe to surface in `meta.cross_encoder`) and a
 * human message (log only — it can name hosts/paths, never shown to a client).
 */
final class CrossEncoderUnavailableException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message = '', ?\Throwable $previous = null)
    {
        parent::__construct($message !== '' ? $message : $reason, 0, $previous);
    }
}
