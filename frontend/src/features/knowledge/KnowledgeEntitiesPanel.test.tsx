import { afterEach, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { KnowledgeEntitiesPanel } from './KnowledgeEntitiesPanel';
import { knowledgeApi } from './knowledge.api';

afterEach(() => vi.restoreAllMocks());
it('searches exact identifiers and opens an original source from a supported timeline', async () => {
    const entity = { id: 12, type: 'order', namespace: 'erp', identifier: '00123', project_key: 'eng' };
    vi.spyOn(knowledgeApi, 'entities').mockResolvedValue({ entities: [entity], coverage: { partial: false } });
    vi.spyOn(knowledgeApi, 'context').mockResolvedValue({ entity, summary: ['documented: spedito'], next_cursor: null, coverage: { partial: true, stale_revision: true }, relations: [],
        timeline: [{ id: 1, property: 'event', value: 'spedito', quote: 'Ordine 00123 spedito.', support: 'documented', event_at: null, stale_revision: true, source: { document_id: 23, title: 'Original email', section: 4 }, target: null, relation: null }] });
    const open = vi.fn(); const user = userEvent.setup();
    render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><KnowledgeEntitiesPanel project="eng" onOpenSource={open} /></QueryClientProvider>);
    await user.type(screen.getByLabelText('Identifier or knowledge query'), '00123');
    await user.click(screen.getByRole('button', { name: 'Search entities' }));
    await user.click(await screen.findByRole('button', { name: 'order · erp · 00123' }));
    expect(await screen.findByText('Ordine 00123 spedito.')).toBeVisible();
    expect(screen.getByText(/Needs reprocessing/)).toBeVisible();
    await user.click(screen.getByRole('button', { name: 'Original email · Section 5' }));
    expect(open).toHaveBeenCalledWith(23);
    expect(knowledgeApi.entities).toHaveBeenCalledWith('eng', '00123', '');
});
