import { useEffect, useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { digestApi, DIGEST_PREFERENCES_QUERY_KEY } from './digest.api';

/**
 * v8.15/W3.2 — per-user digest preferences: cadence (radio) + section toggles
 * (checkboxes). Reads/writes /api/me/digest-preferences. Follows R11 (stable
 * testids), R15 (every control programmatically labelled), R14 (save errors
 * surface in the DOM), R16/R17 (edits seeded once, dirty drives Save).
 */
export function DigestPreferences(): ReactNode {
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
            style={{ padding: 24, maxWidth: 560 }}
        >
            <h2 style={{ marginTop: 0 }}>Digest preferences</h2>

            {dataState === 'loading' && <p data-testid="digest-pref-loading">Loading…</p>}

            {dataState === 'error' && (
                <div data-testid="digest-pref-error" role="alert">
                    Could not load your preferences.{' '}
                    <button type="button" data-testid="digest-pref-retry" onClick={() => void query.refetch()}>
                        Retry
                    </button>
                </div>
            )}

            {dataState === 'ready' && query.data && frequency !== null && sections !== null && (
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        // Always send an explicit array: an empty array is stored
                        // verbatim as "no sections" (honest opt-out). The BE's
                        // null/omitted case — which this form never sends — instead
                        // resolves to "all sections" on read.
                        saveMut.mutate({ frequency, sections });
                    }}
                >
                    <fieldset style={{ border: 0, padding: 0, margin: '0 0 16px' }}>
                        <legend style={{ fontWeight: 600, marginBottom: 8 }}>How often?</legend>
                        {query.data.available_frequencies.map((freq) => (
                            <label key={freq} style={{ display: 'block', padding: '4px 0' }}>
                                <input
                                    type="radio"
                                    name="digest-frequency"
                                    value={freq}
                                    checked={frequency === freq}
                                    aria-label={`${freq} digest`}
                                    data-testid={`digest-pref-frequency-${freq}`}
                                    onChange={() => setFrequency(freq)}
                                />{' '}
                                {freq === 'off' ? 'Off' : freq.charAt(0).toUpperCase() + freq.slice(1)}
                            </label>
                        ))}
                    </fieldset>

                    <fieldset style={{ border: 0, padding: 0, margin: '0 0 16px' }}>
                        <legend style={{ fontWeight: 600, marginBottom: 8 }}>Sections to include</legend>
                        {query.data.available_sections.map((key) => (
                            <label key={key} style={{ display: 'block', padding: '4px 0' }}>
                                <input
                                    type="checkbox"
                                    checked={sections.includes(key)}
                                    aria-label={`Include ${key.replace('_', ' ')}`}
                                    data-testid={`digest-pref-section-${key}`}
                                    onChange={() => toggleSection(key)}
                                />{' '}
                                {key.replace('_', ' ')}
                            </label>
                        ))}
                    </fieldset>

                    {dirty && <span data-testid="digest-pref-dirty">Unsaved changes</span>}

                    {saveMut.isError && (
                        <div data-testid="digest-pref-save-error" role="alert" style={{ color: 'var(--err)' }}>
                            Could not save your preferences. Please try again.
                        </div>
                    )}
                    {saveMut.isSuccess && !dirty && (
                        <div data-testid="digest-pref-save-success" role="status">
                            Saved.
                        </div>
                    )}

                    <div style={{ marginTop: 12 }}>
                        <button
                            type="submit"
                            data-testid="digest-pref-save"
                            disabled={!dirty || saveMut.isPending}
                        >
                            {saveMut.isPending ? 'Saving…' : 'Save preferences'}
                        </button>
                    </div>
                </form>
            )}
        </section>
    );
}
