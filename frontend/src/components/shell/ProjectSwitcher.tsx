import { useEffect, useId, useRef, useState } from 'react';
import { Icon } from '../Icons';
import { ProjectDot } from './Avatar';
import type { Project } from '../../lib/seed';

export type ProjectSwitcherProps = {
    project: Project;
    projects: Project[];
    onChange: (p: Project) => void;
};

/*
 * Single-select project switcher.
 *
 * Copilot PR #33 a11y fixes:
 * - Switched from `role="listbox"` (which would have required full
 *   roving-tabindex / aria-activedescendant keyboard navigation) to
 *   the simpler ARIA `menu` + `menuitemradio` pattern that browsers
 *   already give natural keyboard semantics to (Tab cycles items,
 *   Enter/Space activates, native button focus ring).
 * - Escape now closes the popover AND returns focus to the trigger
 *   button (was: only mousedown-outside closed; keyboard users were
 *   stuck).
 * - aria-controls + per-instance menu id so screen readers can
 *   announce the relationship.
 */
export function ProjectSwitcher({ project, projects, onChange }: ProjectSwitcherProps) {
    const [open, setOpen] = useState(false);
    const ref = useRef<HTMLDivElement | null>(null);
    const triggerRef = useRef<HTMLButtonElement | null>(null);
    const reactId = useId();
    const menuId = `project-switcher-menu-${reactId}`;

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
                onClick={() => setOpen((o) => !o)}
                aria-haspopup="menu"
                aria-expanded={open}
                aria-controls={open ? menuId : undefined}
                style={{
                    display: 'flex',
                    alignItems: 'center',
                    gap: 8,
                    padding: '6px 10px',
                    background: 'var(--bg-2)',
                    border: '1px solid var(--panel-border)',
                    borderRadius: 9,
                    cursor: 'pointer',
                    color: 'var(--fg-0)',
                    fontSize: 12.5,
                    fontWeight: 500,
                }}
            >
                <ProjectDot color={project.color} size={8} />
                {project.label}
                <Icon.ChevronDown size={13} style={{ color: 'var(--fg-3)' }} />
            </button>
            {open && (
                <div
                    id={menuId}
                    className="panel popin"
                    role="menu"
                    aria-label="Switch project"
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
                        Switch project
                    </div>
                    {projects.map((p) => (
                        <button
                            key={p.key}
                            type="button"
                            role="menuitemradio"
                            aria-checked={project.key === p.key}
                            onClick={() => {
                                onChange(p);
                                close(true);
                            }}
                            style={{
                                width: '100%',
                                display: 'flex',
                                alignItems: 'center',
                                gap: 10,
                                padding: '8px 10px',
                                background: project.key === p.key ? 'var(--bg-3)' : 'transparent',
                                border: 0,
                                borderRadius: 7,
                                cursor: 'pointer',
                                color: 'var(--fg-0)',
                                fontSize: 13,
                                textAlign: 'left',
                            }}
                        >
                            <ProjectDot color={p.color} size={10} />
                            <span style={{ flex: 1 }}>{p.label}</span>
                            <span className="mono" style={{ fontSize: 10.5, color: 'var(--fg-3)' }}>
                                {p.docs} docs
                            </span>
                            {project.key === p.key && <Icon.Check size={13} />}
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}
