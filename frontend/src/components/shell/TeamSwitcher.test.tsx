import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { TeamSwitcher } from './TeamSwitcher';
import type { Team } from '../../lib/team-store';

const TEAMS: Team[] = [
    { tenant_id: 'default', name: 'Default', projects: [] },
    {
        tenant_id: 'acme',
        name: 'Acme Corporation',
        projects: [
            { project_key: 'acme-kb', role: 'admin', scope: [] },
            { project_key: 'acme-legal', role: 'viewer', scope: [] },
        ],
    },
];

describe('TeamSwitcher', () => {
    it('shows the active team on the trigger and opens the menu', async () => {
        const user = userEvent.setup();
        render(<TeamSwitcher team={TEAMS[0]} teams={TEAMS} onChange={() => undefined} />);

        const trigger = screen.getByTestId('team-switcher-trigger');
        expect(trigger).toHaveTextContent('Default');
        expect(trigger).toHaveAttribute('aria-expanded', 'false');

        await user.click(trigger);

        expect(trigger).toHaveAttribute('aria-expanded', 'true');
        const menu = screen.getByTestId('team-switcher-menu');
        expect(menu).toHaveAttribute('role', 'menu');
        expect(screen.getByTestId('team-switcher-item-acme')).toHaveTextContent('2 projects');
        expect(screen.getByTestId('team-switcher-item-default')).toHaveAttribute(
            'aria-checked',
            'true',
        );
    });

    it('fires onChange with the picked team and closes the menu', async () => {
        const user = userEvent.setup();
        const onChange = vi.fn();
        render(<TeamSwitcher team={TEAMS[0]} teams={TEAMS} onChange={onChange} />);

        await user.click(screen.getByTestId('team-switcher-trigger'));
        await user.click(screen.getByTestId('team-switcher-item-acme'));

        expect(onChange).toHaveBeenCalledWith(TEAMS[1]);
        expect(screen.queryByTestId('team-switcher-menu')).not.toBeInTheDocument();
    });

    it('closes on Escape and returns focus to the trigger', async () => {
        const user = userEvent.setup();
        render(<TeamSwitcher team={TEAMS[0]} teams={TEAMS} onChange={() => undefined} />);

        const trigger = screen.getByTestId('team-switcher-trigger');
        await user.click(trigger);
        expect(screen.getByTestId('team-switcher-menu')).toBeInTheDocument();

        await user.keyboard('{Escape}');
        expect(screen.queryByTestId('team-switcher-menu')).not.toBeInTheDocument();
        expect(trigger).toHaveFocus();
    });

    it('renders disabled (not hidden) when only one team is offered', async () => {
        const user = userEvent.setup();
        const single = [TEAMS[0]];
        render(<TeamSwitcher team={single[0]} teams={single} onChange={() => undefined} />);

        const trigger = screen.getByTestId('team-switcher-trigger');
        expect(trigger).toBeDisabled();
        expect(trigger).toHaveAttribute('aria-disabled', 'true');
        expect(trigger).toHaveTextContent('Default');

        await user.click(trigger);
        expect(screen.queryByTestId('team-switcher-menu')).not.toBeInTheDocument();
    });
});
