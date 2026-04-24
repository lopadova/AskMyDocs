import { AdminShell } from '../shell/AdminShell';
import { useInsightsLatest } from './insights.api';
import { PromotionSuggestionsCard } from './PromotionSuggestionsCard';
import { OrphanDocsCard } from './OrphanDocsCard';
import { SuggestedTagsCard } from './SuggestedTagsCard';
import { CoverageGapsCard } from './CoverageGapsCard';
import { StaleDocsCard } from './StaleDocsCard';
import { QualityReportCard } from './QualityReportCard';

/*
 * Phase I — admin AI insights view.
 *
 * Renders the 6 widget cards side-by-side + a "Today we recommend…"
 * header summarising the aggregate counts. Every card subscribes to
 * the same snapshot via `useInsightsLatest()` so one request powers
 * the whole view.
 *
 * No LLM call happens on this route except when the user clicks
 * "Recompute now" (super-admin only; surfaced via the compute button).
 */

export function InsightsView() {
    const latest = useInsightsLatest();

    const snapshot = latest.data?.data ?? null;
    const state: 'loading' | 'ready' | 'error' | 'empty' = latest.isLoading
        ? 'loading'
        : latest.isError
          ? isNoSnapshot(latest.error)
              ? 'empty'
              : 'error'
          : snapshot
            ? 'ready'
            : 'empty';

    const promotions = snapshot?.suggest_promotions ?? [];
    const orphans = snapshot?.orphan_docs ?? [];
    const tags = snapshot?.suggested_tags ?? [];

    return (
        <AdminShell section="insights">
            <div
                data-testid="insights-view"
                data-state={state}
                style={{ display: 'flex', flexDirection: 'column', gap: 16, minHeight: 0 }}
            >
                <header>
                    <h1
                        style={{
                            fontSize: 20,
                            fontWeight: 600,
                            margin: '0 0 2px',
                            color: 'var(--fg-0)',
                        }}
                    >
                        AI Insights
                    </h1>
                    <p style={{ fontSize: 12.5, color: 'var(--fg-3)', margin: 0 }}>
                        Daily-computed signals across the knowledge base.
                        {snapshot?.computed_at ? ` Last run ${snapshot.computed_at}.` : null}
                    </p>
                </header>

                {state === 'loading' ? (
                    <div data-testid="insights-loading" style={{ color: 'var(--fg-3)' }}>
                        Loading insights…
                    </div>
                ) : null}

                {state === 'empty' ? (
                    <div
                        data-testid="insights-no-snapshot"
                        style={{
                            padding: '20px',
                            border: '1px dashed var(--hairline)',
                            borderRadius: 8,
                            color: 'var(--fg-2)',
                            fontSize: 13,
                        }}
                    >
                        No insights snapshot has been computed yet.
                        Run <code>php artisan insights:compute</code> (or POST
                        {' '}<code>/api/admin/insights/compute</code>) to
                        populate the widgets below.
                    </div>
                ) : null}

                {state === 'error' ? (
                    <div
                        data-testid="insights-error"
                        style={{
                            padding: '14px 18px',
                            border: '1px solid var(--danger-fg, #b91c1c)',
                            background: 'var(--danger-bg, #fee)',
                            borderRadius: 8,
                            color: 'var(--danger-fg, #b91c1c)',
                            fontSize: 13,
                        }}
                    >
                        Failed to load insights. Try reloading the page.
                    </div>
                ) : null}

                {state === 'ready' && snapshot ? (
                    <>
                        <HighlightStrip
                            promotions={promotions.length}
                            orphans={orphans.length}
                            tags={tags.length}
                        />
                        <div
                            style={{
                                display: 'grid',
                                gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))',
                                gap: 14,
                            }}
                        >
                            <PromotionSuggestionsCard items={promotions} />
                            <OrphanDocsCard items={orphans} />
                            <SuggestedTagsCard items={tags} />
                            <CoverageGapsCard items={snapshot.coverage_gaps ?? []} />
                            <StaleDocsCard items={snapshot.stale_docs ?? []} />
                            <QualityReportCard report={snapshot.quality_report} />
                        </div>
                    </>
                ) : null}
            </div>
        </AdminShell>
    );
}

function isNoSnapshot(err: unknown): boolean {
    // A 404 from /latest means "no snapshot yet" — treat as empty, not
    // error. The axios error shape bubbles `response.status`; keep this
    // defensive because the lib types bypass our own ApiError.
    if (err && typeof err === 'object' && 'response' in err) {
        const response = (err as { response?: { status?: number } }).response;
        return response?.status === 404;
    }
    return false;
}

interface HighlightStripProps {
    promotions: number;
    orphans: number;
    tags: number;
}

function HighlightStrip({ promotions, orphans, tags }: HighlightStripProps) {
    return (
        <div
            data-testid="insights-highlights"
            style={{
                display: 'grid',
                gridTemplateColumns: 'repeat(3, 1fr)',
                gap: 12,
            }}
        >
            <Kpi testId="insights-highlight-promotions" label="Promotable docs" count={promotions} />
            <Kpi testId="insights-highlight-orphans" label="Orphan canonical" count={orphans} />
            <Kpi testId="insights-highlight-tags" label="Need tags" count={tags} />
        </div>
    );
}

function Kpi({ testId, label, count }: { testId: string; label: string; count: number }) {
    return (
        <div
            data-testid={testId}
            style={{
                background: 'var(--bg-1)',
                border: '1px solid var(--hairline)',
                borderRadius: 8,
                padding: '14px 16px',
            }}
        >
            <div style={{ fontSize: 22, fontWeight: 600, color: 'var(--fg-0)' }}>{count}</div>
            <div style={{ fontSize: 11.5, color: 'var(--fg-3)', marginTop: 2 }}>{label}</div>
        </div>
    );
}
