import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor, cleanup } from '@testing-library/react';
import type { AxiosResponse } from 'axios';

/*
 * v8.13/P11 — EvidenceRiskReviewView (native cross-mount shell).
 *
 * Mocks the host axios module so each scenario controls how the gated data
 * probe (GET /api/admin/evidence-risk-review/reviews) resolves, and asserts the
 * host wrapper exposes the matching data-state for E2E + observability.
 *
 * R43 — the OFF path (probe 404 → clean "unavailable" landing) and the ON path
 * (probe resolves → dashboards mount) are BOTH covered here, never just one.
 * R16 — each test name promises a behaviour the body actually drives.
 */

type GetReply = { kind: 'ok'; data: unknown } | { kind: 'fail'; status: number } | { kind: 'pending' };

const apiMock: { getResponses: Record<string, GetReply> } = { getResponses: {} };

function axiosOk(data: unknown): AxiosResponse<unknown> {
    return { data, status: 200, statusText: 'OK', headers: {}, config: {} } as unknown as AxiosResponse<unknown>;
}

vi.mock('../../../lib/api', () => {
    return {
        api: {
            get: vi.fn(async (url: string) => {
                const reply = apiMock.getResponses[url] ?? apiMock.getResponses['*'];
                if (!reply || reply.kind === 'fail') {
                    const status = reply && reply.kind === 'fail' ? reply.status : 404;
                    const error = new Error('mock ' + status) as Error & { isAxiosError: true; response?: { status: number } };
                    error.isAxiosError = true;
                    error.response = { status };
                    throw error;
                }
                if (reply.kind === 'pending') {
                    return new Promise(() => {});
                }
                return axiosOk(reply.data);
            }),
            // The inner native SPA fetches reviews / profiles via api.request.
            request: vi.fn(async (config: { url?: string }) => {
                const url = config.url ?? '';
                if (url.includes('/profiles')) {
                    return axiosOk({ profiles: {} });
                }
                return axiosOk({ data: [], current_page: 1, last_page: 1, per_page: 20, total: 0 });
            }),
        },
        ensureCsrfCookie: vi.fn(async () => {}),
        resetCsrf: vi.fn(),
    };
});

import { EvidenceRiskReviewView } from './EvidenceRiskReviewView';

const PROBE_URL = '/api/admin/evidence-risk-review/reviews';

beforeEach(() => {
    apiMock.getResponses = {};
});

afterEach(() => {
    cleanup();
});

describe('EvidenceRiskReviewView (native cross-mount shell)', () => {
    it('renders the loading state while the data probe is pending (no SPA mounted yet)', async () => {
        apiMock.getResponses[PROBE_URL] = { kind: 'pending' };

        render(<EvidenceRiskReviewView />);

        const host = await screen.findByTestId('admin-evidence-risk-review-host');
        expect(host).toHaveAttribute('data-state', 'loading');
        expect(host).toHaveAttribute('data-mount', 'cross-mount');
        expect(screen.getByTestId('admin-evidence-risk-review-loading')).toBeInTheDocument();
        expect(screen.queryByTestId('admin-evidence-risk-review-app')).not.toBeInTheDocument();
    });

    it('mounts the native SPA when the data probe resolves — data-state=ready (R43 ON path)', async () => {
        apiMock.getResponses[PROBE_URL] = {
            kind: 'ok',
            data: { data: [], current_page: 1, last_page: 1, per_page: 20, total: 0 },
        };

        render(<EvidenceRiskReviewView />);

        await waitFor(() => {
            expect(screen.getByTestId('admin-evidence-risk-review-host')).toHaveAttribute('data-state', 'ready');
        });
        expect(await screen.findByTestId('admin-evidence-risk-review-app')).toBeInTheDocument();
        expect(screen.queryByTestId('admin-evidence-risk-review-unavailable')).not.toBeInTheDocument();
    });

    it('shows a clean unavailable landing (NOT the SPA) when the probe 404s — R43 OFF path degrades cleanly', async () => {
        apiMock.getResponses[PROBE_URL] = { kind: 'fail', status: 404 };

        render(<EvidenceRiskReviewView />);

        await waitFor(() => {
            expect(screen.getByTestId('admin-evidence-risk-review-host')).toHaveAttribute('data-state', 'unavailable');
        });
        expect(screen.getByTestId('admin-evidence-risk-review-unavailable')).toBeInTheDocument();
        expect(screen.queryByTestId('admin-evidence-risk-review-app')).not.toBeInTheDocument();
    });
});
