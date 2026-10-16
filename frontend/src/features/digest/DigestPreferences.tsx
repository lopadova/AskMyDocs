import { useUiTranslation } from "../../lib/ui-locale";
import { useEffect, useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import { Button } from '../../components/Button';
import { EditorialArtwork } from '../../components/editorial/EditorialArtwork';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { digestApi, DIGEST_PREFERENCES_QUERY_KEY } from './digest.api';
import './digest.css';

const SECTION_LABELS: Record<string, string> = {
    metrics: 'Activity overview',
    new_docs: 'New & promoted',
    stale_docs: 'Needs review',
    top_gaps: 'Top unanswered',
    leaderboard: 'Top contributors',
};

const FREQUENCY_HINTS: Record<string, string> = {
    weekly: 'A summary every week.',
    monthly: 'A summary every month.',
    off: 'Pause scheduled updates.',
};

/**
 * v8.15/W3.2 — per-user digest preferences: cadence (radio) + section toggles
 * (checkboxes). Reads/writes /api/me/digest-preferences. Follows R11 (stable
 * testids), R15 (every control programmatically labelled), R14 (save errors
 * surface in the DOM), R16/R17 (edits seeded once, dirty drives Save).
 */
export function DigestPreferences(): ReactNode {
    const { t: translateInterface } = useUiTranslation();
    const qc = useQueryClient();

    const query = useQuery({
        queryKey: DIGEST_PREFERENCES_QUERY_KEY,
        queryFn: () => digestApi.loadPreferences(),
        refetchOnWindowFocus: false,
        staleTime: 5 * 60_000,
    });

    const [frequency, setFrequency] = useState<string | null>(null);
    const [sections, setSections] = useState<string[] | null>(null);

    // Seed local edit state once from the server snapshot (R17).
    useEffect(() => {
        if (!query.data || frequency !== null) {
            return;
        }
        setFrequency(query.data.frequency);
        setSections(query.data.sections);
    }, [query.data, frequency]);

    const saveMut = useMutation({
        mutationFn: (payload: { frequency: string; sections: string[] | null }) => digestApi.savePreferences(payload),
        onSuccess: (data) => {
            qc.setQueryData(DIGEST_PREFERENCES_QUERY_KEY, data);
            setFrequency(data.frequency);
            setSections(data.sections);
        },
    });

    const dataState = query.isError
        ? 'error'
        : query.isLoading || frequency === null || sections === null
            ? 'loading'
            : 'ready';

    const dirty = useMemo(() => {
        if (!query.data || frequency === null || sections === null) {
            return false;
        }
        const a = [...sections].sort().join(',');
        const b = [...query.data.sections].sort().join(',');
        return frequency !== query.data.frequency || a !== b;
    }, [frequency, sections, query.data]);

    const toggleSection = (key: string): void => {
        setSections((prev) => {
            const cur = prev ?? [];
            return cur.includes(key) ? cur.filter((s) => s !== key) : [...cur, key];
        });
    };

    return (
        <section
            data-testid="digest-pref"
            data-state={dataState}
            aria-busy={query.isFetching}
            className="digest-preferences editorial-surface"
        >
            <EditorialArtwork artwork="processes" className="editorial-surface-art" resolution="small" />
            <h2 className="digest-panel-title">{translateInterface("Digest preferences")}</h2>
            <p className="digest-panel-description">{translateInterface('Choose the rhythm and content of your updates.')}</p>

            {dataState === 'loading' && <p data-testid="digest-pref-loading">{translateInterface("Loading…")}</p>}

            {dataState === 'error' && (
                <div className="digest-message" data-testid="digest-pref-error" role="alert">
                    {translateInterface("Could not load your preferences.")}{' '}
                    <Button variant="quiet" size="sm" data-testid="digest-pref-retry" onClick={() => void query.refetch()}>
                        {translateInterface("Retry")}</Button>
                </div>
            )}

            {dataState === 'ready' && query.data && frequency !== null && sections !== null && (
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        // Persist null when EVERY available section is selected, so
                        // the choice stays "all" (default-in) and any section added
                        // to the catalog later auto-includes for this user. A strict
                        // subset persists verbatim; none persists [] (honest
                        // opt-out). The BE resolves null → all on read.
                        const all = query.data.available_sections;
                        const allSelected = all.length > 0 && all.every((s) => sections.includes(s));
                        saveMut.mutate({ frequency, sections: allSelected ? null : sections });
                    }}
                >
                    <fieldset className="digest-fieldset">
                        <legend>{translateInterface("How often?")}</legend>
                        <div className="digest-frequency-options">
                        {query.data.available_frequencies.map((freq) => (
                            <label key={freq} className="digest-frequency-option" data-selected={frequency === freq}>
                                <input
                                    type="radio"
                                    name="digest-frequency"
                                    value={freq}
                                    checked={frequency === freq}
                                    data-testid={`digest-pref-frequency-${freq}`}
                                    onChange={() => setFrequency(freq)}
                                />
                                <span>
                                    <strong>{translateInterface(freq === 'off' ? 'Off' : freq.charAt(0).toUpperCase() + freq.slice(1))}</strong>
                                    {FREQUENCY_HINTS[freq] && <small>{translateInterface(FREQUENCY_HINTS[freq])}</small>}
                                </span>
                            </label>
                        ))}
                        </div>
                    </fieldset>

                    <fieldset className="digest-fieldset">
                        <legend>{translateInterface("Sections to include")}</legend>
                        <div className="digest-section-options">
                        {query.data.available_sections.map((key) => (
                            <label key={key} className="digest-section-option">
                                <input
                                    type="checkbox"
                                    checked={sections.includes(key)}
                                    data-testid={`digest-pref-section-${key}`}
                                    onChange={() => toggleSection(key)}
                                />
                                <span>{translateInterface(SECTION_LABELS[key] ?? key.replaceAll('_', ' '))}</span>
                            </label>
                        ))}
                        </div>
                    </fieldset>

                    {dirty && <span className="digest-save-status" data-testid="digest-pref-dirty">{translateInterface("Unsaved changes")}</span>}

                    {saveMut.isError && (
                        <div className="digest-save-status digest-save-error" data-testid="digest-pref-save-error" role="alert">
                            {translateInterface("Could not save your preferences. Please try again.")}</div>
                    )}
                    {saveMut.isSuccess && !dirty && (
                        <div className="digest-save-status" data-testid="digest-pref-save-success" role="status">
                            {translateInterface("Saved.")}</div>
                    )}

                    <div className="digest-preferences-actions">
                        <Button
                            type="submit"
                            variant="primary"
                            data-testid="digest-pref-save"
                            disabled={!dirty}
                            busy={saveMut.isPending}
                        >
                            {translateInterface("Save preferences")}
                        </Button>
                    </div>
                </form>
            )}
        </section>
    );
}
