import { useQuery } from '@tanstack/react-query';
import { type ReactNode } from 'react';
import { chatCostApi, computeMessageCost, type CostRateTable } from './chat.api';

export interface TokenCostMeterProps {
    provider?: string;
    model?: string;
    promptTokens?: number;
    completionTokens?: number;
    totalTokens?: number;
    // v8.16/W3 — authoritative SERVER-resolved cost (finops pricing cascade) as a
    // decimal string + ISO currency. When present it is rendered DIRECTLY and the
    // client-side rate compute (+ its /api/chat/cost-rates fetch) is skipped
    // entirely. The legacy client compute is the fallback for legacy rows / when
    // finops metering is off (server cost null).
    serverCost?: string | null;
    serverCostCurrency?: string | null;
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
    serverCost,
    serverCostCurrency,
}: TokenCostMeterProps): ReactNode {
    const tokensTotal = totalTokens ?? ((promptTokens ?? 0) + (completionTokens ?? 0));

    // Server cost wins. A finite parsed value (incl. 0) means "authoritative cost
    // present" — skip the client-side rate fetch + compute entirely.
    const parsedServerCost =
        serverCost != null && serverCost !== '' ? Number(serverCost) : null;
    const hasServerCost = parsedServerCost !== null && Number.isFinite(parsedServerCost);

    const ratesQuery = useQuery<CostRateTable>({
        queryKey: ['chat-cost-rates'],
        queryFn: () => chatCostApi.fetchRates(),
        staleTime: 60 * 60 * 1000,
        gcTime: 60 * 60 * 1000,
        // Skip the network request when the meter won't render (no token
        // telemetry, or provider/model absent) OR when the server already gave us
        // an authoritative cost (the whole point of W3 — no client-side compute).
        enabled: tokensTotal > 0 && !!provider && !!model && !hasServerCost,
    });

    if (tokensTotal <= 0) {
        return null;
    }

    const rates = ratesQuery.data ?? {};
    const cost = hasServerCost
        ? parsedServerCost
        : computeMessageCost(rates, provider, model, promptTokens, completionTokens);
    const currency = hasServerCost ? (serverCostCurrency ?? 'USD') : 'USD';

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
                <span data-testid="chat-token-cost-usd">{formatCost(cost, currency)}</span>
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
 * Format a cost amount. Costs below 1 cent are shown to 4 decimals so a user can
 * still see a non-zero number for a cheap turn ("$0.0024"); sub-dollar to 3
 * decimals; above 1 to 2 decimals. USD (the default + the legacy client-compute
 * currency) renders with a leading "$"; any other ISO currency (v8.16/W3
 * server-resolved cost when `ai-finops.currency.base` differs) renders with a
 * trailing code ("0.0024 EUR") — the test asserting "cost present" keeps its
 * stable "$" signal on the USD path.
 */
export function formatCost(amount: number, currency = 'USD'): string {
    const isUsd = currency === 'USD';
    const prefix = isUsd ? '$' : '';
    const suffix = isUsd ? '' : ` ${currency}`;

    if (amount === 0) {
        return `${prefix}0${suffix}`;
    }
    const abs = Math.abs(amount);
    const digits = abs < 0.01 ? 4 : abs < 1 ? 3 : 2;

    return `${prefix}${amount.toFixed(digits)}${suffix}`;
}
