import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { Sidebar } from './Sidebar';
import { USERS } from '../../lib/seed';

const props = { active: 'chat' as const, onNav: vi.fn(), user: { ...USERS[0], role: 'admin' as const }, projectCount: 2, teamHash: 'team-hash' };

describe('Sidebar areas', () => {
    it('shows five everyday destinations while conversations retain one entry', () => {
        render(<Sidebar {...props} />);
        expect(screen.getAllByRole('link')).toHaveLength(5);
        expect(screen.getByRole('link', { name: 'Conversations' })).toHaveAttribute('href', '/app/team-hash/chat');
        expect(screen.queryByTestId('sidebar-nav-sessions')).not.toBeInTheDocument();
        expect(screen.queryByTestId('sidebar-hub-knowledge')).not.toBeInTheDocument();
    });
    it('replaces the long administration list with seven contextual destinations', () => {
        render(<Sidebar {...props} active="kb" area="admin" />);
        expect(screen.getAllByRole('link')).toHaveLength(7);
        expect(screen.getByTestId('sidebar-hub-knowledge')).toHaveAttribute('aria-current', 'page');
        expect(screen.getByTestId('sidebar-hub-knowledge')).toHaveAttribute('href', '/app/team-hash/area/knowledge');
        expect(screen.queryByTestId('sidebar-nav-mcp-tokens')).not.toBeInTheDocument();
    });
    it('hides the system area from an ordinary admin', () => {
        render(<Sidebar {...props} roles={['admin']} permissions={[]} features={{ system_admin: false }} />);
        expect(screen.queryByRole('option', { name: 'System' })).not.toBeInTheDocument();
    });
    it('admits the platform area from the actual capability and permission', () => {
        render(<Sidebar {...props} area="system" roles={['admin']} permissions={['platform.admin']} features={{ system_admin: true }} />);
        expect(screen.getByRole('option', { name: 'System' })).toBeInTheDocument();
        expect(screen.getByTestId('sidebar-hub-system')).toHaveAttribute('href', '/app/team-hash/area/system');
    });
    it('keeps global administration usable without inventing a tenant', () => {
        render(<Sidebar {...props} active="tenant-control" area="system" hasTenants={false} teamHash={null} roles={['system-admin']} permissions={['platform.admin']} features={{ system_admin: true }} />);
        expect(screen.getByTestId('sidebar-hub-system')).toHaveAttribute('href', '/app/system/tenants');
        expect(screen.queryByTestId('sidebar-nav-chat')).not.toBeInTheDocument();
    });
    it('does not expose unrelated admin hubs to viewers', () => {
        render(<Sidebar {...props} user={{ ...USERS[0], role: 'viewer' }} roles={['viewer']} area="admin" />);
        expect(screen.getAllByRole('link')).toHaveLength(1);
        expect(screen.getByTestId('sidebar-hub-automation')).toBeInTheDocument();
    });
    it('uses the actual roles rather than the decorative primary role', () => {
        render(<Sidebar {...props} user={{ ...USERS[0], role: 'viewer' }} roles={['viewer', 'admin']} area="admin" />);
        expect(screen.getAllByRole('link')).toHaveLength(7);
    });
    it('navigates a destination and changes area with the provided callbacks', async () => {
        const user = userEvent.setup(); const onNav = vi.fn(); const onAreaChange = vi.fn();
        render(<Sidebar {...props} onNav={onNav} onAreaChange={onAreaChange} />);
        await user.click(screen.getByRole('link', { name: 'Library' }));
        expect(onNav).toHaveBeenCalledWith('kb-browse');
        await user.selectOptions(screen.getByRole('combobox', { name: 'Workspace area' }), 'admin');
        expect(onAreaChange).toHaveBeenCalledWith('admin');
    });
    it('opens the real navigation search', async () => {
        const user = userEvent.setup(); const handler = vi.fn(); window.addEventListener('amd:palette', handler);
        render(<Sidebar {...props} />);
        await user.click(screen.getByRole('button', { name: 'Open command palette' }));
        window.removeEventListener('amd:palette', handler);
        expect(handler).toHaveBeenCalledOnce();
    });
});
