import { useState, type ReactNode } from 'react';
import type { AgentTableArtifact as AgentTableArtifactData } from './chat.api';

export interface AgentArtifactSelection {
    messageId: number;
    rowKey: string;
    label: string;
    content: string;
}

interface AgentTableArtifactProps {
    artifact: AgentTableArtifactData;
    messageId: number;
    locale?: string;
    onSelect?: (selection: AgentArtifactSelection) => Promise<void>;
}

export function AgentTableArtifact({
    artifact,
    messageId,
    locale = 'en',
    onSelect,
}: AgentTableArtifactProps): ReactNode {
    const [submitting, setSubmitting] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const italian = locale.toLowerCase().startsWith('it');
    const selectable = artifact.interaction_mode === 'selection' && onSelect !== undefined;

    async function select(rowKey: string, label: string): Promise<void> {
        if (!onSelect || submitting !== null) return;
        setSubmitting(rowKey);
        setError(null);
        try {
            await onSelect({
                messageId,
                rowKey,
                label,
                content: italian
                    ? `Ho scelto: ${label}. Continua usando questa selezione.`
                    : `I selected: ${label}. Continue using this selection.`,
            });
        } catch (cause) {
            setError(cause instanceof Error ? cause.message : (italian ? 'Selezione non riuscita.' : 'Selection failed.'));
        } finally {
            setSubmitting(null);
        }
    }

    return (
        <section
            className="agent-table-artifact"
            data-testid={`agent-table-artifact-${messageId}`}
            data-interaction-mode={artifact.interaction_mode}
        >
            <div className="agent-table-artifact-header">
                <div>
                    <strong>{artifact.title}</strong>
                    <span>
                        {artifact.total_rows} {italian ? 'risultati' : 'results'}
                        {artifact.truncated ? ` · ${italian ? 'primi' : 'first'} ${artifact.rows.length}` : ''}
                    </span>
                </div>
                {selectable && (
                    <span className="agent-table-artifact-hint">
                        {italian ? 'Scegli una riga per continuare' : 'Choose a row to continue'}
                    </span>
                )}
            </div>
            <div className="agent-table-artifact-scroll">
                <table>
                    <thead>
                        <tr>
                            {artifact.columns.map((column) => <th key={column.key}>{column.label}</th>)}
                            {selectable && <th aria-label={italian ? 'Azione' : 'Action'} />}
                        </tr>
                    </thead>
                    <tbody>
                        {artifact.rows.map((row) => (
                            <tr key={row.key}>
                                {artifact.columns.map((column) => (
                                    <td key={column.key}>{displayValue(row.values[column.key])}</td>
                                ))}
                                {selectable && (
                                    <td className="agent-table-artifact-action">
                                        <button
                                            type="button"
                                            className="btn sm primary"
                                            disabled={submitting !== null}
                                            onClick={() => void select(row.key, row.label)}
                                            data-testid={`agent-table-select-${row.key}`}
                                        >
                                            {submitting === row.key
                                                ? (italian ? 'Scelgo…' : 'Selecting…')
                                                : (italian ? 'Scegli' : 'Select')}
                                        </button>
                                    </td>
                                )}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            {error && <div className="agent-table-artifact-error" role="alert">{error}</div>}
        </section>
    );
}

function displayValue(value: string | number | boolean | null | undefined): string {
    if (value === null || value === undefined || value === '') return '—';
    if (typeof value === 'boolean') return value ? '✓' : '✕';

    return String(value);
}
