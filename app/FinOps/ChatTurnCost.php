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
         * money never round-trips through a float (Copilot R1). Mirrors the
         * `chat_logs.cost` decimal(18,8) cast + the ledger's cost_total precision,
         * so the value is lossless end-to-end (resolver → DB → JSON meta).
         */
        public string $cost,
        public string $currency,
        /** One of the finops CostMethod values: actual|computed|estimated|covered. */
        public string $method,
    ) {}
}
