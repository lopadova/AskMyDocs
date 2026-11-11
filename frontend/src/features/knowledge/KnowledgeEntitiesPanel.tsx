import { useState } from 'react';
import { useInfiniteQuery, useQuery } from '@tanstack/react-query';
import { ArrowRight, Search } from 'lucide-react';
import { Button } from '../../components/Button';
import { useUiTranslation } from '../../lib/ui-locale';
import { useTeamStore } from '../../lib/team-store';
import { knowledgeApi, type Entity } from './knowledge.api';
import './knowledge.css';

export function KnowledgeEntitiesPanel({ project, onOpenSource }: { project: string; onOpenSource: (id: number) => void }) {
    const { t } = useUiTranslation();
    const tenant = useTeamStore(s => s.currentTeam);
    const [q, setQ] = useState('');
    const [namespace, setNamespace] = useState('');
    const [search, setSearch] = useState<{ q: string; namespace: string } | null>(null);
    const [selected, setSelected] = useState<Entity | null>(null);
    const results = useQuery({ queryKey: ['knowledge-entities', tenant, project, search], queryFn: () => knowledgeApi.entities(project, search!.q, search!.namespace), enabled: project !== '' && search !== null });
    const context = useInfiniteQuery({ queryKey: ['knowledge-entity', tenant, project, selected?.id], queryFn: ({ pageParam }) => knowledgeApi.context(project, selected!.id, pageParam),
        initialPageParam: undefined as number | undefined, getNextPageParam: page => page.next_cursor ?? undefined, enabled: selected !== null && selected.project_key === project });
    return <section className="knowledge-panel" aria-label={t('Knowledge entities')} data-testid="knowledge-entities-panel">
        <h2>{t('Knowledge entities')}</h2>
        {!project ? <p>{t('Select one project to explore its entities.')}</p> : <>
            <form className="knowledge-actions" onSubmit={e => { e.preventDefault(); setSelected(null); setSearch({ q, namespace }); }}>
                <label>{t('Identifier or knowledge query')}<input value={q} onChange={e => setQ(e.target.value)} /></label>
                <label>{t('Namespace (optional)')}<input value={namespace} onChange={e => setNamespace(e.target.value)} /></label>
                <Button type="submit" variant="secondary" leadingIcon={<Search size={15} />} busy={results.isFetching}>{t('Search entities')}</Button>
            </form>
            {results.isError && <p role="alert">{t('Could not search knowledge entities.')}</p>}
            {results.data && <><ul className="knowledge-entities">{results.data.entities.map(entity => <li key={entity.id}><Button variant="quiet" onClick={() => setSelected(entity)}>{entity.type} · {entity.namespace} · {entity.identifier}</Button></li>)}</ul>
                {results.data.entities.length === 0 && <p>{t('No supported entities found.')}</p>}
                {results.data.coverage.partial && <p role="status">{t('Partial coverage. Refine the identifier or namespace.')}</p>}</>}
            {context.isError && <p role="alert">{t('This knowledge context is no longer available.')}</p>}
            {context.data?.pages.map((page, index) => <article key={index}>
                {index === 0 && <><h3>{page.entity.identifier}</h3><p>{page.coverage.partial ? t('Partial coverage') : t('Processed available text')}{page.coverage.stale_revision ? ' · ' + t('Needs reprocessing for the current revision') : ''}</p><ul>{page.summary.map((s, i) => <li key={i}>{s}</li>)}</ul></>}
                <ol className="knowledge-timeline">{page.timeline.map(event => <li key={event.id}>
                    <p><strong>{event.support}</strong> · {event.event_at ? new Date(event.event_at).toLocaleString() : t('Source date unavailable')}</p>
                    <p>{event.subject && event.subject.id !== page.entity.id && <strong>{event.subject.type} · {event.subject.identifier}: </strong>}{event.property}: {event.value}</p><blockquote>{event.quote}</blockquote>
                    {event.target && <p>{event.relation} · {event.subject && event.subject.id !== page.entity.id ? event.subject.type + ' · ' + event.subject.identifier : event.target.type + ' · ' + event.target.identifier}</p>}
                    <Button variant="quiet" leadingIcon={<ArrowRight size={14} />} onClick={() => onOpenSource(event.source.document_id)}>{event.source.title} · {t('Section')} {event.source.section + 1}</Button>
                </li>)}</ol>
            </article>)}
            {context.hasNextPage && <Button variant="secondary" busy={context.isFetchingNextPage} onClick={() => void context.fetchNextPage()}>{t('Load more history')}</Button>}
        </>}
    </section>;
}
