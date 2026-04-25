import { describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { ProjectSwitcher } from './ProjectSwitcher';
import type { Project } from '../../lib/seed';

const PROJECTS: Project[] = [
    { key: 'hr-portal', label: 'HR Portal', color: '#8b5cf6', docs: 100, members: 5 },
    { key: 'legal-vault', label: 'Legal Vault', color: '#22d3ee', docs: 50, members: 3 },
    { key: 'finance-ops', label: 'Finance Ops', color: '#f97316', docs: 25, members: 2 },
];

const expectMenuClosed = async () => {
    await waitFor(() => {
        expect(
            screen.queryByRole('menu', { name: /switch project/i }),
        ).not.toBeInTheDocument();
    });
};

describe('ProjectSwitcher', () => {
    it('renders the current project label on the trigger', () => {
        render(<ProjectSwitcher project={PROJECTS[0]} projects={PROJECTS} onChange={vi.fn()} />);
        expect(screen.getByRole('button', { name: /HR Portal/i })).toBeInTheDocument();
    });

    it('opens the menu on click and shows every project', async () => {
        const user = userEvent.setup();
        render(<ProjectSwitcher project={PROJECTS[0]} projects={PROJECTS} onChange={vi.fn()} />);
        await user.click(screen.getAllByRole('button', { name: /HR Portal/i })[0]);
        const menu = await screen.findByRole('menu', { name: /switch project/i });
        expect(menu).toBeInTheDocument();
        expect(screen.getByRole('menuitemradio', { name: /HR Portal/ })).toBeInTheDocument();
        expect(screen.getByRole('menuitemradio', { name: /Legal Vault/ })).toBeInTheDocument();
        expect(screen.getByRole('menuitemradio', { name: /Finance Ops/ })).toBeInTheDocument();
    });

    it('calls onChange and closes when a project is selected', async () => {
        const user = userEvent.setup();
        const onChange = vi.fn();
        render(<ProjectSwitcher project={PROJECTS[0]} projects={PROJECTS} onChange={onChange} />);
        await user.click(screen.getAllByRole('button', { name: /HR Portal/i })[0]);
        await user.click(screen.getByRole('menuitemradio', { name: /Legal Vault/ }));
        await expectMenuClosed();
        expect(onChange).toHaveBeenCalledTimes(1);
        expect(onChange).toHaveBeenCalledWith(PROJECTS[1]);
    });

    it('closes on Escape and returns focus to the trigger', async () => {
        const user = userEvent.setup();
        const onChange = vi.fn();
        render(<ProjectSwitcher project={PROJECTS[0]} projects={PROJECTS} onChange={onChange} />);
        const trigger = screen.getAllByRole('button', { name: /HR Portal/i })[0];
        await user.click(trigger);
        expect(await screen.findByRole('menu', { name: /switch project/i })).toBeInTheDocument();
        await user.keyboard('{Escape}');
        await expectMenuClosed();
        await waitFor(() => expect(trigger).toHaveFocus());
        expect(onChange).not.toHaveBeenCalled();
    });

    it('closes on outside mousedown without calling onChange', async () => {
        const user = userEvent.setup();
        const onChange = vi.fn();
        render(
            <div>
                <ProjectSwitcher project={PROJECTS[0]} projects={PROJECTS} onChange={onChange} />
                <button data-testid="outside">outside</button>
            </div>,
        );
        await user.click(screen.getAllByRole('button', { name: /HR Portal/i })[0]);
        expect(await screen.findByRole('menu', { name: /switch project/i })).toBeInTheDocument();
        await user.click(screen.getByTestId('outside'));
        await expectMenuClosed();
        expect(onChange).not.toHaveBeenCalled();
    });
});
