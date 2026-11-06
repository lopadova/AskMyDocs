<?php

declare(strict_types=1);

namespace App\Services\Kb\Code;

use Illuminate\Support\Facades\Log;

/**
 * Chooses the break-point driver for one file and applies the degradation
 * policy (ADR 0035 D7, R43):
 *
 *  - `heuristic`   never spawns a process;
 *  - `auto`        tree-sitter when the helper answers its probe and ships a
 *                  grammar for the language, otherwise — or when the run
 *                  fails (timeout, crash, malformed output) — the heuristic;
 *  - `tree_sitter` forced: any unavailability FAILS the ingest loudly (R14);
 *                  a language with no pinned grammar always uses the
 *                  heuristic, because that is design, not degradation.
 *
 * An unrecognised value of `kb.code.chunker_driver` resolves to `heuristic`
 * (the mode that spawns no process): unknown policy must not widen the
 * execution surface.
 */
final class BreakPointDriverResolver
{
    public const MODES = ['auto', 'tree_sitter', 'heuristic'];

    public function __construct(
        private readonly TreeSitterBreakPointDriver $treeSitter,
        private readonly HeuristicBreakPointDriver $heuristic,
    ) {}

    public function mode(): string
    {
        $mode = strtolower(trim((string) config('kb.code.chunker_driver', 'auto')));

        return in_array($mode, self::MODES, true) ? $mode : 'heuristic';
    }

    /**
     * @return array{driver: string, points: list<BreakPoint>}
     *
     * @throws CodeChunkerUnavailableException only in forced `tree_sitter` mode
     */
    public function breakPoints(string $source, string $language): array
    {
        $mode = $this->mode();

        if ($mode === 'heuristic' || ! CodeLanguageMap::isAstCapable($language)) {
            return $this->viaHeuristic($source, $language);
        }

        if ($mode === 'tree_sitter') {
            return ['driver' => $this->treeSitter->name(), 'points' => $this->treeSitter->breakPoints($source, $language)];
        }

        if (! $this->treeSitter->isAvailable($language)) {
            return $this->viaHeuristic($source, $language);
        }
        try {
            return ['driver' => $this->treeSitter->name(), 'points' => $this->treeSitter->breakPoints($source, $language)];
        } catch (CodeChunkerUnavailableException $e) {
            // Reason code only — never the source or the helper's output.
            Log::warning('Code chunker: tree-sitter run failed, using the heuristic driver', ['language' => $language, 'reason' => $e->reason]);

            return $this->viaHeuristic($source, $language);
        }
    }

    /**
     * @return array{driver: string, points: list<BreakPoint>}
     */
    private function viaHeuristic(string $source, string $language): array
    {
        return ['driver' => $this->heuristic->name(), 'points' => $this->heuristic->breakPoints($source, $language)];
    }
}
