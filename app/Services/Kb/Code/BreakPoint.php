<?php

declare(strict_types=1);

namespace App\Services\Kb\Code;

/**
 * A place where a code chunk may START (v8.45 / W5, ADR 0035 D7).
 *
 * `line`/`endLine` are 1-based source lines (byte offsets are deliberately not
 * part of the contract — see tools/code-chunker/index.mjs). `score` ranks the
 * boundary: class/interface 100, top-level function 90, method 80, import
 * block 60, other top-level statement 40; the chunker adds blank lines (20).
 */
final readonly class BreakPoint
{
    public function __construct(
        public int $line,
        public int $endLine,
        public int $score,
        public string $type,
        public ?string $symbol,
        public int $depth,
    ) {}

    /** A declaration the chunker should keep whole and may name a chunk after. */
    public function isDeclaration(): bool
    {
        return in_array($this->type, ['class', 'function', 'method', 'type'], true);
    }
}
