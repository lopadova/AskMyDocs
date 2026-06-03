import { useState } from 'react';
import { Icon } from '../Icons';
import { Avatar } from './Avatar';
import { NAV_GROUPS, type SidebarSection } from './nav-config';
import type { SeedUser } from '../../lib/seed';

export type { SidebarSection } from './nav-config';

export type SidebarProps = {
    active: SidebarSection;
    onNav: (id: SidebarSection) => void;
    collapsed?: boolean;
    user: SeedUser;
    projectCount: number;
};

/*
 * The ONE host navigation. Every admin section lives here, grouped and
 * collapsible — the old secondary `AdminShell` rail is gone, so nothing
 * appears twice. Groups default to expanded; the group that owns the active
 * section is always forced open so the current page is never hidden.
 */
export function Sidebar({ active, onNav, collapsed = false, user, projectCount }: SidebarProps) {
    const activeGroupId = NAV_GROUPS.find((g) => g.items.some((i) => i.id === active))?.id;
    const [collapsedGroups, setCollapsedGroups] = useState<Record<string, boolean>>({});

    // The active group is force-open (so the current page is never hidden), so
    // toggling its collapse state would be a no-op now but silently collapse it
    // the moment the user navigates away — a hidden side effect. Ignore the
    // toggle for the active group entirely.
    const toggleGroup = (id: string) => {
        if (id === activeGroupId) {
            return;
        }
        setCollapsedGroups((prev) => ({ ...prev, [id]: !prev[id] }));
    };

    return (
        <aside
            aria-label="Primary navigation"
            data-testid="sidebar-nav"
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
                    data-testid="sidebar-command-palette"
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
                {NAV_GROUPS.map((group) => {
                    // The active section's group is always shown; user toggle wins
                    // otherwise. In icon-collapsed mode there are no group headers
                    // to re-expand with, so force every group open — a group can
                    // never get stuck hidden with no way to reveal it.
                    const groupOpen = collapsed || group.id === activeGroupId || !collapsedGroups[group.id];
                    return (
                        <div key={group.id} style={{ marginTop: 10 }}>
                            {!collapsed && (
                                <button
                                    type="button"
                                    data-testid={`sidebar-group-${group.id}`}
                                    aria-expanded={groupOpen}
                                    onClick={() => toggleGroup(group.id)}
                                    className="focus-ring"
                                    style={{
                                        width: '100%',
                                        display: 'flex',
                                        alignItems: 'center',
                                        gap: 6,
                                        background: 'transparent',
                                        border: 0,
                                        cursor: 'pointer',
                                        fontSize: 10,
                                        color: 'var(--fg-3)',
                                        textTransform: 'uppercase',
                                        letterSpacing: '.08em',
                                        padding: '6px 10px 4px',
                                        fontFamily: 'var(--font-mono)',
                                    }}
                                >
                                    <Icon.ChevronDown
                                        size={11}
                                        style={{
                                            transition: 'transform .15s',
                                            transform: groupOpen ? 'none' : 'rotate(-90deg)',
                                        }}
                                    />
                                    <span style={{ flex: 1, textAlign: 'left' }}>{group.label}</span>
                                </button>
                            )}
                            {groupOpen &&
                                group.items.map((it) => {
                                    const IconCmp = Icon[it.icon];
                                    const isActive = active === it.id;
                                    return (
                                        <button
                                            key={it.id}
                                            type="button"
                                            data-testid={`sidebar-nav-${it.id}`}
                                            onClick={() => onNav(it.id)}
                                            className="focus-ring"
                                            aria-label={it.label}
                                            aria-current={isActive ? 'page' : undefined}
                                            title={collapsed ? it.label : undefined}
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
                                            {!collapsed && <span style={{ flex: 1, textAlign: 'left' }}>{it.label}</span>}
                                        </button>
                                    );
                                })}
                        </div>
                    );
                })}
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
