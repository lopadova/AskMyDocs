import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { ProjectSelector } from './ProjectSelector';

describe('ProjectSelector', () => {
    it('renders a read-only label (no <select>) when only one project is reachable', () => {
        // Single-tenant / single-project deployments must look exactly like
        // the pre-selector chat — no dropdown to switch into.
        render(<ProjectSelector value="hr-portal" projects={['hr-portal']} onChange={() => {}} />);
        expect(screen.getByTestId('chat-project-label')).toHaveTextContent('hr-portal');
        expect(screen.queryByTestId('chat-project-selector')).toBeNull();
    });

    it('renders a labelled <select> listing every reachable project when >1', () => {
        render(
            <ProjectSelector
                value="hr-portal"
                projects={['hr-portal', 'connector-imap', 'engineering']}
                onChange={() => {}}
            />,
        );
        const select = screen.getByTestId('chat-project-selector');
        // R15: the control announces itself, not via placeholder text.
        expect(select).toHaveAccessibleName('Project scope');
        expect((select as HTMLSelectElement).value).toBe('hr-portal');
        expect(screen.getByRole('option', { name: 'connector-imap' })).toBeInTheDocument();
        expect(screen.getByRole('option', { name: 'engineering' })).toBeInTheDocument();
    });

    it('calls onChange with the chosen project_key when the user switches', async () => {
        const onChange = vi.fn();
        render(
            <ProjectSelector
                value="hr-portal"
                projects={['hr-portal', 'connector-imap']}
                onChange={onChange}
            />,
        );
        await userEvent.selectOptions(
            screen.getByTestId('chat-project-selector'),
            'connector-imap',
        );
        expect(onChange).toHaveBeenCalledTimes(1);
        expect(onChange).toHaveBeenCalledWith('connector-imap');
    });

    it('always represents the effective value even if absent from the reachable list', () => {
        // A conversation can be bound to a project the user no longer has
        // membership in; the select must still report that scope, not blank.
        render(
            <ProjectSelector
                value="legacy-project"
                projects={['hr-portal', 'connector-imap']}
                onChange={() => {}}
            />,
        );
        const select = screen.getByTestId('chat-project-selector') as HTMLSelectElement;
        expect(select.value).toBe('legacy-project');
        expect(screen.getByRole('option', { name: 'legacy-project' })).toBeInTheDocument();
    });

    describe('allowAll', () => {
        it('offers an "All projects" entry and selecting it emits the empty sentinel', async () => {
            const onChange = vi.fn();
            render(
                <ProjectSelector
                    value="hr-portal"
                    projects={['hr-portal', 'connector-imap']}
                    allowAll
                    onChange={onChange}
                />,
            );
            expect(screen.getByRole('option', { name: 'All projects' })).toBeInTheDocument();
            await userEvent.selectOptions(
                screen.getByTestId('chat-project-selector'),
                screen.getByRole('option', { name: 'All projects' }),
            );
            expect(onChange).toHaveBeenCalledWith('');
        });

        it('shows "All projects" as selected when value is the empty string', () => {
            render(
                <ProjectSelector value="" projects={['hr-portal']} allowAll onChange={() => {}} />,
            );
            const select = screen.getByTestId('chat-project-selector') as HTMLSelectElement;
            expect(select.value).toBe('');
            expect(screen.getByRole('option', { name: 'All projects' }).getAttribute('value')).toBe(
                '',
            );
        });

        it('renders a <select> even with a single project (the "All" choice exists)', () => {
            // Without allowAll a single project degrades to a read-only label;
            // with allowAll there are always ≥2 meaningful choices.
            render(
                <ProjectSelector value="hr-portal" projects={['hr-portal']} allowAll onChange={() => {}} />,
            );
            expect(screen.getByTestId('chat-project-selector')).toBeInTheDocument();
            expect(screen.queryByTestId('chat-project-label')).toBeNull();
        });
    });
});
