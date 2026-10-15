import { useUiTranslation } from "../../lib/ui-locale";
import type { ReactNode } from 'react';
import { useQuery } from '@tanstack/react-query';
import { meDashboardApi, ME_BADGES_QUERY_KEY } from './me-dashboard.api';
import { Award } from 'lucide-react';

/**
 * v8.15/W5 — the gamification badges section on the "My KB" dashboard.
 *
 * Opt-in: when the backend reports `enabled: false` (KB_GAMIFICATION_ENABLED off)
 * the section renders nothing at all. Earned badges are highlighted; locked ones
 * show progress toward their threshold. R11 testids per badge.
 */
export function MeBadges(): ReactNode {
    const { t: translateInterface } = useUiTranslation();
    const query = useQuery({
        queryKey: ME_BADGES_QUERY_KEY,
        queryFn: () => meDashboardApi.badges(),
        refetchOnWindowFocus: false,
        staleTime: 60_000,
    });

    // Surface a real backend/API failure instead of swallowing it (R14).
    if (query.isError) {
        return (
            <section data-testid="me-badges" data-state="error" role="alert" style={{ marginTop: 20 }}>
                Could not load your badges.{' '}
                <button type="button" data-testid="me-badges-retry" onClick={() => void query.refetch()}>{translateInterface("Retry")}</button>
            </section>
        );
    }

    // Still loading, or gamification off (enabled:false) → render nothing (no empty box).
    if (!query.data?.enabled) {
        return null;
    }

    return (
        <section data-testid="me-badges" data-state="ready" aria-busy={query.isFetching} style={{ marginTop: 20 }}>
            <h3>{translateInterface("Your badges")}</h3>
            <div style={{ display: 'flex', flexWrap: 'wrap', gap: 12 }}>
                {query.data.badges.map((b) => (
                    <div
                        key={b.key}
                        data-testid={`me-badge-${b.key}`}
                        data-earned={b.earned ? 'true' : 'false'}
                        title={b.earned ? 'Earned' : `${b.progress}/${b.threshold}`}
                        style={{
                            border: '1px solid var(--hairline)',
                            borderRadius: 12,
                            padding: '10px 14px',
                            opacity: b.earned ? 1 : 0.45,
                            minWidth: 120,
                            textAlign: 'center',
                        }}
                    >
                        <div className="badge-art" aria-hidden="true"><Award size={26} /></div>
                        <div style={{ fontWeight: 600 }}>{translateInterface(b.label)}</div>
                        <div style={{ fontSize: 12, color: 'var(--fg-3)' }}>
                            {b.earned ? 'Earned' : `${b.progress}/${b.threshold}`}
                        </div>
                    </div>
                ))}
            </div>
        </section>
    );
}
