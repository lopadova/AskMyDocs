import { useMemo, useState, useEffect } from 'react';
import type { McpServerEntry } from './mcp-tools.api';

interface ToolMatrixProps {
    server: McpServerEntry;
    onSave: (enabledTools: string[]) => void;
    busy: boolean;
}

const ALLOW_ALL_SENTINEL = '*';

/*
 * v5.0/W2 — Per-server tool enable/disable matrix. Discovers tools from
 * the handshake response (`handshake_response.tools[]`), shows checkboxes,
 * highlights tools currently in `enabled_tools`. A special "allow all"
 * checkbox writes `['*']` (the BE understands the wildcard).
 *
 * Dirty state survives across re-renders until the user clicks Save or
 * Reset; refetched server data only re-syncs the saved baseline when
 * `enabled_tools` actually changes server-side.
 */
export function ToolMatrix({ server, onSave, busy }: ToolMatrixProps) {
    const discoveredTools = useMemo(
        () => server.handshake_response?.tools ?? [],
        [server.handshake_response],
    );
    const savedEnabled = useMemo(() => server.enabled_tools ?? [], [server.enabled_tools]);

    const [selection, setSelection] = useState<string[]>(savedEnabled);
    useEffect(() => {
        setSelection(savedEnabled);
    }, [savedEnabled]);

    const allowAll = selection.length === 1 && selection[0] === ALLOW_ALL_SENTINEL;
    const dirty = !arraysEqual(selection, savedEnabled);

    function toggle(name: string) {
        if (allowAll) {
            // Switching off "allow all" preserves the saved set
            setSelection(savedEnabled.filter((entry) => entry !== ALLOW_ALL_SENTINEL));
            return;
        }
        setSelection((prev) => {
            const set = new Set(prev);
            if (set.has(name)) {
                set.delete(name);
            } else {
                set.add(name);
            }
            return Array.from(set);
        });
    }

    function setAllowAll(next: boolean) {
        setSelection(next ? [ALLOW_ALL_SENTINEL] : []);
    }

    function reset() {
        setSelection(savedEnabled);
    }

    function save() {
        onSave(selection);
    }

    if (discoveredTools.length === 0) {
        return (
            <div
                data-testid={`admin-mcp-server-${server.id}-tool-matrix-empty`}
                style={{
                    padding: '14px 16px',
                    borderRadius: 12,
                    border: '1px dashed var(--border-2)',
                    background: 'var(--bg-2)',
                    color: 'var(--fg-2)',
                    fontSize: 13,
                }}
            >
                No tools discovered yet. Run a handshake first.
            </div>
        );
    }

    return (
        <div
            data-testid={`admin-mcp-server-${server.id}-tool-matrix`}
            data-state={busy ? 'busy' : dirty ? 'dirty' : 'ready'}
            aria-busy={busy}
            style={{
                display: 'flex',
                flexDirection: 'column',
                gap: 10,
                padding: 14,
                borderRadius: 12,
                border: '1px solid var(--border-1)',
                background: 'var(--bg-2)',
            }}
        >
            <label
                style={{
                    display: 'flex',
                    alignItems: 'center',
                    gap: 10,
                    fontSize: 13.5,
                    color: 'var(--fg-1)',
                    fontWeight: 600,
                }}
            >
                <input
                    type="checkbox"
                    data-testid={`admin-mcp-server-${server.id}-allow-all`}
                    checked={allowAll}
                    disabled={busy}
                    onChange={(event) => setAllowAll(event.target.checked)}
                />
                Allow all discovered tools (*)
            </label>
            <ul
                style={{
                    listStyle: 'none',
                    margin: 0,
                    padding: 0,
                    display: 'grid',
                    gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))',
                    gap: 8,
                }}
            >
                {discoveredTools.map((tool) => {
                    const isSelected = allowAll || selection.includes(tool.name);
                    return (
                        <li key={tool.name}>
                            <label
                                style={{
                                    display: 'flex',
                                    alignItems: 'center',
                                    gap: 10,
                                    padding: '8px 10px',
                                    borderRadius: 8,
                                    border: '1px solid var(--border-2)',
                                    background: 'var(--bg-1)',
                                    fontSize: 12.5,
                                    color: 'var(--fg-1)',
                                    cursor: busy ? 'wait' : 'pointer',
                                }}
                            >
                                <input
                                    type="checkbox"
                                    data-testid={`admin-mcp-server-${server.id}-tool-${tool.name}`}
                                    checked={isSelected}
                                    disabled={busy || allowAll}
                                    onChange={() => toggle(tool.name)}
                                />
                                <span style={{ fontWeight: 600 }}>{tool.name}</span>
                                {tool.description ? (
                                    <span
                                        title={tool.description}
                                        style={{
                                            color: 'var(--fg-2)',
                                            fontSize: 11.5,
                                            overflow: 'hidden',
                                            textOverflow: 'ellipsis',
                                            whiteSpace: 'nowrap',
                                            maxWidth: 180,
                                        }}
                                    >
                                        — {tool.description}
                                    </span>
                                ) : null}
                            </label>
                        </li>
                    );
                })}
            </ul>
            <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8 }}>
                <button
                    type="button"
                    data-testid={`admin-mcp-server-${server.id}-tool-matrix-reset`}
                    onClick={reset}
                    disabled={!dirty || busy}
                    style={baseBtnStyle}
                >
                    Reset
                </button>
                <button
                    type="button"
                    data-testid={`admin-mcp-server-${server.id}-tool-matrix-save`}
                    onClick={save}
                    disabled={!dirty || busy}
                    style={primaryBtnStyle(!dirty || busy)}
                >
                    {busy ? 'Saving…' : 'Save changes'}
                </button>
            </div>
        </div>
    );
}

function arraysEqual(a: string[], b: string[]): boolean {
    if (a.length !== b.length) return false;
    const sortedA = [...a].sort();
    const sortedB = [...b].sort();
    for (let i = 0; i < sortedA.length; i++) {
        if (sortedA[i] !== sortedB[i]) return false;
    }
    return true;
}

const baseBtnStyle: React.CSSProperties = {
    padding: '6px 12px',
    borderRadius: 8,
    border: '1px solid var(--border-2)',
    background: 'var(--bg-1)',
    color: 'var(--fg-1)',
    cursor: 'pointer',
    fontSize: 12.5,
    fontWeight: 600,
};

function primaryBtnStyle(disabled: boolean): React.CSSProperties {
    return {
        padding: '6px 12px',
        borderRadius: 8,
        border: '1px solid transparent',
        background: disabled ? 'var(--bg-2)' : 'var(--accent-bg)',
        color: disabled ? 'var(--fg-2)' : 'var(--accent-fg)',
        cursor: disabled ? 'not-allowed' : 'pointer',
        fontSize: 12.5,
        fontWeight: 600,
    };
}
