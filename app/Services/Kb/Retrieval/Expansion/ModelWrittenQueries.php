<?php

declare(strict_types=1);

namespace App\Services\Kb\Retrieval\Expansion;

/**
 * Integration with the recursive investigation (ADR 0035 addendum I1) — marks
 * a region of code whose retrieval queries were WRITTEN BY A MODEL (the
 * investigation's understanding/assessment rounds, the agent planner, wave
 * searches). Typed query expansion is skipped inside it: rewriting what a model
 * already rewrote costs a second LLM call and drifts further from the user.
 *
 * A scope rather than a filter on purpose: the investigation and agent code
 * keep calling `ChatRetrievalService::retrieve()` with exactly the filters they
 * always passed (their contracts and tests are unchanged); the explicit
 * `RetrievalFilters::withoutExpansion()` remains for callers that build filters.
 * Re-entrant and always restored.
 */
final class ModelWrittenQueries
{
    private static int $depth = 0;

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function run(callable $callback): mixed
    {
        self::$depth++;
        try {
            return $callback();
        } finally {
            self::$depth--;
        }
    }

    public static function active(): bool
    {
        return self::$depth > 0;
    }
}
