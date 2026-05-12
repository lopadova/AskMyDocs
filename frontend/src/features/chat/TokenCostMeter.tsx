import { useQuery } from '@tanstack/react-query';
import { type ReactNode } from 'react';
import { chatCostApi, computeMessageCost, type CostRateTable } from './chat.api';

export interface TokenCostMeterProps {
    provider?: string;
    model?: string;
    promptTokens?: number;
    completionTokens?: number;
    totalTokens?: number;
}

/**
 * v4.5/W7 Tier 1 #5 — small inline pill rendering the per-turn token
 * total + the USD cost. Reads cost rates from `GET /api/chat/cost-rates`
 * (session-scoped cache, stale-time 1h to mirror the BE Cache-Control
 * header).
 *
 * Renders nothing when the message has no token telemetry (user turns,
 * legacy rows that pre-date the v3.0 grounding tier). Renders
 * `1,254 tok · $0.012` when cost is computable, otherwise just
 * `1,254 tok` (when the provider has no rate entry — e.g. a custom
 * OpenRouter model we haven't priced yet).
 *
 * R11: `data-testid="chat-token-cost"` so Playwright can assert the
 * meter renders on the happy path AND the absence of a `$` symbol
 * when cost is null.
 */
export function TokenCostMeter({
    provider,
    model,
    promptTokens,
    completionTokens,
    totalTokens,
}: TokenCostMeterProps): ReactNode {
    const tokensTotal = totalTokens ?? ((promptTokens ?? 0) + (completionTokens ?? 0));
    const ratesQuery = useQuery<CostRateTable>({
        queryKey: ['chat-cost-rates'],
        queryFn: () => chatCostApi.fetchRates(),
        staleTime: 60 * 60 * 1000,
        gcTime: 60 * 60 * 1000,
        // Skip the network request entirely when the meter won't render
        // (no token telemetry, or provider/model absent). The early return
        // below short-circuits the render, but without this guard the
        // query fires for every assistant bubble that has no token data.
        enabled: tokensTotal > 0 && !!provider && !!model,
    });

    if (tokensTotal <= 0) {
        return null;
    }

    const rates = ratesQuery.data ?? {};
    const cost = computeMessageCost(rates, provider, model, promptTokens, completionTokens);

    return (
        <span
            data-testid="chat-token-cost"
            data-cost-available={cost !== null ? 'true' : 'false'}
            className="mono"
            style={{
                fontSize: 10.5,
                color: 'var(--fg-3)',
                marginLeft: 6,
                display: 'inline-flex',
                alignItems: 'center',
                gap: 6,
            }}
            title={`${promptTokens ?? 0} input + ${completionTokens ?? 0} output tokens`}
        >
            <span>{formatTokenCount(tokensTotal)} tok</span>
            {cost !== null && (
                <span data-testid="chat-token-cost-usd">{formatCost(cost)}</span>
            )}
        </span>
    );
}

function formatTokenCount(n: number): string {
    // Always shown with thousand separator; never abbreviate — exact
    // counts matter for cost-conscious users.
    return n.toLocaleString('en-US');
}

/**
 * Format the cost in USD. Costs below 1 cent are shown to 4 decimals
 * so a user can still see a non-zero number for a cheap turn
 * ("$0.0024"). Costs above $1 are shown to 2 decimals ("$1.23").
 * Always shows the leading "$" so the test asserting "cost present"
 * has a stable signal.
 */
export function formatCost(usd: number): string {
    if (usd === 0) {
        return '$0';
    }
    if (Math.abs(usd) < 0.01) {
        return `$${usd.toFixed(4)}`;
    }
    if (Math.abs(usd) < 1) {
        return `$${usd.toFixed(3)}`;
    }
    return `$${usd.toFixed(2)}`;
}
