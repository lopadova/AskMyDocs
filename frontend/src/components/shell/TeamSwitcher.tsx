import { useEffect, useId, useRef, useState } from 'react';
import { Icon } from '../Icons';
import { ProjectDot } from './Avatar';
import type { Team } from '../../lib/team-store';

export type TeamSwitcherProps = {
    team: Team;
    teams: Team[];
    onChange: (t: Team) => void;
};

/*
 * Single-select TEAM (= tenant) switcher in the topbar. Changing team
 * re-scopes the whole SPA: lib/api.ts stamps `X-Tenant-Id` from the
 * team store and AppShell remounts the route outlet.
 *
 * A11y pattern inherited from the retired ProjectSwitcher (Copilot
 * PR #33 fixes): ARIA `menu` + `menuitemradio`, Escape closes AND
 * returns focus to the trigger, aria-controls wires trigger → menu.
 *
 * With a single team the trigger renders DISABLED rather than hidden:
 * the user still sees which team they are in, the layout stays stable,
 * and E2E selectors stay deterministic.
 */

// Deterministic accent per team — teams (unlike the old seed projects)
// carry no colour of their own.
const TEAM_COLORS = ['#8b5cf6', '#22d3ee', '#f97316', '#a3e635', '#f43f5e', '#eab308'];

function teamColor(teams: Team[], tenantId: string): string {
    const idx = Math.max(
        0,
        teams.findIndex((t) => t.tenant_id === tenantId),
    );
    return TEAM_COLORS[idx % TEAM_COLORS.length];
}

export function TeamSwitcher({ team, teams, onChange }: TeamSwitcherProps) {
    const [open, setOpen] = useState(false);
    const ref = useRef<HTMLDivElement | null>(null);
    const triggerRef = useRef<HTMLButtonElement | null>(null);
    const reactId = useId();
    const menuId = `team-switcher-menu-${reactId}`;
    const singleTeam = teams.length <= 1;

    const close = (returnFocus = false) => {
        setOpen(false);
        if (returnFocus) {
            triggerRef.current?.focus();
        }
    };

    useEffect(() => {
        if (!open) return;
        const onMouseDown = (e: MouseEvent) => {
            if (!ref.current?.contains(e.target as Node)) {
                close();
            }
        };
        const onKeyDown = (e: KeyboardEvent) => {
            if (e.key === 'Escape') {
                e.preventDefault();
                close(true);
            }
        };
        document.addEventListener('mousedown', onMouseDown);
        document.addEventListener('keydown', onKeyDown);
        return () => {
            document.removeEventListener('mousedown', onMouseDown);
            document.removeEventListener('keydown', onKeyDown);
        };
    }, [open]);

    return (
        <div ref={ref} style={{ position: 'relative' }}>
            <button
                ref={triggerRef}
                type="button"
                className="focus-ring"
                data-testid="team-switcher-trigger"
                onClick={() => setOpen((o) => !o)}
                disabled={singleTeam}
                aria-disabled={singleTeam}
                aria-haspopup="menu"
                aria-expanded={open}
                aria-controls={open ? menuId : undefined}
                aria-label={`Active team: ${team.name}`}
                style={{
                    display: 'flex',
                    alignItems: 'center',
                    gap: 8,
                    padding: '6px 10px',
                    background: 'var(--bg-2)',
                    border: '1px solid var(--panel-border)',
                    borderRadius: 9,
                    cursor: singleTeam ? 'default' : 'pointer',
                    color: 'var(--fg-0)',
                    fontSize: 12.5,
                    fontWeight: 500,
                    opacity: singleTeam ? 0.75 : 1,
                }}
            >
                <ProjectDot color={teamColor(teams, team.tenant_id)} size={8} />
                {team.name}
                {!singleTeam && <Icon.ChevronDown size={13} style={{ color: 'var(--fg-3)' }} />}
            </button>
            {open && (
                <div
                    id={menuId}
                    className="panel popin"
                    role="menu"
                    aria-label="Switch team"
                    data-testid="team-switcher-menu"
                    style={{
                        position: 'absolute',
                        top: 'calc(100% + 6px)',
                        left: 0,
                        minWidth: 260,
                        padding: 6,
                        zIndex: 100,
                        boxShadow: 'var(--shadow-lg)',
                    }}
                >
                    <div
                        style={{
                            padding: '4px 8px 6px',
                            fontSize: 10.5,
                            color: 'var(--fg-3)',
                            fontFamily: 'var(--font-mono)',
                            textTransform: 'uppercase',
                            letterSpacing: '.08em',
                        }}
                    >
                        Switch team
                    </div>
                    {teams.map((t) => (
                        <button
                            key={t.tenant_id}
                            type="button"
                            role="menuitemradio"
                            aria-checked={team.tenant_id === t.tenant_id}
                            data-testid={`team-switcher-item-${t.tenant_id}`}
                            onClick={() => {
                                onChange(t);
                                close(true);
                            }}
                            style={{
                                width: '100%',
                                display: 'flex',
                                alignItems: 'center',
                                gap: 10,
                                padding: '8px 10px',
                                background: team.tenant_id === t.tenant_id ? 'var(--bg-3)' : 'transparent',
                                border: 0,
                                borderRadius: 7,
                                cursor: 'pointer',
                                color: 'var(--fg-0)',
                                fontSize: 13,
                                textAlign: 'left',
                            }}
                        >
                            <ProjectDot color={teamColor(teams, t.tenant_id)} size={10} />
                            <span style={{ flex: 1 }}>{t.name}</span>
                            <span className="mono" style={{ fontSize: 10.5, color: 'var(--fg-3)' }}>
                                {t.projects.length} projects
                            </span>
                            {team.tenant_id === t.tenant_id && <Icon.Check size={13} />}
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}
