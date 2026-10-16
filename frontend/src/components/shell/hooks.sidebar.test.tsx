import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, expect, it } from 'vitest';
import { useSidebarCollapsed } from './hooks';

function SidebarPreference() {
    const [collapsed, setCollapsed] = useSidebarCollapsed();
    return <button onClick={() => setCollapsed(!collapsed)}>{collapsed ? 'Reopen sidebar' : 'Close sidebar'}</button>;
}

afterEach(() => { cleanup(); localStorage.removeItem('amd-sidebar-collapsed'); });

it('retains the sidebar choice when a new page mounts and allows reopening it', () => {
    const first = render(<SidebarPreference />);
    fireEvent.click(screen.getByRole('button', { name: 'Close sidebar' }));
    first.unmount();
    render(<SidebarPreference />);
    expect(screen.getByRole('button', { name: 'Reopen sidebar' })).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Reopen sidebar' }));
    expect(screen.getByRole('button', { name: 'Close sidebar' })).toBeInTheDocument();
});
