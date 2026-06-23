import { describe, it, expect, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import { AccountMetaForm } from './AccountMetaForm';
import type { AdminProject } from '../projects/admin-projects.api';

/*
 * v8.20 — the label + project-binding modal (add-OAuth + edit). R16: each test
 * exercises the behaviour its name promises.
 */

const PROJECTS: AdminProject[] = [
    { id: 1, project_key: 'acme-hr', name: 'Acme HR', description: null, document_count: 0, member_count: 0 },
    { id: 2, project_key: 'eng', name: 'Engineering', description: null, document_count: 0, member_count: 0 },
];

const BASE = {
    connectorKey: 'imap',
    title: 'Add Email account',
    submitLabel: 'Continue',
    projects: PROJECTS,
    onSubmit: vi.fn(),
    onClose: vi.fn(),
};

describe('AccountMetaForm', () => {
    it('renders the label input + a project dropdown derived from the registry (R18)', () => {
        render(<AccountMetaForm {...BASE} onSubmit={vi.fn()} onClose={vi.fn()} />);

        expect(screen.getByTestId('connector-imap-account-form-label')).toBeInTheDocument();
        const select = screen.getByTestId('connector-imap-account-form-project') as HTMLSelectElement;
        // "Global (tenant default)" sentinel + one option per real project.
        expect(select.options).toHaveLength(PROJECTS.length + 1);
        expect(select.options[0].value).toBe('');
        expect(Array.from(select.options).map((o) => o.value)).toContain('acme-hr');
    });

    it('submits the trimmed label + selected projectKey', () => {
        const onSubmit = vi.fn();
        render(<AccountMetaForm {...BASE} onSubmit={onSubmit} onClose={vi.fn()} />);

        fireEvent.change(screen.getByTestId('connector-imap-account-form-label'), {
            target: { value: '  Support  ' },
        });
        fireEvent.change(screen.getByTestId('connector-imap-account-form-project'), {
            target: { value: 'acme-hr' },
        });
        fireEvent.click(screen.getByTestId('connector-imap-account-form-submit'));

        expect(onSubmit).toHaveBeenCalledTimes(1);
        expect(onSubmit.mock.calls[0][0]).toEqual({ label: 'Support', projectKey: 'acme-hr' });
    });

    it('pre-fills label + project for the edit flow', () => {
        render(
            <AccountMetaForm
                {...BASE}
                title="Edit account"
                submitLabel="Save"
                initialLabel="sales"
                initialProjectKey="eng"
                onSubmit={vi.fn()}
                onClose={vi.fn()}
            />,
        );

        expect(screen.getByTestId('connector-imap-account-form-label')).toHaveValue('sales');
        expect(screen.getByTestId('connector-imap-account-form-project')).toHaveValue('eng');
    });

    it('surfaces a top-level error + a per-field label error', () => {
        render(
            <AccountMetaForm
                {...BASE}
                onSubmit={vi.fn()}
                onClose={vi.fn()}
                submitError="Something went wrong."
                fieldErrors={{ label: 'An account with this label already exists for this connector.' }}
            />,
        );

        expect(screen.getByTestId('connector-imap-account-form-error')).toHaveTextContent('Something went wrong.');
        expect(screen.getByTestId('connector-imap-account-form-label-error')).toHaveTextContent(
            'already exists',
        );
    });

    it('exposes data-state for E2E waits and closes on Cancel', () => {
        const onClose = vi.fn();
        const { rerender } = render(
            <AccountMetaForm {...BASE} onSubmit={vi.fn()} onClose={onClose} isSubmitting={false} />,
        );
        expect(screen.getByTestId('connector-imap-account-form')).toHaveAttribute('data-state', 'idle');

        fireEvent.click(screen.getByTestId('connector-imap-account-form-cancel'));
        expect(onClose).toHaveBeenCalledTimes(1);

        rerender(<AccountMetaForm {...BASE} onSubmit={vi.fn()} onClose={onClose} isSubmitting />);
        const form = screen.getByTestId('connector-imap-account-form');
        expect(form).toHaveAttribute('data-state', 'loading');
        expect(form).toHaveAttribute('aria-busy', 'true');
    });

    it('closes on Escape', () => {
        const onClose = vi.fn();
        render(<AccountMetaForm {...BASE} onSubmit={vi.fn()} onClose={onClose} />);
        fireEvent.keyDown(document, { key: 'Escape' });
        expect(onClose).toHaveBeenCalledTimes(1);
    });
});
