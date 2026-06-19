<?php

declare(strict_types=1);

namespace App\FinOps;

use Illuminate\Support\Str;
use Padosoft\LaravelAiFinOps\Support\TraceContext;

/**
 * Host-side, finops-guarded wrapper around the package `TraceContext` (v8.16/W3).
 *
 * A chat turn generates ONE trace id and runs the `AiManager::chat()` call
 * inside {@see within()} so the finops metering hook (and the `AiCallMeter`
 * bridge) stamp that SAME `trace_id` on the `ai_finops_usage_ledger` row. The
 * controller then persists the identical trace id on the turn's `chat_logs`
 * row, so the per-turn chat log and the authoritative ledger entry join on
 * `trace_id` for reconciliation — replacing the old synthetic-`invocationId` gap.
 *
 * Guarded: when the finops package is absent the wrapper is a transparent
 * passthrough (the call still runs; there is simply no ambient trace to stamp).
 */
final class ChatTraceContext
{
    public static function newTraceId(): string
    {
        return (string) Str::uuid();
    }

    /**
     * @template T
     *
     * @param  callable():T  $callback
     * @return T
     */
    public static function within(string $traceId, callable $callback): mixed
    {
        if (! class_exists(TraceContext::class)) {
            return $callback();
        }

        // The package context array is snake_cased (`trace_id`), per TraceContext::within().
        return app(TraceContext::class)->within(['trace_id' => $traceId], $callback);
    }
}
