import { SelectField, SelectOption } from '@/components/ui/select-field';
import { useUiTranslation } from "../../../lib/ui-locale";
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
export function RetrievalProfilesView({ initialProjectKey }: { initialProjectKey?: string } = {}): ReactNode {
    const { t: translateInterface } = useUiTranslation();
    const queryClient = useQueryClient();
    const query = useQuery({
        queryKey: ['admin-retrieval-profiles'],
        queryFn: getRetrievalProfiles,
        staleTime: 15_000,
    });
    const profiles = query.data?.profiles ?? [];
    const [projectKey, setProjectKey] = useState(initialProjectKey ?? '');
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
        if (initialProjectKey) setProjectKey(initialProjectKey);
    }, [initialProjectKey]);

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
            void queryClient.invalidateQueries({ queryKey: ['chat-retrieval-profile-status'] });
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
                <h1 style={{ margin: 0, fontSize: 18, color: 'var(--fg-0)' }}>{translateInterface("Retrieval profiles")}</h1>
            </header>
            <section aria-label={translateInterface('What the research profile is for')} style={{ marginBottom: 20, padding: 16, border: '1px solid var(--hairline)', borderRadius: 10, lineHeight: 1.6 }}>
                <h2 style={{ margin: '0 0 8px', fontSize: 15 }}>{translateInterface('Help the chat understand your project')}</h2>
                <p style={{ margin: '0 0 10px' }}>{translateInterface('The research profile explains your business context, terminology and the information people need. The chat uses it to interpret questions and guide searches in this project’s documents. Answers are then checked against the sources it finds.')}</p>
                <p style={{ margin: '0 0 10px' }}>{translateInterface('An administrator must save a profile for each project before document search is available. Profiles are separate for each workspace and project.')}</p>
                <p style={{ margin: 0 }}>{translateInterface('Start with Company context: describe what the project covers and what users usually ask. The other fields are optional and help make searches more precise. After saving, return to the chat and send your question again.')}</p>
            </section>

            {query.isLoading && <p data-testid="admin-retrieval-profiles-loading">{translateInterface("Loading…")}</p>}
            {query.isError && <p data-testid="admin-retrieval-profiles-error" role="alert">{translateInterface("Unable to load retrieval profiles.")}</p>}
            {!query.isLoading && !query.isError && profiles.length === 0 && (
                <p data-testid="admin-retrieval-profiles-empty" style={{ color: 'var(--fg-3)' }}>{translateInterface("No KB project is available yet.")}</p>
            )}
            {profiles.length > 0 && (
                <form onSubmit={submit} aria-busy={mutation.isPending} style={{ display: 'grid', gap: 14 }}>
                    <label style={{ display: 'grid', gap: 5, maxWidth: 380 }}>
                        <span>{translateInterface("Project")}</span>
                        <SelectField data-testid="admin-retrieval-profile-project" value={projectKey} onValueChange={(nextValue) => setProjectKey(nextValue)} disabled={mutation.isPending}>
                            {profiles.map((profile) => (
                                <SelectOption key={profile.project_key} value={profile.project_key}>
                                    {profile.project_key}{profile.configured ? '' : ` — ${translateInterface('Configuration required')}`}
                                </SelectOption>
                            ))}
                        </SelectField>
                    </label>
                    {selected && !selected.configured && <p role="status" style={{ margin: 0, color: 'var(--err)' }}>{translateInterface('This project has no research profile yet. Complete Company context and save to enable document search.')}</p>}
                    <label style={{ display: 'grid', gap: 5 }}>
                        <span>{translateInterface("Company context")}</span>
                        <textarea data-testid="admin-retrieval-profile-context" value={context} onChange={(event) => setContext(event.target.value)} minLength={20} required rows={5} disabled={mutation.isPending} aria-describedby="retrieval-context-help" />
                        <small id="retrieval-context-help">{translateInterface('Required, at least 20 characters. Describe the business, the project’s processes and the questions the documents should help answer. For example: “We manage customer orders and returns. Users ask about delivery dates, order status and refund rules.”')}</small>
                    </label>
                    <label style={{ display: 'grid', gap: 5 }}>
                        <span>{translateInterface("Glossary (JSON)")}</span>
                        <textarea data-testid="admin-retrieval-profile-glossary" value={glossaryText} onChange={(event) => setGlossaryText(event.target.value)} rows={6} disabled={mutation.isPending} spellCheck={false} />
                        <small style={{ color: 'var(--fg-3)' }}>{translateInterface('Optional. Explain acronyms and terms that have a specific meaning in your project. Each JSON entry contains a term, its meaning and any aliases.')}</small>
                        <small style={{ color: 'var(--fg-3)' }}>[&#123;"term":"ordine","meaning":"pratica commerciale","aliases":["pratica"]&#125;]</small>
                    </label>
                    <TwoColumnField label={translateInterface("Relevant entities")} value={entitiesText} onChange={setEntitiesText} testId="admin-retrieval-profile-entities" disabled={mutation.isPending} hint={translateInterface('Optional, one per line. Names or identifiers the chat should recognize, such as products, customers, teams or order codes.')} />
                    <TwoColumnField label={translateInterface("Expected facts")} value={factsText} onChange={setFactsText} testId="admin-retrieval-profile-facts" disabled={mutation.isPending} hint={translateInterface('Optional, one per line. Information users usually need, such as order status, deadlines, responsibilities or return conditions.')} />
                    <TwoColumnField label={translateInterface("Preferred source types")} value={sourceTypesText} onChange={setSourceTypesText} testId="admin-retrieval-profile-source-types" disabled={mutation.isPending} hint={translateInterface('Optional, one per line. Indicate the most useful document formats, for example markdown, pdf or email.')} />
                    {formError && <p data-testid="admin-retrieval-profile-save-error" role="alert" style={{ color: 'var(--err)' }}>{formError}</p>}
                    {mutation.isSuccess && !formError && <p role="status">{translateInterface('Research profile saved. Return to the chat and send your question again.')}</p>}
                    <div>
                        <Button type="submit" variant="primary" busy={mutation.isPending} data-testid="admin-retrieval-profile-save">{translateInterface("Save retrieval profile")}</Button>
                    </div>
                </form>
            )}
        </div>
    );
}

function TwoColumnField({ label, value, onChange, testId, disabled, hint }: { label: string; value: string; onChange: (value: string) => void; testId: string; disabled: boolean; hint?: string }): ReactNode {
    const { t: translateInterface } = useUiTranslation();
    return (
        <label style={{ display: 'grid', gap: 5 }}>
            <span>{label}</span>
            <textarea data-testid={testId} value={value} onChange={(event) => onChange(event.target.value)} rows={3} disabled={disabled} placeholder={translateInterface("One item per line")} />
            {hint && <small style={{ color: 'var(--fg-3)' }}>{hint}</small>}
        </label>
    );
}

function lines(value: string): string[] {
    return value.split('\n').map((line) => line.trim()).filter(Boolean);
}
