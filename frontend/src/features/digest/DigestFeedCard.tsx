import { useUiTranslation } from "../../lib/ui-locale";
import type { ReactNode } from 'react';
import { Button } from '../../components/Button';
import { EditorialArtwork } from '../../components/editorial/EditorialArtwork';
import { useQuery } from '@tanstack/react-query';
import { digestApi, DIGEST_LATEST_QUERY_KEY, type DigestLatest } from './digest.api';
import './digest.css';

/**
 * v8.15/W3.2 — "This week in your KB" in-app digest card. Reads
 * /api/me/digest/latest and renders the sections the user has enabled. R11
 * (testids), R14 (error surfaced), explicit empty/loading/ready/error states.
 */
export function DigestFeedCard(): ReactNode {
    const { t: translateInterface } = useUiTranslation();
    const query = useQuery({
        queryKey: DIGEST_LATEST_QUERY_KEY,
        queryFn: () => digestApi.latest(),
        refetchOnWindowFocus: false,
        staleTime: 60_000,
    });

    const dataState = query.isError
        ? 'error'
        : query.isLoading
            ? 'loading'
            : !query.data?.has_digest
                ? 'empty'
                : 'ready';

    const data: DigestLatest | undefined = query.data;
    const enabled = (key: string): boolean => (data?.enabled_sections ?? []).includes(key);
    const d = data?.digest ?? {};
    const m = (d.metrics ?? {}) as Record<string, unknown>;

    return (
        <section
            data-testid="digest-feed-card"
            data-state={dataState}
            aria-busy={query.isFetching}
            className="digest-feed editorial-surface"
        >
            <EditorialArtwork artwork="observatory" className="editorial-surface-art" resolution="small" />
            <h2 className="digest-panel-title">{translateInterface("This week in your KB")}</h2>

            {dataState === 'loading' && <p data-testid="digest-feed-loading">{translateInterface("Loading…")}</p>}

            {dataState === 'error' && (
                <div className="digest-message" data-testid="digest-feed-error" role="alert">
                    {translateInterface("Could not load the latest digest.")}{' '}
                    <Button variant="quiet" size="sm" data-testid="digest-feed-retry" onClick={() => void query.refetch()}>
                        {translateInterface("Retry")}</Button>
                </div>
            )}

            {dataState === 'empty' && (
                <p className="digest-message" data-testid="digest-feed-empty">{translateInterface("No digest yet — it will appear here after the next run.")}</p>
            )}

            {dataState === 'ready' && data?.digest && (
                <div className="digest-feed-content" data-testid="digest-feed-content">
                    {typeof d.narrative === 'string' && d.narrative !== '' && (
                        <p className="digest-narrative" data-testid="digest-feed-narrative">{d.narrative}</p>
                    )}

                    {enabled('metrics') && (
                        <dl data-testid="digest-feed-metrics" className="digest-metrics">
                            <div><dt>{translateInterface("Contributors")}</dt><dd>{String(m.contributors ?? 0)}</dd></div>
                            <div><dt>{translateInterface("New documents")}</dt><dd>{String(m.new_docs ?? 0)}</dd></div>
                            <div><dt>{translateInterface("Promoted documents")}</dt><dd>{String(m.promoted_docs ?? 0)}</dd></div>
                            <div><dt>{translateInterface("Open questions")}</dt><dd>{String(m.open_gaps ?? 0)}</dd></div>
                        </dl>
                    )}

                    {enabled('new_docs') && (d.new_docs?.length ?? 0) > 0 && (
                        <section className="digest-feed-section" data-testid="digest-feed-new-docs">
                            <h3>{translateInterface("New & promoted")}</h3>
                            <ul>
                                {d.new_docs!.map((doc, i) => (
                                    <li key={i}>{doc.title} <em>({doc.change})</em></li>
                                ))}
                            </ul>
                        </section>
                    )}

                    {enabled('stale_docs') && (d.stale_docs?.length ?? 0) > 0 && (
                        <section className="digest-feed-section" data-testid="digest-feed-stale">
                            <h3>{translateInterface("Needs review")}</h3>
                            <ul>
                                {d.stale_docs!.map((doc, i) => (
                                    <li key={i}>{doc.title} <em>{translateInterface("(debt") + " "}{doc.debt_score})</em></li>
                                ))}
                            </ul>
                        </section>
                    )}

                    {enabled('top_gaps') && (d.top_gaps?.length ?? 0) > 0 && (
                        <section className="digest-feed-section" data-testid="digest-feed-gaps">
                            <h3>{translateInterface("Top unanswered")}</h3>
                            <ul>
                                {d.top_gaps!.map((gap, i) => (
                                    <li key={i}>{gap.question} <em>({gap.occurrences}×)</em></li>
                                ))}
                            </ul>
                        </section>
                    )}

                    {enabled('leaderboard') && (d.leaderboard?.length ?? 0) > 0 && (
                        <section className="digest-feed-section" data-testid="digest-feed-leaderboard">
                            <h3>{translateInterface("Top contributors")}</h3>
                            <ul>
                                {d.leaderboard!.map((c, i) => (
                                    <li key={i}>{c.name} <em>({c.score} {" " + translateInterface("pts)")}</em></li>
                                ))}
                            </ul>
                        </section>
                    )}
                </div>
            )}
        </section>
    );
}
