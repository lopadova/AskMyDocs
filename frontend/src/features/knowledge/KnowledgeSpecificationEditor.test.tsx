import { beforeEach, afterEach, describe, it, expect, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { chooseSelectOption } from '../../test-utils/select';
import { KnowledgeSpecificationEditor } from './KnowledgeSpecificationEditor';
import { knowledgeApi, type Blocks, type SpecificationData } from './knowledge.api';

const blocks: Blocks = { types: { entities: [] }, identity: { namespaces: [] }, admission: { rules: [], calibration: null }, contributions: { destinations: [] } };
let data: SpecificationData;
beforeEach(() => {
    data = { scope: null, versions: [], effective: null, template: blocks };
    vi.spyOn(knowledgeApi, 'specifications').mockImplementation(async () => data);
    vi.spyOn(knowledgeApi, 'runs').mockResolvedValue([]);
    vi.spyOn(knowledgeApi, 'draft').mockImplementation(async (_project, description, value) => {
        const revision = { id: 2, description, blocks: value, activated_at: null };
        data = { ...data, versions: [revision] }; return revision;
    });
    vi.spyOn(knowledgeApi, 'activate').mockResolvedValue({} as never);
});
afterEach(() => vi.restoreAllMocks());
function mount() { render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><KnowledgeSpecificationEditor projects={['eng']} /></QueryClientProvider>); }

describe('Knowledge specification workflow', () => {
    it('saves a revision, then enables activation and activates the saved revision', async () => {
        mount(); const user = userEvent.setup();
        await screen.findByLabelText('types');
        await user.type(screen.getByLabelText('Description in Italian'), 'Ordini e assistenza');
        expect(screen.getByRole('button', { name: 'Activate revision' })).toBeDisabled();
        await user.click(screen.getByRole('button', { name: 'Save new revision' }));
        await waitFor(() => expect(screen.getByRole('button', { name: 'Activate revision' })).toBeEnabled());
        await user.click(screen.getByRole('button', { name: 'Activate revision' }));
        expect(knowledgeApi.activate).toHaveBeenCalledWith('*', 2);
    });
    it('rejects malformed JSON locally without creating a revision', async () => {
        mount(); const user = userEvent.setup();
        const types = await screen.findByLabelText('types');
        await user.clear(types); await user.type(types, 'invalid JSON');
        await user.click(screen.getByRole('button', { name: 'Save new revision' }));
        expect(await screen.findByRole('alert')).toBeVisible();
        expect(knowledgeApi.draft).not.toHaveBeenCalled();
    });
    it('inherits project blocks and does not reprocess on activation', async () => {
        mount();
        await screen.findByLabelText('types');
        await chooseSelectOption(screen.getByRole('combobox', { name: 'Specification scope' }), 'eng');
        await waitFor(() => expect(screen.getByLabelText('types')).toBeDisabled());
        expect(screen.getAllByRole('checkbox', { name: 'Inherit company block' })).toHaveLength(4);
        expect(screen.getByRole('button', { name: 'Reprocess selected sources' })).toBeDisabled();
    });
    it('edits types through structured controls and preserves the other blocks', async () => {
        mount(); const user = userEvent.setup();
        await screen.findByLabelText('types');
        await user.click(screen.getByRole('button', { name: 'Add · Entity types' }));
        await user.type(screen.getByLabelText('Type key'), 'order');
        await user.type(screen.getByLabelText('Entity types 1 · Properties'), 'event, outcome');
        await user.tab();
        await user.click(screen.getByRole('button', { name: 'Save new revision' }));
        await waitFor(() => expect(knowledgeApi.draft).toHaveBeenCalledWith('*', '', { ...blocks, types: { entities: [{ key: 'order', properties: ['event', 'outcome'] }] } }));
    });

});
