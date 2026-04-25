import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { Sidebar } from './Sidebar';
import { USERS } from '../../lib/seed';

describe('Sidebar', () => {
    it('renders all 7 nav items', () => {
        render(<Sidebar active="chat" onNav={() => undefined} user={USERS[0]} projectCount={4} />);
        for (const label of ['Chat', 'Dashboard', 'Knowledge', 'AI Insights', 'Users & Roles', 'Logs', 'Maintenance']) {
            expect(screen.getByRole('button', { name: new RegExp(label, 'i') })).toBeInTheDocument();
        }
    });

    it('fires onNav when a section is clicked', async () => {
        const onNav = vi.fn();
        render(<Sidebar active="chat" onNav={onNav} user={USERS[0]} projectCount={4} />);
        await userEvent.click(screen.getByRole('button', { name: /dashboard/i }));
        expect(onNav).toHaveBeenCalledWith('dashboard');
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
