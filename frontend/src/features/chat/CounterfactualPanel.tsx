import { useMemo, type ReactNode } from 'react';
import type { CounterfactualPanel as CounterfactualPanelData } from './chat.api';

interface CounterfactualPanelProps {
    rows: CounterfactualPanelData[];
    enabled: boolean;
}

export function CounterfactualPanel({ rows, enabled }: CounterfactualPanelProps): ReactNode {
    const totalOtherProjects = useMemo(() => rows.length, [rows.length]);
    if (!enabled || rows.length === 0) {
        return null;
    }

    return (
        <details style={{ marginTop: 10, border: '1px solid var(--panel-border)', borderRadius: 10, padding: 10 }}>
            <summary style={{ cursor: 'pointer', fontSize: 12, color: 'var(--fg-2)' }}>
                Counterfactual citations <span className="mono">({totalOtherProjects} other projects)</span>
            </summary>
            <div style={{ marginTop: 8, display: 'flex', flexDirection: 'column', gap: 8 }}>
                {rows.map((panel) => (
                    <div key={panel.project_key} style={{ border: '1px solid var(--hairline)', borderRadius: 8, padding: 8 }}>
                        <div style={{ fontSize: 12, color: 'var(--fg-1)', marginBottom: 6 }}>
                            {panel.project_key}
                        </div>
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
                            {panel.top_chunks.slice(0, 3).map((chunk) => (
                                <div key={chunk.chunk_id} style={{ fontSize: 11.5, color: 'var(--fg-2)' }}>
                                    {chunk.document?.title ?? chunk.heading_path ?? `Chunk #${chunk.chunk_id}`}
                                </div>
                            ))}
                        </div>
                    </div>
                ))}
            </div>
        </details>
    );
}

