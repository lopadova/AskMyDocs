<?php

declare(strict_types=1);

namespace App\Support\TabularReview;

/**
 * v4.7/W1 — Tabular cell state machine.
 *
 * pending     — no extraction attempt yet
 * generating  — extractor in flight (set just before LLM/json_path call)
 * ready       — extraction stored, flag populated
 * failed      — terminal error path (LLM 4xx/5xx, JSON parse error,
 *               R14 no-evidence refusal). content carries the reason
 *               in `reasoning` and `flag = 'red'`.
 */
enum CellStatus: string
{
    case PENDING = 'pending';
    case GENERATING = 'generating';
    case READY = 'ready';
    case FAILED = 'failed';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (CellStatus $c) => $c->value, self::cases());
    }
}
