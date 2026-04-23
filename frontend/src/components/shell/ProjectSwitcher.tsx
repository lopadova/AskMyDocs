import { useEffect, useRef, useState } from 'react';
import { Icon } from '../Icons';
import { ProjectDot } from './Avatar';
import type { Project } from '../../lib/seed';

export type ProjectSwitcherProps = {
    project: Project;
    projects: Project[];
    onChange: (p: Project) => void;
};

export function ProjectSwitcher({ project, projects, onChange }: ProjectSwitcherProps) {
    const [open, setOpen] = useState(false);
    const ref = useRef<HTMLDivElement | null>(null);

    useEffect(() => {
        const off = (e: MouseEvent) => {
            if (!ref.current?.contains(e.target as Node)) {
                setOpen(false);
            }
        };
        document.addEventListener('mousedown', off);
        return () => document.removeEventListener('mousedown', off);
    }, []);

    return (
        <div ref={ref} style={{ position: 'relative' }}>
            <button
                type="button"
                className="focus-ring"
                onClick={() => setOpen(!open)}
                aria-haspopup="listbox"
                aria-expanded={open}
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
                    className="panel popin"
                    role="listbox"
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
                            role="option"
                            aria-selected={project.key === p.key}
                            onClick={() => {
                                onChange(p);
                                setOpen(false);
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
