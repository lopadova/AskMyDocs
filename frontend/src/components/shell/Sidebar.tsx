import { Icon, type IconName } from '../Icons';
import { Avatar } from './Avatar';
import type { SeedUser } from '../../lib/seed';

export type SidebarSection = 'chat' | 'dashboard' | 'kb' | 'insights' | 'users' | 'logs' | 'maintenance';

export type SidebarProps = {
    active: SidebarSection;
    onNav: (id: SidebarSection) => void;
    collapsed?: boolean;
    user: SeedUser;
    projectCount: number;
};

type NavItem = {
    id: SidebarSection;
    label: string;
    icon: IconName;
    section: 'workspace' | 'admin' | 'ops';
    badge?: number;
};

const NAV_ITEMS: NavItem[] = [
    { id: 'chat', label: 'Chat', icon: 'Chat', section: 'workspace' },
    { id: 'dashboard', label: 'Dashboard', icon: 'Grid', section: 'admin' },
    { id: 'kb', label: 'Knowledge', icon: 'Book', section: 'admin' },
    { id: 'insights', label: 'AI Insights', icon: 'Sparkles', section: 'admin', badge: 5 },
    { id: 'users', label: 'Users & Roles', icon: 'Users', section: 'admin' },
    { id: 'logs', label: 'Logs', icon: 'Activity', section: 'ops' },
    { id: 'maintenance', label: 'Maintenance', icon: 'Wrench', section: 'ops' },
];

const SECTIONS: { id: NavItem['section']; label: string }[] = [
    { id: 'workspace', label: 'Workspace' },
    { id: 'admin', label: 'Administration' },
    { id: 'ops', label: 'Operations' },
];

export function Sidebar({ active, onNav, collapsed = false, user, projectCount }: SidebarProps) {
    return (
        <aside
            aria-label="Primary navigation"
            style={{
                width: collapsed ? 60 : 232,
                minWidth: collapsed ? 60 : 232,
                borderRight: '1px solid var(--hairline)',
                background: 'var(--bg-1)',
                display: 'flex',
                flexDirection: 'column',
                transition: 'width .22s',
            }}
        >
            <div style={{ padding: '14px 14px 10px', display: 'flex', alignItems: 'center', gap: 10 }}>
                <Icon.Logo size={22} />
                {!collapsed && (
                    <div style={{ minWidth: 0 }}>
                        <div style={{ fontSize: 13.5, fontWeight: 600, letterSpacing: '-0.01em' }}>AskMyDocs</div>
                        <div
                            style={{
                                fontSize: 10.5,
                                color: 'var(--fg-3)',
                                fontFamily: 'var(--font-mono)',
                                textTransform: 'uppercase',
                                letterSpacing: '0.04em',
                            }}
                        >
                            Enterprise
                        </div>
                    </div>
                )}
            </div>
            <div style={{ padding: collapsed ? '4px 8px 8px' : '4px 10px 8px' }}>
                <button
                    type="button"
                    className="focus-ring"
                    aria-label="Open command palette"
                    onClick={() => window.dispatchEvent(new CustomEvent('amd:palette'))}
                    style={{
                        width: '100%',
                        display: 'flex',
                        alignItems: 'center',
                        gap: 10,
                        padding: '8px 10px',
                        background: 'var(--bg-2)',
                        border: '1px solid var(--panel-border)',
                        borderRadius: 9,
                        color: 'var(--fg-2)',
                        fontSize: 12,
                        cursor: 'pointer',
                        justifyContent: collapsed ? 'center' : 'flex-start',
                    }}
                >
                    <Icon.Search size={14} />
                    {!collapsed && (
                        <>
                            <span style={{ flex: 1, textAlign: 'left' }}>Search…</span>
                            <span className="kbd">⌘K</span>
                        </>
                    )}
                </button>
            </div>
            <nav style={{ flex: 1, overflow: 'auto', padding: '4px 10px 10px' }}>
                {SECTIONS.map((sec) => (
                    <div key={sec.id} style={{ marginTop: 10 }}>
                        {!collapsed && (
                            <div
                                style={{
                                    fontSize: 10,
                                    color: 'var(--fg-3)',
                                    textTransform: 'uppercase',
                                    letterSpacing: '.08em',
                                    padding: '6px 10px 4px',
                                    fontFamily: 'var(--font-mono)',
                                }}
                            >
                                {sec.label}
                            </div>
                        )}
                        {NAV_ITEMS.filter((i) => i.section === sec.id).map((it) => {
                            const IconCmp = Icon[it.icon];
                            const isActive = active === it.id;
                            return (
                                <button
                                    key={it.id}
                                    type="button"
                                    onClick={() => onNav(it.id)}
                                    className="focus-ring"
                                    aria-current={isActive ? 'page' : undefined}
                                    style={{
                                        width: '100%',
                                        display: 'flex',
                                        alignItems: 'center',
                                        gap: 10,
                                        padding: collapsed ? '8px 10px' : '7px 10px',
                                        background: isActive ? 'var(--bg-3)' : 'transparent',
                                        color: isActive ? 'var(--fg-0)' : 'var(--fg-2)',
                                        border: '1px solid ' + (isActive ? 'var(--panel-border)' : 'transparent'),
                                        borderRadius: 8,
                                        cursor: 'pointer',
                                        fontSize: 13,
                                        fontWeight: isActive ? 500 : 400,
                                        justifyContent: collapsed ? 'center' : 'flex-start',
                                        position: 'relative',
                                        marginBottom: 2,
                                    }}
                                >
                                    {isActive && (
                                        <span
                                            style={{
                                                position: 'absolute',
                                                left: -10,
                                                top: 6,
                                                bottom: 6,
                                                width: 2,
                                                background: 'var(--grad-accent)',
                                                borderRadius: 2,
                                            }}
                                        />
                                    )}
                                    <IconCmp size={15} />
                                    {!collapsed && (
                                        <>
                                            <span style={{ flex: 1, textAlign: 'left' }}>{it.label}</span>
                                            {it.badge && (
                                                <span
                                                    style={{
                                                        fontSize: 10,
                                                        padding: '2px 6px',
                                                        borderRadius: 99,
                                                        background: 'var(--grad-accent-soft)',
                                                        color: 'var(--fg-0)',
                                                        fontWeight: 500,
                                                        border: '1px solid rgba(139,92,246,.3)',
                                                    }}
                                                >
                                                    {it.badge}
                                                </span>
                                            )}
                                        </>
                                    )}
                                </button>
                            );
                        })}
                    </div>
                ))}
            </nav>
            <div
                style={{
                    padding: 10,
                    borderTop: '1px solid var(--hairline)',
                    display: 'flex',
                    alignItems: 'center',
                    gap: 10,
                }}
            >
                <Avatar user={user} size={28} />
                {!collapsed && (
                    <div style={{ minWidth: 0, flex: 1 }}>
                        <div
                            style={{
                                fontSize: 12.5,
                                fontWeight: 500,
                                color: 'var(--fg-0)',
                                whiteSpace: 'nowrap',
                                overflow: 'hidden',
                                textOverflow: 'ellipsis',
                            }}
                        >
                            {user.name}
                        </div>
                        <div style={{ fontSize: 10.5, color: 'var(--fg-3)', fontFamily: 'var(--font-mono)' }}>
                            {user.role} · {projectCount} project{projectCount === 1 ? '' : 's'}
                        </div>
                    </div>
                )}
            </div>
        </aside>
    );
}
