interface ToolResultPreviewProps {
    value: unknown;
}

/*
 * v5.0/W3 — Renders an MCP tool result (or input arguments) as a
 * compact JSON tree. Strings render verbatim; arrays/objects recurse;
 * primitives render with their typeof. Designed for the in-bubble
 * preview — not a full-blown JSON viewer.
 */

export function ToolResultPreview({ value }: ToolResultPreviewProps) {
    return (
        <div
            style={{
                fontFamily: 'var(--font-mono, ui-monospace)',
                fontSize: 11.5,
                color: 'var(--fg-1)',
                whiteSpace: 'pre-wrap',
                wordBreak: 'break-word',
                maxHeight: 280,
                overflowY: 'auto',
            }}
        >
            <Node value={value} depth={0} />
        </div>
    );
}

interface NodeProps {
    value: unknown;
    depth: number;
    keyName?: string;
}

function Node({ value, depth, keyName }: NodeProps) {
    if (value === null || value === undefined) {
        return <Leaf keyName={keyName} display={value === null ? 'null' : 'undefined'} tone="muted" />;
    }
    if (typeof value === 'string') {
        return <Leaf keyName={keyName} display={JSON.stringify(value)} tone="string" />;
    }
    if (typeof value === 'number' || typeof value === 'boolean' || typeof value === 'bigint') {
        return <Leaf keyName={keyName} display={String(value)} tone="primitive" />;
    }
    if (Array.isArray(value)) {
        if (value.length === 0) {
            return <Leaf keyName={keyName} display="[]" tone="muted" />;
        }
        return (
            <div style={{ marginLeft: depth === 0 ? 0 : 12 }}>
                <KeyLabel name={keyName} suffix="[" />
                {value.map((entry, index) => (
                    <Node key={index} value={entry} depth={depth + 1} keyName={String(index)} />
                ))}
                <span style={{ color: 'var(--fg-2)' }}>]</span>
            </div>
        );
    }
    if (typeof value === 'object') {
        const entries = Object.entries(value as Record<string, unknown>);
        if (entries.length === 0) {
            return <Leaf keyName={keyName} display="{}" tone="muted" />;
        }
        return (
            <div style={{ marginLeft: depth === 0 ? 0 : 12 }}>
                <KeyLabel name={keyName} suffix="{" />
                {entries.map(([nestedKey, nestedValue]) => (
                    <Node
                        key={nestedKey}
                        value={nestedValue}
                        depth={depth + 1}
                        keyName={nestedKey}
                    />
                ))}
                <span style={{ color: 'var(--fg-2)' }}>{'}'}</span>
            </div>
        );
    }
    return <Leaf keyName={keyName} display={String(value)} tone="muted" />;
}

interface LeafProps {
    keyName?: string;
    display: string;
    tone: 'string' | 'primitive' | 'muted';
}

function Leaf({ keyName, display, tone }: LeafProps) {
    const color = (() => {
        switch (tone) {
            case 'string':
                return '#86efac';
            case 'primitive':
                return '#fde68a';
            case 'muted':
            default:
                return 'var(--fg-2)';
        }
    })();
    return (
        <div>
            <KeyLabel name={keyName} />
            <span style={{ color }}>{display}</span>
        </div>
    );
}

function KeyLabel({ name, suffix }: { name?: string; suffix?: string }) {
    if (!name && !suffix) return null;
    return (
        <span style={{ color: 'var(--fg-2)' }}>
            {name ? (
                <>
                    <span>{name}</span>
                    <span>: </span>
                </>
            ) : null}
            {suffix}
        </span>
    );
}
