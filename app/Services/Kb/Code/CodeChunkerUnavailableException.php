<?php

declare(strict_types=1);

namespace App\Services\Kb\Code;

use RuntimeException;

/**
 * The tree-sitter helper could not produce break points (missing Node or
 * grammar, timeout, crash, malformed output). Under `KB_CODE_CHUNKER_DRIVER=auto`
 * the resolver absorbs it and falls back to the heuristic driver; under
 * `tree_sitter` it propagates and fails the ingest loudly (R14).
 *
 * The message carries a stable reason code, never source text or process
 * output (SEC-LOG-001).
 */
final class CodeChunkerUnavailableException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message = '')
    {
        parent::__construct($message !== '' ? $message : 'Code chunker unavailable: '.$reason);
    }
}
