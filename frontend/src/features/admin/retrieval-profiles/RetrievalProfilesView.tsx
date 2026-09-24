import { useEffect, useMemo, useState, type FormEvent, type ReactNode } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button } from '../../../components/Button';
import {
    getRetrievalProfiles,
    upsertRetrievalProfile,
    type RetrievalGlossaryEntry,
} from './retrieval-profiles.api';

/**
 * Operator-owned context that constrains the LLM before recursive retrieval.
 * A project without a saved profile is visibly unconfigured and cannot fall
 * back to a corpus-derived or generic semantic search.
 */
export function RetrievalProfilesView(): ReactNode {
    const queryClient = useQueryClient();
    const query = useQuery({
        queryKey: ['admin-retrieval-profiles'],
        queryFn: getRetrievalProfiles,
        staleTime: 15_000,
    });
    const profiles = query.data?.profiles ?? [];
    const [projectKey, setProjectKey] = useState('');
    const selected = useMemo(
        () => profiles.find((profile) => profile.project_key === projectKey) ?? null,
        [profiles, projectKey],
    );
    const [context, setContext] = useState('');
    const [glossaryText, setGlossaryText] = useState('[]');
    const [entitiesText, setEntitiesText] = useState('');
    const [factsText, setFactsText] = useState('');
    const [sourceTypesText, setSourceTypesText] = useState('');
    const [formError, setFormError] = useState<string | null>(null);

    useEffect(() => {
        if (projectKey === '' && profiles[0]) {
            setProjectKey(profiles[0].project_key);
        }
    }, [profiles, projectKey]);

    useEffect(() => {
        setContext(selected?.company_context ?? '');
        setGlossaryText(JSON.stringify(selected?.glossary ?? [], null, 2));
        setEntitiesText((selected?.relevant_entities ?? []).join('\n'));
        setFactsText((selected?.expected_facts ?? []).join('\n'));
        setSourceTypesText((selected?.preferred_source_types ?? []).join('\n'));
    }, [selected]);

    const mutation = useMutation({
        mutationFn: upsertRetrievalProfile,
        onSuccess: () => {
            setFormError(null);
            void queryClient.invalidateQueries({ queryKey: ['admin-retrieval-profiles'] });
        },
        onError: (error: unknown) => {
            setFormError(error instanceof Error ? error.message : 'Unable to save the retrieval profile.');
        },
    });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (projectKey === '') {
            setFormError('Choose a project first.');
            return;
        }
        let glossary: RetrievalGlossaryEntry[];
        try {
            const decoded: unknown = JSON.parse(glossaryText || '[]');
            if (!Array.isArray(decoded)) {
                throw new Error('Glossary must be a JSON list.');
            }
            glossary = decoded as RetrievalGlossaryEntry[];
        } catch (error) {
            setFormError(error instanceof Error ? error.message : 'The glossary JSON is invalid.');
            return;
        }
        mutation.mutate({
            project_key: projectKey,
            company_context: context.trim(),
            glossary,
            relevant_entities: lines(entitiesText),
            expected_facts: lines(factsText),
            preferred_source_types: lines(sourceTypesText),
        });
    };

    return (
        <div data-testid="admin-retrieval-profiles-view" data-state={query.isLoading ? 'loading' : query.isError ? 'error' : 'ready'} style={{ padding: 24, maxWidth: 940 }}>
            <header style={{ marginBottom: 8 }}>
                <h1 style={{ margin: 0, fontSize: 18, color: 'var(--fg-0)' }}>Retrieval profiles</h1>
            </header>
            <p style={{ margin: '0 0 16px', color: 'var(--fg-3)', fontSize: 12, maxWidth: 760 }}>
                Define the company vocabulary used to interpret a request before the KB is searched. This is administrator-authored configuration: documents and emails cannot change it.
            </p>

            {query.isLoading && <p data-testid="admin-retrieval-profiles-loading">Loading…</p>}
            {query.isError && <p data-testid="admin-retrieval-profiles-error" role="alert">Unable to load retrieval profiles.</p>}
            {!query.isLoading && !query.isError && profiles.length === 0 && (
                <p data-testid="admin-retrieval-profiles-empty" style={{ color: 'var(--fg-3)' }}>No KB project is available yet.</p>
            )}
            {profiles.length > 0 && (
                <form onSubmit={submit} aria-busy={mutation.isPending} style={{ display: 'grid', gap: 14 }}>
                    <label style={{ display: 'grid', gap: 5, maxWidth: 380 }}>
                        <span>Project</span>
                        <select data-testid="admin-retrieval-profile-project" value={projectKey} onChange={(event) => setProjectKey(event.target.value)} disabled={mutation.isPending}>
                            {profiles.map((profile) => (
                                <option key={profile.project_key} value={profile.project_key}>
                                    {profile.project_key}{profile.configured ? '' : ' — configuration required'}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label style={{ display: 'grid', gap: 5 }}>
                        <span>Company context</span>
                        <textarea data-testid="admin-retrieval-profile-context" value={context} onChange={(event) => setContext(event.target.value)} minLength={20} required rows={5} disabled={mutation.isPending} />
                    </label>
                    <label style={{ display: 'grid', gap: 5 }}>
                        <span>Glossary (JSON)</span>
                        <textarea data-testid="admin-retrieval-profile-glossary" value={glossaryText} onChange={(event) => setGlossaryText(event.target.value)} rows={6} disabled={mutation.isPending} spellCheck={false} />
                        <small style={{ color: 'var(--fg-3)' }}>[&#123;"term":"ordine","meaning":"pratica commerciale","aliases":["pratica"]&#125;]</small>
                    </label>
                    <TwoColumnField label="Relevant entities" value={entitiesText} onChange={setEntitiesText} testId="admin-retrieval-profile-entities" disabled={mutation.isPending} />
                    <TwoColumnField label="Expected facts" value={factsText} onChange={setFactsText} testId="admin-retrieval-profile-facts" disabled={mutation.isPending} />
                    <TwoColumnField label="Preferred source types" value={sourceTypesText} onChange={setSourceTypesText} testId="admin-retrieval-profile-source-types" disabled={mutation.isPending} hint="One per line, for example markdown, text or email." />
                    {formError && <p data-testid="admin-retrieval-profile-save-error" role="alert" style={{ color: 'var(--err)' }}>{formError}</p>}
                    <div>
                        <Button type="submit" variant="primary" busy={mutation.isPending} data-testid="admin-retrieval-profile-save">Save retrieval profile</Button>
                    </div>
                </form>
            )}
        </div>
    );
}

function TwoColumnField({ label, value, onChange, testId, disabled, hint }: { label: string; value: string; onChange: (value: string) => void; testId: string; disabled: boolean; hint?: string }): ReactNode {
    return (
        <label style={{ display: 'grid', gap: 5 }}>
            <span>{label}</span>
            <textarea data-testid={testId} value={value} onChange={(event) => onChange(event.target.value)} rows={3} disabled={disabled} placeholder="One item per line" />
            {hint && <small style={{ color: 'var(--fg-3)' }}>{hint}</small>}
        </label>
    );
}

function lines(value: string): string[] {
    return value.split('\n').map((line) => line.trim()).filter(Boolean);
}
