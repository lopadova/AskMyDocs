import { useUiTranslation } from "../../lib/ui-locale";
import type { ReactNode } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Button } from '../../components/Button';
import { EditorialArtwork } from '../../components/editorial/EditorialArtwork';
import type { Artwork } from '../../components/shell/navigation';
import { meDashboardApi, ME_BADGES_QUERY_KEY } from './me-dashboard.api';
import './editorial-dashboard.css';

const badgeArtwork: Artwork[] = ['knowledge', 'community', 'connections', 'archive', 'processes'];

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
            <section data-testid="me-badges" data-state="error" role="alert" className="personal-dashboard-notice">
                Could not load your badges.{' '}
                <Button size="sm" data-testid="me-badges-retry" onClick={() => void query.refetch()} busy={query.isFetching}>{translateInterface("Retry")}</Button>
            </section>
        );
    }

    // Still loading, or gamification off (enabled:false) → render nothing (no empty box).
    if (!query.data?.enabled) {
        return null;
    }

    return (
        <section data-testid="me-badges" data-state="ready" aria-busy={query.isFetching} className="personal-badges">
            <h3>{translateInterface("Your badges")}</h3>
            <div className="personal-badge-grid">
                {query.data.badges.map((b, index) => (
                    <div
                        key={b.key}
                        data-testid={`me-badge-${b.key}`}
                        data-earned={b.earned ? 'true' : 'false'}
                        title={b.earned ? translateInterface('Earned') : `${b.progress}/${b.threshold}`}
                        className="personal-badge editorial-surface"
                    >
                        <EditorialArtwork artwork={badgeArtwork[index % badgeArtwork.length]} className="editorial-surface-art" resolution="small" />
                        <div className="personal-badge-label">{translateInterface(b.label)}</div>
                        <div className="personal-badge-status">
                            {b.earned ? translateInterface('Earned') : `${b.progress}/${b.threshold}`}
                        </div>
                        <progress
                            className="personal-badge-progress"
                            aria-label={translateInterface(b.label)}
                            max={Math.max(1, b.threshold)}
                            value={b.earned ? Math.max(1, b.threshold) : Math.min(Math.max(0, b.progress), Math.max(1, b.threshold))}
                        />
                    </div>
                ))}
            </div>
        </section>
    );
}
