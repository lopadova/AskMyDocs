import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { Sidebar } from './Sidebar';
import { USERS } from '../../lib/seed';

describe('Sidebar (unified rail)', () => {
    it('renders every admin section in the single rail — including the ones that used to live only in the AdminShell rail', () => {
        render(<Sidebar active="chat" onNav={() => undefined} user={USERS[0]} projectCount={4} />);
        // Core + formerly-duplicated + formerly-rail-only sections all live here now.
        for (const id of [
            'chat',
            'dashboard',
            'insights',
            'users',
            'roles',
            'kb',
            'collections',
            'synonyms',
            'kb-insights',
            'analysis-settings',
            'content-gaps',
            'tabular-reviews',
            'workflows',
            'ai-act-compliance',
            'compliance-reports',
            'pii-redactor',
            'connectors',
            'flows',
            'eval-harness',
            'mcp-tools',
            'mcp-tokens',
            'logs',
            'maintenance',
        ]) {
            expect(screen.getByTestId(`sidebar-nav-${id}`)).toBeInTheDocument();
        }
    });

    it('fires onNav with the section id when an entry is clicked', async () => {
        const onNav = vi.fn();
        render(<Sidebar active="chat" onNav={onNav} user={USERS[0]} projectCount={4} />);
        await userEvent.click(screen.getByTestId('sidebar-nav-tabular-reviews'));
        expect(onNav).toHaveBeenCalledWith('tabular-reviews');
    });

    it('collapsing a non-active group hides its entries; re-expanding shows them', async () => {
        render(<Sidebar active="chat" onNav={() => undefined} user={USERS[0]} projectCount={4} />);
        // Operations group is not the active group (chat lives in Workspace).
        expect(screen.getByTestId('sidebar-nav-flows')).toBeInTheDocument();

        await userEvent.click(screen.getByTestId('sidebar-group-operations'));
        expect(screen.queryByTestId('sidebar-nav-flows')).not.toBeInTheDocument();

        await userEvent.click(screen.getByTestId('sidebar-group-operations'));
        expect(screen.getByTestId('sidebar-nav-flows')).toBeInTheDocument();
    });

    it('the active section group stays open even if collapsed', async () => {
        // active=flows lives in Operations; toggling Operations must not hide it.
        render(<Sidebar active="flows" onNav={() => undefined} user={USERS[0]} projectCount={4} />);
        await userEvent.click(screen.getByTestId('sidebar-group-operations'));
        expect(screen.getByTestId('sidebar-nav-flows')).toBeInTheDocument();
        expect(screen.getByTestId('sidebar-nav-flows')).toHaveAttribute('aria-current', 'page');
    });

    it('dispatches amd:palette when the search button is clicked', async () => {
        const handler = vi.fn();
        window.addEventListener('amd:palette', handler);
        render(<Sidebar active="chat" onNav={() => undefined} user={USERS[0]} projectCount={4} />);
        await userEvent.click(screen.getByRole('button', { name: /open command palette/i }));
        window.removeEventListener('amd:palette', handler);
        expect(handler).toHaveBeenCalled();
    });
});
