import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { TokenCostMeter, formatCost } from './TokenCostMeter';
import { computeMessageCost, type CostRateTable } from './chat.api';

/**
 * v4.5/W7 tests for the token/cost meter. R16: every test exercises
 * the behaviour its name claims — fixtures use real, non-trivial
 * token counts and the assertions are strict equality on rendered
 * substrings.
 */

const SAMPLE_RATES: CostRateTable = {
    openai: {
        default: { input: 2.5, output: 10.0 },
        'gpt-4o': { input: 2.5, output: 10.0 },
        'gpt-4o-mini': { input: 0.15, output: 0.6 },
    },
    anthropic: {
        default: { input: 3.0, output: 15.0 },
    },
};

function wrap(ui: ReactNode, rates: CostRateTable = SAMPLE_RATES): ReactNode {
    const qc = new QueryClient({
        defaultOptions: {
            queries: { retry: false, gcTime: 0, staleTime: Infinity },
        },
    });
    qc.setQueryData(['chat-cost-rates'], rates);
    return <QueryClientProvider client={qc}>{ui}</QueryClientProvider>;
}

describe('computeMessageCost', () => {
    it('returns null when provider is missing', () => {
        expect(computeMessageCost(SAMPLE_RATES, undefined, 'gpt-4o', 1000, 500)).toBeNull();
    });

    it('returns null when model is missing', () => {
        expect(computeMessageCost(SAMPLE_RATES, 'openai', undefined, 1000, 500)).toBeNull();
    });

    it('returns null for unknown provider (no default to fall back on)', () => {
        expect(computeMessageCost(SAMPLE_RATES, 'mistral', 'mistral-large', 1000, 500)).toBeNull();
    });

    it('falls back to provider default when model is unknown', () => {
        const cost = computeMessageCost(SAMPLE_RATES, 'anthropic', 'unknown-claude', 1_000_000, 0);
        // 1M input tokens × $3/M = $3 exactly.
        expect(cost).toBe(3);
    });

    it('computes the linear sum: prompt*input_rate + completion*output_rate', () => {
        // 1000 input @ $2.5/M + 500 output @ $10/M
        //   = 0.0025 + 0.005 = 0.0075
        const cost = computeMessageCost(SAMPLE_RATES, 'openai', 'gpt-4o', 1000, 500);
        expect(cost).toBeCloseTo(0.0075, 6);
    });

    it('treats missing token counts as zero (no NaN)', () => {
        const cost = computeMessageCost(SAMPLE_RATES, 'openai', 'gpt-4o', undefined, undefined);
        expect(cost).toBe(0);
    });
});

describe('formatCost', () => {
    it('renders exact 0 as "$0"', () => {
        expect(formatCost(0)).toBe('$0');
    });

    it('renders sub-cent values to 4 decimals', () => {
        expect(formatCost(0.0024)).toBe('$0.0024');
    });

    it('renders sub-dollar values to 3 decimals', () => {
        expect(formatCost(0.123)).toBe('$0.123');
    });

    it('renders dollar values to 2 decimals', () => {
        expect(formatCost(1.234)).toBe('$1.23');
        expect(formatCost(12)).toBe('$12.00');
    });

    it('renders a non-USD currency with a trailing ISO code (v8.16/W3)', () => {
        expect(formatCost(0, 'EUR')).toBe('0 EUR');
        expect(formatCost(0.0024, 'EUR')).toBe('0.0024 EUR');
        expect(formatCost(1.234, 'GBP')).toBe('1.23 GBP');
    });
});

describe('TokenCostMeter', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders nothing when total tokens is 0', () => {
        const { container } = render(wrap(
            <TokenCostMeter
                provider="openai"
                model="gpt-4o"
                promptTokens={0}
                completionTokens={0}
                totalTokens={0}
            />,
        ));
        expect(container.firstChild).toBeNull();
    });

    it('renders token count only when provider has no rate entry', async () => {
        render(wrap(
            <TokenCostMeter
                provider="unknown-vendor"
                model="unknown-model"
                promptTokens={500}
                completionTokens={300}
                totalTokens={800}
            />,
        ));
        const meter = await screen.findByTestId('chat-token-cost');
        expect(meter).toHaveAttribute('data-cost-available', 'false');
        expect(meter).toHaveTextContent('800 tok');
        // No `$` should appear.
        expect(meter.textContent ?? '').not.toContain('$');
        expect(screen.queryByTestId('chat-token-cost-usd')).toBeNull();
    });

    it('renders token count AND cost when provider+model match', async () => {
        render(wrap(
            <TokenCostMeter
                provider="openai"
                model="gpt-4o"
                promptTokens={1_000_000}
                completionTokens={0}
                totalTokens={1_000_000}
            />,
        ));
        const meter = await screen.findByTestId('chat-token-cost');
        await waitFor(() => {
            expect(meter).toHaveAttribute('data-cost-available', 'true');
        });
        // 1M input @ $2.5/M = $2.50
        expect(screen.getByTestId('chat-token-cost-usd')).toHaveTextContent('$2.50');
        expect(meter).toHaveTextContent('1,000,000 tok');
    });

    it('renders the SERVER-resolved cost directly, ignoring client rates (v8.16/W3)', async () => {
        // serverCost present → the meter must render it verbatim (decimal string
        // parsed) WITHOUT computing from the rate table. Rates here would give a
        // different number, so a match proves the server cost won.
        render(wrap(
            <TokenCostMeter
                provider="openai"
                model="gpt-4o"
                promptTokens={1000}
                completionTokens={500}
                totalTokens={1500}
                serverCost="0.01230000"
                serverCostCurrency="USD"
            />,
        ));
        const meter = await screen.findByTestId('chat-token-cost');
        expect(meter).toHaveAttribute('data-cost-available', 'true');
        expect(screen.getByTestId('chat-token-cost-usd')).toHaveTextContent('$0.012');
        expect(meter).toHaveTextContent('1,500 tok');
    });

    it('renders a server cost of exactly 0 (metered, zero-priced) as a present cost', async () => {
        render(wrap(
            <TokenCostMeter
                provider="regolo"
                model="Llama-3.3-70B-Instruct"
                promptTokens={800}
                completionTokens={200}
                totalTokens={1000}
                serverCost="0.00000000"
                serverCostCurrency="USD"
            />,
        ));
        const meter = await screen.findByTestId('chat-token-cost');
        expect(meter).toHaveAttribute('data-cost-available', 'true');
        expect(screen.getByTestId('chat-token-cost-usd')).toHaveTextContent('$0');
    });

    it('formats the title attribute with the input/output breakdown', async () => {
        render(wrap(
            <TokenCostMeter
                provider="openai"
                model="gpt-4o"
                promptTokens={123}
                completionTokens={456}
                totalTokens={579}
            />,
        ));
        const meter = await screen.findByTestId('chat-token-cost');
        expect(meter).toHaveAttribute('title', '123 input + 456 output tokens');
    });
});
