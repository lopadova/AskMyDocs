<?php

declare(strict_types=1);

namespace App\Support\TabularReview;

/**
 * v4.7/W1 — Tabular cell evidence flag.
 *
 * Classifies the strength of the extraction:
 *
 *   green   — extraction confident, high vector similarity, single chunk
 *   grey    — value present but ambiguous OR sourced from metadata only
 *   yellow  — multiple chunks, conflicting answers
 *   red     — extraction failed or no evidence found (R14)
 */
enum CellFlag: string
{
    case GREEN = 'green';
    case GREY = 'grey';
    case YELLOW = 'yellow';
    case RED = 'red';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (CellFlag $c) => $c->value, self::cases());
    }
}
