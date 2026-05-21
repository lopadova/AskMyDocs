import { useState, type ReactNode } from 'react';
import { chatApi, type RunnerUpChunk } from './chat.api';

interface RetrievalRunnerUpPanelProps {
    rows: RunnerUpChunk[];
}

export function RetrievalRunnerUpPanel({ rows }: RetrievalRunnerUpPanelProps): ReactNode {
    const [busyKey, setBusyKey] = useState<string | null>(null);

    if (rows.length === 0) {
        return null;
    }

    const send = async (chunkId: number, signal: 'should_have_cited' | 'not_relevant') => {
        const key = `${chunkId}:${signal}`;
        setBusyKey(key);
        try {
            await chatApi.sendChunkFeedback(chunkId, signal);
        } finally {
            setBusyKey(null);
        }
    };

    return (
        <details style={{ marginTop: 10, border: '1px solid var(--panel-border)', borderRadius: 10, padding: 10 }}>
            <summary style={{ cursor: 'pointer', fontSize: 12, color: 'var(--fg-2)' }}>
                Considered but not used ({rows.length})
            </summary>
            <div style={{ marginTop: 8, display: 'flex', flexDirection: 'column', gap: 8 }}>
                {rows.slice(0, 5).map((row) => (
                    <div key={row.chunk_id} style={{ border: '1px solid var(--hairline)', borderRadius: 8, padding: 8 }}>
                        <div style={{ fontSize: 12, color: 'var(--fg-1)', marginBottom: 4 }}>
                            {row.document?.title ?? row.heading_path ?? `Chunk #${row.chunk_id}`}
                        </div>
                        <div style={{ fontSize: 11.5, color: 'var(--fg-2)', lineHeight: 1.45 }}>
                            {(row.chunk_text ?? '').slice(0, 180)}
                        </div>
                        <div style={{ display: 'flex', gap: 6, marginTop: 8 }}>
                            <button
                                type="button"
                                className="btn sm"
                                onClick={() => void send(row.chunk_id, 'should_have_cited')}
                                disabled={busyKey !== null}
                            >
                                {busyKey === `${row.chunk_id}:should_have_cited` ? 'Sending…' : 'Should have cited'}
                            </button>
                            <button
                                type="button"
                                className="btn sm"
                                onClick={() => void send(row.chunk_id, 'not_relevant')}
                                disabled={busyKey !== null}
                            >
                                {busyKey === `${row.chunk_id}:not_relevant` ? 'Sending…' : 'Was not relevant'}
                            </button>
                        </div>
                    </div>
                ))}
            </div>
        </details>
    );
}

