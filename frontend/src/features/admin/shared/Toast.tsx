import { useEffect, useState } from 'react';

/*
 * Minimal transient toast surface for admin flows. No external lib —
 * we already have zustand + TanStack Query and don't want to add
 * `sonner`/`react-hot-toast` for one feature. The component renders a
 * fixed overlay with a testid-addressable payload so E2E can assert
 * on success + error outcomes.
 *
 * Singleton-lite: the module exposes a `useToast()` hook that returns
 * a stable publisher, and a <ToastHost /> component that must be
 * mounted once near the route root.
 */

export type ToastKind = 'success' | 'error' | 'info';

export interface ToastEntry {
    id: number;
    kind: ToastKind;
    message: string;
    testid?: string;
}

type Listener = (entries: ToastEntry[]) => void;

const listeners = new Set<Listener>();
let entries: ToastEntry[] = [];
let seq = 1;

function publish() {
    listeners.forEach((l) => l([...entries]));
}

export function pushToast(kind: ToastKind, message: string, testid?: string): number {
    const id = seq++;
    entries = [...entries, { id, kind, message, testid }];
    publish();
    // Auto-dismiss after 5 seconds. Errors linger a bit longer so operators
    // actually see them before they fade — 8s is still within CI patience.
    const ttl = kind === 'error' ? 8000 : 5000;
    setTimeout(() => dismissToast(id), ttl);
    return id;
}

export function dismissToast(id: number) {
    entries = entries.filter((e) => e.id !== id);
    publish();
}

export function useToast() {
    return {
        success: (msg: string, testid?: string) => pushToast('success', msg, testid),
        error: (msg: string, testid?: string) => pushToast('error', msg, testid),
        info: (msg: string, testid?: string) => pushToast('info', msg, testid),
    };
}

export function ToastHost() {
    const [list, setList] = useState<ToastEntry[]>(entries);

    useEffect(() => {
        const listener: Listener = (next) => setList(next);
        listeners.add(listener);
        return () => {
            listeners.delete(listener);
        };
    }, []);

    if (list.length === 0) {
        return <div data-testid="toast-host" style={{ position: 'fixed', inset: 0, pointerEvents: 'none' }} />;
    }

    return (
        <div
            data-testid="toast-host"
            style={{
                position: 'fixed',
                top: 16,
                right: 16,
                display: 'flex',
                flexDirection: 'column',
                gap: 8,
                zIndex: 60,
                pointerEvents: 'none',
            }}
        >
            {list.map((entry) => (
                <div
                    key={entry.id}
                    data-testid={entry.testid ?? `toast-${entry.kind}`}
                    data-kind={entry.kind}
                    role="status"
                    style={{
                        pointerEvents: 'auto',
                        minWidth: 220,
                        maxWidth: 360,
                        padding: '10px 14px',
                        borderRadius: 10,
                        fontSize: 13,
                        lineHeight: 1.45,
                        background:
                            entry.kind === 'error'
                                ? 'rgba(239,68,68,0.16)'
                                : entry.kind === 'success'
                                  ? 'rgba(16,185,129,0.16)'
                                  : 'rgba(59,130,246,0.16)',
                        border:
                            '1px solid ' +
                            (entry.kind === 'error'
                                ? 'rgba(239,68,68,0.45)'
                                : entry.kind === 'success'
                                  ? 'rgba(16,185,129,0.45)'
                                  : 'rgba(59,130,246,0.45)'),
                        color: 'var(--fg-0)',
                        boxShadow: '0 10px 30px rgba(0,0,0,0.3)',
                        fontFamily: 'var(--font-sans)',
                    }}
                >
                    {entry.message}
                </div>
            ))}
        </div>
    );
}
