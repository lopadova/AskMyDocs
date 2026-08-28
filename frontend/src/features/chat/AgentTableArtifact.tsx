import { useState, type ReactNode } from 'react';
import { Icon } from '../../components/Icons';
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
    const [selected, setSelected] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const italian = locale.toLowerCase().startsWith('it');
    const selectable = onSelect !== undefined;
    const selectionRequired = artifact.interaction_mode === 'selection';

    async function select(rowKey: string, label: string): Promise<void> {
        if (!onSelect || submitting !== null || selected !== null) return;
        setSubmitting(rowKey);
        setError(null);
        try {
            await onSelect({
                messageId,
                rowKey,
                label,
                content: selectionContent(label, rowValues(artifact, rowKey), italian),
            });
            setSelected(rowKey);
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
                        {selectionRequired
                            ? (italian ? 'Scegli una riga per continuare' : 'Choose a row to continue')
                            : (italian ? 'Seleziona una riga per approfondire' : 'Select a row to inspect')}
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
                        {artifact.rows.map((row) => {
                            const isSelected = selected === row.key;

                            return (
                                <tr
                                    key={row.key}
                                    className={selectable ? 'is-selectable' : undefined}
                                    data-selected={isSelected || undefined}
                                    aria-selected={selectable ? isSelected : undefined}
                                    onClick={selectable ? () => void select(row.key, row.label) : undefined}
                                >
                                    {artifact.columns.map((column) => (
                                        <td key={column.key}>{displayValue(row.values[column.key])}</td>
                                    ))}
                                    {selectable && (
                                        <td className="agent-table-artifact-action">
                                            <button
                                                type="button"
                                                className={`btn sm ${isSelected ? 'ghost' : 'primary'} agent-table-artifact-select`}
                                                disabled={submitting !== null || selected !== null}
                                                onClick={(event) => {
                                                    event.stopPropagation();
                                                    void select(row.key, row.label);
                                                }}
                                                data-testid={`agent-table-select-${row.key}`}
                                            >
                                                {submitting === row.key
                                                    ? (italian ? 'Scelgo…' : 'Selecting…')
                                                    : isSelected
                                                        ? <><Icon.Check size={12} /> {italian ? 'Selezionata' : 'Selected'}</>
                                                        : (italian ? 'Usa questa riga' : 'Use this row')}
                                            </button>
                                        </td>
                                    )}
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
            {selected && (
                <div className="agent-table-artifact-success" role="status">
                    <Icon.Check size={13} />
                    {italian ? 'Scelta inviata alla chat.' : 'Selection sent to the chat.'}
                </div>
            )}
            {error && <div className="agent-table-artifact-error" role="alert">{error}</div>}
        </section>
    );
}

function rowValues(artifact: AgentTableArtifactData, rowKey: string): Record<string, string | number | boolean | null> {
    return artifact.rows.find((row) => row.key === rowKey)?.values ?? {};
}

function selectionContent(
    label: string,
    values: Record<string, string | number | boolean | null>,
    italian: boolean,
): string {
    const row = JSON.stringify(values, null, 2);

    return italian
        ? `Ho selezionato questa riga (${label}):\n\n${row}\n\nContinua usando tutti i dati della riga nel contesto della richiesta precedente.`
        : `I selected this row (${label}):\n\n${row}\n\nContinue using all row data in the context of the previous request.`;
}

function displayValue(value: string | number | boolean | null | undefined): string {
    if (value === null || value === undefined || value === '') return '—';
    if (typeof value === 'boolean') return value ? '✓' : '✕';

    return String(value);
}
