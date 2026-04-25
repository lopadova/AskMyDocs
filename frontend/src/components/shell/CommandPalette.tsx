import { useEffect, useRef, useState } from 'react';
import { Icon, type IconName } from '../Icons';

type PaletteItem = {
    icon: IconName;
    label: string;
    group: string;
    kbd?: string;
};

const ITEMS: PaletteItem[] = [
    { icon: 'Chat', label: 'New chat', group: 'Actions', kbd: 'N' },
    { icon: 'Search', label: 'Search knowledge base', group: 'Actions', kbd: '/' },
    { icon: 'Folder', label: 'Open KB tree', group: 'Navigate' },
    { icon: 'Grid', label: 'Admin dashboard', group: 'Navigate' },
    { icon: 'Sparkles', label: 'AI Insights (5 new)', group: 'Navigate' },
    { icon: 'Users', label: 'Manage users', group: 'Admin' },
    { icon: 'Wrench', label: 'Run kb:validate-canonical', group: 'Commands' },
    { icon: 'Wrench', label: 'Run kb:rebuild-graph', group: 'Commands' },
    { icon: 'File', label: 'remote-work-policy.md', group: 'Documents' },
    { icon: 'File', label: 'incident-response.md', group: 'Documents' },
    { icon: 'File', label: 'data-protection.md', group: 'Documents' },
];

export function CommandPalette() {
    const [open, setOpen] = useState(false);
    const [q, setQ] = useState('');
    const inputRef = useRef<HTMLInputElement | null>(null);

    useEffect(() => {
        const trigger = () => setOpen((o) => !o);
        const key = (e: KeyboardEvent) => {
            if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
                e.preventDefault();
                trigger();
                return;
            }
            if (e.key === 'Escape') {
                setOpen(false);
            }
        };
        window.addEventListener('amd:palette', trigger as EventListener);
        window.addEventListener('keydown', key);
        return () => {
            window.removeEventListener('amd:palette', trigger as EventListener);
            window.removeEventListener('keydown', key);
        };
    }, []);

    useEffect(() => {
        if (!open) {
            setQ('');
            return;
        }
        const handle = window.setTimeout(() => inputRef.current?.focus(), 20);
        return () => window.clearTimeout(handle);
    }, [open]);

    if (!open) {
        return null;
    }

    const needle = q.toLowerCase();
    const filtered = needle ? ITEMS.filter((i) => i.label.toLowerCase().includes(needle)) : ITEMS;
    const grouped = filtered.reduce<Record<string, PaletteItem[]>>((acc, it) => {
        (acc[it.group] = acc[it.group] || []).push(it);
        return acc;
    }, {});

    return (
        <div
            role="dialog"
            aria-modal="true"
            aria-label="Command palette"
            onClick={() => setOpen(false)}
            style={{
                position: 'fixed',
                inset: 0,
                zIndex: 1000,
                background: 'rgba(0,0,0,0.55)',
                backdropFilter: 'blur(8px)',
                display: 'flex',
                alignItems: 'flex-start',
                justifyContent: 'center',
                paddingTop: '12vh',
            }}
        >
            <div
                onClick={(e) => e.stopPropagation()}
                className="panel popin"
                style={{
                    width: 620,
                    maxWidth: '92vw',
                    background: 'var(--panel-solid)',
                    boxShadow: 'var(--shadow-lg)',
                    border: '1px solid var(--panel-border-strong)',
                    overflow: 'hidden',
                }}
            >
                <div
                    style={{
                        display: 'flex',
                        alignItems: 'center',
                        gap: 10,
                        padding: '14px 16px',
                        borderBottom: '1px solid var(--hairline)',
                    }}
                >
                    <Icon.Search size={16} style={{ color: 'var(--fg-3)' }} />
                    <input
                        ref={inputRef}
                        value={q}
                        onChange={(e) => setQ(e.target.value)}
                        placeholder="Search commands, documents, users…"
                        aria-label="Search"
                        style={{
                            flex: 1,
                            background: 'transparent',
                            border: 0,
                            outline: 'none',
                            color: 'var(--fg-0)',
                            fontSize: 14,
                            fontFamily: 'var(--font-sans)',
                        }}
                    />
                    <span className="kbd">ESC</span>
                </div>
                <div style={{ maxHeight: 420, overflow: 'auto', padding: 6 }}>
                    {Object.entries(grouped).map(([g, its]) => (
                        <div key={g} style={{ marginTop: 6 }}>
                            <div
                                style={{
                                    padding: '6px 10px',
                                    fontSize: 10,
                                    color: 'var(--fg-3)',
                                    fontFamily: 'var(--font-mono)',
                                    textTransform: 'uppercase',
                                    letterSpacing: '.08em',
                                }}
                            >
                                {g}
                            </div>
                            {its.map((it, i) => {
                                const IconCmp = Icon[it.icon];
                                return (
                                    <button
                                        key={`${g}-${i}`}
                                        type="button"
                                        onClick={() => setOpen(false)}
                                        style={{
                                            width: '100%',
                                            display: 'flex',
                                            alignItems: 'center',
                                            gap: 12,
                                            padding: '8px 10px',
                                            borderRadius: 7,
                                            border: 0,
                                            cursor: 'pointer',
                                            color: 'var(--fg-1)',
                                            background: 'transparent',
                                            textAlign: 'left',
                                            fontSize: 13,
                                        }}
                                        onMouseOver={(e) => {
                                            (e.currentTarget as HTMLButtonElement).style.background = 'var(--bg-3)';
                                        }}
                                        onMouseOut={(e) => {
                                            (e.currentTarget as HTMLButtonElement).style.background = 'transparent';
                                        }}
                                    >
                                        <IconCmp size={14} />
                                        <span style={{ flex: 1 }}>{it.label}</span>
                                        {it.kbd && <span className="kbd">{it.kbd}</span>}
                                    </button>
                                );
                            })}
                        </div>
                    ))}
                </div>
                <div
                    style={{
                        display: 'flex',
                        alignItems: 'center',
                        gap: 14,
                        padding: '8px 14px',
                        borderTop: '1px solid var(--hairline)',
                        fontSize: 11,
                        color: 'var(--fg-3)',
                    }}
                >
                    <span>
                        <span className="kbd">↑↓</span> navigate
                    </span>
                    <span>
                        <span className="kbd">⏎</span> select
                    </span>
                    <span style={{ flex: 1 }} />
                    <span className="mono">AskMyDocs v2.4.0</span>
                </div>
            </div>
        </div>
    );
}
