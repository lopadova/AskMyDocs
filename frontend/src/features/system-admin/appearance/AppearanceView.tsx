import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button } from '@/components/Button';
import { EditorialArtwork } from '@/components/editorial/EditorialArtwork';
import { SelectField, SelectOption } from '@/components/ui/select-field';
import { VISUAL_THEMES, applyVisualTheme, getVisualTheme, type VisualThemeId } from '@/lib/visual-theme';
import { APPEARANCE_QUERY_KEY, appearanceApi } from './appearance.api';
import { THEME_COPY, useAppearanceTranslation } from './appearance.messages';
import './appearance.css';

const CATEGORIES = { identity: 'Original identities', worlds: 'Worlds', abstract: 'Abstract themes' } as const;
type ThemeCategory = keyof typeof CATEGORIES;

export function AppearanceView() {
    const t = useAppearanceTranslation();
    const queryClient = useQueryClient();
    const query = useQuery({ queryKey: APPEARANCE_QUERY_KEY, queryFn: appearanceApi.load, refetchOnWindowFocus: false });
    // A draft is deliberately local: inspecting a theme never changes the application.
    const [draft, setDraft] = useState<VisualThemeId | null>(null);
    const [previewMode, setPreviewMode] = useState<'light' | 'dark'>(() => document.documentElement.dataset.theme === 'light' ? 'light' : 'dark');
    const [category, setCategory] = useState<'all' | ThemeCategory>('all');
    const active = query.data?.theme ?? getVisualTheme();
    const selected = draft ?? active;
    const dirty = selected !== active;
    const visibleThemes = VISUAL_THEMES.filter((theme) => category === 'all' || theme.category === category);
    const selectedTheme = VISUAL_THEMES.find((theme) => theme.id === selected)!;
    const categoryLabel = (value: ThemeCategory) => t(CATEGORIES[value]);
    const save = useMutation({
        mutationFn: appearanceApi.save,
        onMutate: () => queryClient.cancelQueries({ queryKey: APPEARANCE_QUERY_KEY }),
        onSuccess: (settings) => {
            queryClient.setQueryData(APPEARANCE_QUERY_KEY, settings);
            setDraft(null);
            applyVisualTheme(settings.theme);
        },
    });
    useEffect(() => {
        const refresh = () => {
            if (document.visibilityState !== 'hidden' && !save.isPending) void query.refetch();
        };
        window.addEventListener('focus', refresh);
        document.addEventListener('visibilitychange', refresh);
        return () => {
            window.removeEventListener('focus', refresh);
            document.removeEventListener('visibilitychange', refresh);
        };
    }, [query.refetch, save.isPending]);

    return <div className="appearance-workspace" data-testid="system-appearance" data-state={query.isLoading ? 'loading' : query.isError ? 'error' : 'ready'} aria-busy={query.isFetching || save.isPending}>
        <header className="appearance-heading">
            <p className="appearance-eyebrow">{t('Visual identity')}</p>
            <h1>{t('Appearance')}</h1>
            <p>{t('One visual identity for every workspace, with coordinated day and night illustrations.')}</p>
        </header>

        {query.isLoading && <p role="status" data-testid="system-appearance-loading">{t('Loading the platform theme…')}</p>}
        {query.isError && <div className="appearance-status is-error" role="alert" data-testid="system-appearance-load-error">
            <p>{t('Could not load the platform theme. Please try again.')}</p>
            <Button size="sm" onClick={() => void query.refetch()}>{t('Retry')}</Button>
        </div>}

        {query.data && !query.isError && <>
            <div className="appearance-toolbar">
                <p className="appearance-active" data-testid="system-appearance-active">{t('Active theme')}<strong>{t(THEME_COPY[active].label)}</strong></p>
                <div className="ui-button-group" role="group" aria-label={t('Preview lighting')}>
                    <Button variant="quiet" size="sm" aria-pressed={previewMode === 'light'} onClick={() => setPreviewMode('light')} data-testid="appearance-preview-light">{t('Day')}</Button>
                    <Button variant="quiet" size="sm" aria-pressed={previewMode === 'dark'} onClick={() => setPreviewMode('dark')} data-testid="appearance-preview-dark">{t('Night')}</Button>
                </div>
            </div>

            <div className="appearance-collection-toolbar">
                <label htmlFor="appearance-category">
                    <span>{t('Theme category')}</span>
                    <SelectField id="appearance-category" data-testid="appearance-category-filter" value={category} onValueChange={(value) => setCategory(value as typeof category)}>
                        <SelectOption value="all">{t('All')} ({VISUAL_THEMES.length})</SelectOption>
                        {Object.entries(CATEGORIES).map(([value, label]) => <SelectOption key={value} value={value}>{t(label)} ({VISUAL_THEMES.filter((theme) => theme.category === value).length})</SelectOption>)}
                    </SelectField>
                </label>
                <p role="status" data-testid="appearance-theme-count">{t('{count} of {total} themes', { count: visibleThemes.length, total: VISUAL_THEMES.length })}</p>
            </div>
            {dirty && !visibleThemes.some((theme) => theme.id === selected) && <div className="appearance-filter-hint" data-testid="appearance-hidden-selection">
                <p>{t('Your selected preview is outside this category.')}</p>
                <Button variant="quiet" size="sm" onClick={() => setCategory(selectedTheme.category)}>{t('Show selected theme')}</Button>
            </div>}

            <fieldset className="appearance-themes" disabled={save.isPending}>
                <legend className="sr-only">{t('Theme collection')}</legend>
                {visibleThemes.map((theme) => <label key={theme.id} className="appearance-theme-card" data-selected={selected === theme.id} data-testid={`appearance-theme-${theme.id}`}>
                    <input type="radio" name="visual-theme" value={theme.id} checked={selected === theme.id} onChange={() => { setDraft(theme.id === active ? null : theme.id); save.reset(); }} aria-label={t(THEME_COPY[theme.id].label)} />
                    <div className="appearance-card-art"><EditorialArtwork artwork="knowledge" designTheme={theme.id} colorMode={previewMode} resolution="small" /></div>
                    <div className="appearance-card-copy">
                        <span className="appearance-category-badge">{categoryLabel(theme.category)}</span>
                        <div className="appearance-card-title"><h2>{t(THEME_COPY[theme.id].label)}</h2>{active === theme.id && <span>{t('Active')}</span>}</div>
                        <p>{t(THEME_COPY[theme.id].description)}</p>
                        <div className="appearance-swatches" aria-hidden="true">{theme.palette[previewMode].map((colour, index) => <span key={index} style={{ backgroundColor: colour }} />)}</div>
                    </div>
                </label>)}
            </fieldset>

            <section className="appearance-workspace-preview" aria-label={t('Workspace preview')}>
                <div className="appearance-preview-caption">
                    <h2>{t('Workspace preview')}</h2>
                    <p>{t('Typography, corners, spacing, colours, and illustration are included in this preview.')}</p>
                </div>
                <div className="design-theme-preview appearance-design-preview" data-design-theme={selected} data-theme={previewMode} data-testid="appearance-workspace-preview" aria-hidden="true">
                    <aside className="appearance-mini-sidebar">
                        <strong>AskMyDocs.</strong>
                        <div className="appearance-mini-nav">
                            <span className="is-active">{t('Home')}</span>
                            <span>{t('Conversations')}</span>
                            <span>{t('Library')}</span>
                        </div>
                    </aside>
                    <div className="appearance-mini-content">
                        <div className="appearance-mini-hero">
                            <div>
                                <p>{t('My workspace')}</p>
                                <h3>{t('Your next chapter starts here.')}</h3>
                                <span className="appearance-mini-action">{t('Ask a question')}</span>
                            </div>
                            <EditorialArtwork artwork="knowledge" designTheme={selected} colorMode={previewMode} resolution="small" />
                        </div>
                        <div className="appearance-mini-metrics">
                            <div><span>{t('Docs authored')}</span><strong>12</strong></div>
                            <div><span>{t('Questions asked')}</span><strong>34</strong></div>
                        </div>
                    </div>
                </div>
            </section>

            <section className="appearance-selection" aria-label={t('Preview')}>
                <div>
                    <p className="appearance-eyebrow">{t('Preview')}</p>
                    <h2>{t(THEME_COPY[selected].label)}</h2>
                    <p>{t('Preview only. Your selection becomes visible to everyone after you save.')}</p>
                </div>
                <div className="appearance-selection-actions">
                    <Button variant="secondary" disabled={!dirty || save.isPending} onClick={() => { setDraft(null); save.reset(); }}>{t('Keep active theme')}</Button>
                    <Button variant="primary" disabled={!dirty} busy={save.isPending} onClick={() => save.mutate(selected)} data-testid="system-appearance-save">{t('Save platform theme')}</Button>
                </div>
            </section>
            <div className="appearance-feedback" aria-live="polite">
                {dirty && !save.isError && <p data-testid="system-appearance-dirty">{t('Unsaved selection')}</p>}
                {save.isError && <p role="alert" className="is-error" data-testid="system-appearance-save-error">{t('Could not save the platform theme. Your selection is still available.')}</p>}
                {save.isSuccess && !dirty && <p role="status" data-testid="system-appearance-saved">{t('Platform theme saved.')}</p>}
            </div>
        </>}
    </div>;
}
