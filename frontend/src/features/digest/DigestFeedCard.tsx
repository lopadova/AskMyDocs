import { useUiTranslation } from "../../lib/ui-locale";
import type { ReactNode } from 'react';
import { ArrowUp, Award, CircleHelp, Clock, FilePlus, Users } from 'lucide-react';
import { Button } from '../../components/Button';
import { useQuery } from '@tanstack/react-query';
import { digestApi, DIGEST_LATEST_QUERY_KEY, type DigestLatest } from './digest.api';

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
            style={{ padding: 20, border: '1px solid var(--hairline)', borderRadius: 14 }}
        >
            <h3 style={{ marginTop: 0 }}>{translateInterface("This week in your KB")}</h3>

            {dataState === 'loading' && <p data-testid="digest-feed-loading">{translateInterface("Loading…")}</p>}

            {dataState === 'error' && (
                <div data-testid="digest-feed-error" role="alert">
                    {translateInterface("Could not load the latest digest.")}{' '}
                    <Button variant="quiet" size="sm" data-testid="digest-feed-retry" onClick={() => void query.refetch()}>
                        {translateInterface("Retry")}</Button>
                </div>
            )}

            {dataState === 'empty' && (
                <p data-testid="digest-feed-empty">{translateInterface("No digest yet — it will appear here after the next run.")}</p>
            )}

            {dataState === 'ready' && data?.digest && (
                <div data-testid="digest-feed-content">
                    {typeof d.narrative === 'string' && d.narrative !== '' && (
                        <p data-testid="digest-feed-narrative">{d.narrative}</p>
                    )}

                    {enabled('metrics') && (
                        <ul data-testid="digest-feed-metrics" style={{ display: 'flex', gap: 16, listStyle: 'none', padding: 0, flexWrap: 'wrap' }}>
                            <li><Users size={15} aria-hidden /> {String(m.contributors ?? 0)} {translateInterface("contributors")}</li>
                            <li><FilePlus size={15} aria-hidden /> {String(m.new_docs ?? 0)} {translateInterface("new")}</li>
                            <li><ArrowUp size={15} aria-hidden /> {String(m.promoted_docs ?? 0)} {translateInterface("promoted")}</li>
                            <li><CircleHelp size={15} aria-hidden /> {String(m.open_gaps ?? 0)} {translateInterface("open gaps")}</li>
                        </ul>
                    )}

                    {enabled('new_docs') && (d.new_docs?.length ?? 0) > 0 && (
                        <div data-testid="digest-feed-new-docs">
                            <strong><FilePlus size={15} aria-hidden /> {translateInterface("New & promoted")}</strong>
                            <ul>
                                {d.new_docs!.map((doc, i) => (
                                    <li key={i}>{doc.title} <em>({doc.change})</em></li>
                                ))}
                            </ul>
                        </div>
                    )}

                    {enabled('stale_docs') && (d.stale_docs?.length ?? 0) > 0 && (
                        <div data-testid="digest-feed-stale">
                            <strong><Clock size={15} aria-hidden /> {translateInterface("Needs review")}</strong>
                            <ul>
                                {d.stale_docs!.map((doc, i) => (
                                    <li key={i}>{doc.title} <em>{translateInterface("(debt") + " "}{doc.debt_score})</em></li>
                                ))}
                            </ul>
                        </div>
                    )}

                    {enabled('top_gaps') && (d.top_gaps?.length ?? 0) > 0 && (
                        <div data-testid="digest-feed-gaps">
                            <strong><CircleHelp size={15} aria-hidden /> {translateInterface("Top unanswered")}</strong>
                            <ul>
                                {d.top_gaps!.map((gap, i) => (
                                    <li key={i}>{gap.question} <em>({gap.occurrences}×)</em></li>
                                ))}
                            </ul>
                        </div>
                    )}

                    {enabled('leaderboard') && (d.leaderboard?.length ?? 0) > 0 && (
                        <div data-testid="digest-feed-leaderboard">
                            <strong><Award size={15} aria-hidden /> {translateInterface("Top contributors")}</strong>
                            <ul>
                                {d.leaderboard!.map((c, i) => (
                                    <li key={i}>{c.name} <em>({c.score} {" " + translateInterface("pts)")}</em></li>
                                ))}
                            </ul>
                        </div>
                    )}
                </div>
            )}
        </section>
    );
}
