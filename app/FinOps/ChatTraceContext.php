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
    /**
     * Tracing is active only when finops metering is ON — i.e. only when a usage
     * ledger row is actually written for this turn. Mirrors
     * {@see ChatTurnCostResolver::enabled()} so `chat_logs.trace_id` is populated
     * EXACTLY when there is a ledger row to join — never a dangling join key.
     */
    public static function enabled(): bool
    {
        if (! class_exists(TraceContext::class)) {
            return false;
        }

        return (bool) config('ai-finops.enabled', true)
            && (bool) config('ai-finops.metering', true);
    }

    /**
     * A fresh trace id when tracing is active, else NULL — a null trace id keeps
     * the chat_logs row honest (no ledger row exists to correlate to).
     */
    public static function newTraceId(): ?string
    {
        return self::enabled() ? (string) Str::uuid() : null;
    }

    /**
     * Run $callback inside the finops trace context for $traceId. A null trace id
     * (or tracing disabled) is a transparent passthrough.
     *
     * @template T
     *
     * @param  callable():T  $callback
     * @return T
     */
    public static function within(?string $traceId, callable $callback): mixed
    {
        if ($traceId === null || ! self::enabled()) {
            return $callback();
        }

        // The package context array is snake_cased (`trace_id`), per TraceContext::within().
        return app(TraceContext::class)->within(['trace_id' => $traceId], $callback);
    }
}
