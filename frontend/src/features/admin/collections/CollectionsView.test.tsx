import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, expect, it, vi } from 'vitest';
import { CollectionsView } from './CollectionsView';
import * as api from './collections.api';

vi.mock('./collections.api', () => ({ listCollections: vi.fn(), createCollection: vi.fn(), listCollectionMembers: vi.fn(), previewCollection: vi.fn() }));
beforeEach(() => { vi.clearAllMocks(); vi.mocked(api.listCollections).mockResolvedValue([]); });
it('explains the empty collection and prevents submitting incomplete fields', async () => {
    render(<CollectionsView />);
    expect(await screen.findByText('Your first collection')).toBeInTheDocument();
    expect(screen.getByTestId('admin-collections-create')).toBeDisabled();
    expect(screen.queryByTestId('admin-collections-delete')).not.toBeInTheDocument();
    const user = userEvent.setup();
    await user.type(screen.getByLabelText('Name'), 'Shared policies');
    await user.type(screen.getByLabelText('Identifier'), 'shared-policies');
    expect(screen.getByTestId('admin-collections-create')).toBeEnabled();
    await user.clear(screen.getByLabelText('Identifier'));
    await user.type(screen.getByLabelText('Identifier'), 'shared_policies');
    expect(screen.getByTestId('admin-collections-create')).toBeDisabled();
});
it('retains entered fields and offers recovery after a failed save', async () => {
    vi.mocked(api.createCollection).mockRejectedValue(new Error('offline'));
    render(<CollectionsView />); await screen.findByText('Your first collection');
    const user = userEvent.setup();
    await user.type(screen.getByLabelText('Name'), 'Shared policies');
    await user.type(screen.getByLabelText('Identifier'), 'shared-policies');
    await user.click(screen.getByTestId('admin-collections-create'));
    expect(await screen.findByRole('alert')).toHaveTextContent('The change could not be saved.');
    expect(screen.getByLabelText('Name')).toHaveValue('Shared policies');
    expect(screen.getByTestId('admin-collections-create')).toBeEnabled();
});
