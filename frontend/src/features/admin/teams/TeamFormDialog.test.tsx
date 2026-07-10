import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { TeamFormDialog } from './TeamFormDialog';
import type { AdminTeam } from './admin-teams.api';

const ACME: AdminTeam = {
    slug: 'acme',
    name: 'Acme Corp',
    hash: '822b33ad87c1',
    status: 'active',
    is_default: false,
    can_manage: true,
    project_count: 2,
    member_count: 3,
};

describe('TeamFormDialog', () => {
    it('create mode: auto-slugs the name and submits { name, slug }', async () => {
        const onSubmit = vi.fn();
        render(<TeamFormDialog team={null} onSubmit={onSubmit} onClose={() => {}} />);

        expect(screen.getByTestId('admin-team-form')).toHaveAttribute('data-mode', 'create');

        await userEvent.type(screen.getByTestId('admin-team-form-name'), 'Acme Corp');
        expect(screen.getByTestId('admin-team-form-slug')).toHaveValue('acme-corp');

        await userEvent.click(screen.getByTestId('admin-team-form-submit'));
        expect(onSubmit).toHaveBeenCalledWith({ name: 'Acme Corp', slug: 'acme-corp' });
    });

    it('create mode: an explicit slug edit stops the auto-slug mirroring', async () => {
        const onSubmit = vi.fn();
        render(<TeamFormDialog team={null} onSubmit={onSubmit} onClose={() => {}} />);

        await userEvent.type(screen.getByTestId('admin-team-form-slug'), 'custom');
        await userEvent.type(screen.getByTestId('admin-team-form-name'), 'Acme Corp');

        // Slug stays as typed — the name no longer overwrites it.
        expect(screen.getByTestId('admin-team-form-slug')).toHaveValue('custom');
    });

    it('edit mode: slug is read-only, name is prefilled, submit sends only { name }', async () => {
        const onSubmit = vi.fn();
        render(<TeamFormDialog team={ACME} onSubmit={onSubmit} onClose={() => {}} />);

        expect(screen.getByTestId('admin-team-form')).toHaveAttribute('data-mode', 'edit');
        expect(screen.getByTestId('admin-team-form-slug')).toHaveAttribute('readonly');
        expect(screen.getByTestId('admin-team-form-name')).toHaveValue('Acme Corp');

        await userEvent.clear(screen.getByTestId('admin-team-form-name'));
        await userEvent.type(screen.getByTestId('admin-team-form-name'), 'Acme Corporation');
        await userEvent.click(screen.getByTestId('admin-team-form-submit'));

        expect(onSubmit).toHaveBeenCalledWith({ name: 'Acme Corporation' });
    });

    it('renders a per-field name error and a general error', () => {
        render(
            <TeamFormDialog
                team={null}
                onSubmit={() => {}}
                onClose={() => {}}
                submitError="Could not save."
                fieldErrors={{ name: 'The team name is required.' }}
            />,
        );

        expect(screen.getByTestId('admin-team-form-name-error')).toHaveTextContent('The team name is required.');
        expect(screen.getByTestId('admin-team-form-error')).toHaveTextContent('Could not save.');
    });

    it('Cancel invokes onClose', async () => {
        const onClose = vi.fn();
        render(<TeamFormDialog team={null} onSubmit={() => {}} onClose={onClose} />);

        await userEvent.click(screen.getByTestId('admin-team-form-cancel'));
        expect(onClose).toHaveBeenCalled();
    });
});
