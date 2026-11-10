<?php

declare(strict_types=1);

namespace App\Services\Kb\Code;

/**
 * Finds the structural boundaries of a source file. Implementations return
 * ONLY positions and labels — never code — so they can run out of process.
 */
interface BreakPointDriver
{
    /** Stable token recorded on every chunk (`metadata.chunker`). */
    public function name(): string;

    /**
     * @return list<BreakPoint> sorted by line ascending
     *
     * @throws CodeChunkerUnavailableException when the driver cannot answer
     */
    public function breakPoints(string $source, string $language): array;
}
