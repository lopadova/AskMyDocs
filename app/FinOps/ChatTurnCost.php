<?php

declare(strict_types=1);

namespace App\FinOps;

/**
 * The server-resolved cost of a single chat turn (v8.16/W3).
 *
 * Returned by {@see ChatTurnCostResolver}; persisted on `chat_logs.cost` and
 * surfaced additively in the chat response `meta` so the FE renders the real
 * cost instead of computing one client-side from static rates.
 */
final readonly class ChatTurnCost
{
    public function __construct(
        /**
         * The total cost as a fixed-precision DECIMAL STRING (8 dp), not a float —
         * a money value is never carried as a float. The upstream finops package
         * exposes the total as a PHP float, so this is a STABLE 8-dp serialization
         * matching the `chat_logs.cost` decimal(18,8) column + the ledger's
         * cost_total precision (no further drift once serialized: resolver → DB →
         * JSON meta).
         */
        public string $cost,
        public string $currency,
        /** One of the finops CostMethod values: actual|computed|estimated|covered. */
        public string $method,
    ) {}
}
