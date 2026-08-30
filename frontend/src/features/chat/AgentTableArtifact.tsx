import { useState, type ReactNode } from 'react';
import { Button } from '../../components/Button';
import { Icon } from '../../components/Icons';
import type { AgentTableArtifact as AgentTableArtifactData } from './chat.api';

export interface AgentArtifactSelection {
    messageId: number;
    rowKey: string;
    label: string;
    displayText: string;
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
    const actionLabel = selectionRequired
        ? (italian ? 'Seleziona' : 'Select')
        : (italian ? 'Apri' : 'Open');

    async function select(rowKey: string, label: string): Promise<void> {
        if (!onSelect || submitting !== null || selected !== null) return;
        setSubmitting(rowKey);
        setError(null);
        try {
            await onSelect({
                messageId,
                rowKey,
                label,
                displayText: italian ? `Ho selezionato “${label}”.` : `I selected “${label}”.`,
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
                <div className="agent-table-artifact-identity">
                    <span className="agent-table-artifact-icon" aria-hidden="true">
                        <Icon.Grid size={13} />
                    </span>
                    <div className="agent-table-artifact-heading">
                        <strong>{displayTitle(artifact.title)}</strong>
                        <span>{resultSummary(artifact.total_rows, artifact.rows.length, artifact.truncated, italian)}</span>
                    </div>
                </div>
                {selectable && (
                    <span className="agent-table-artifact-hint">
                        <Icon.Eye size={12} />
                        {selectionRequired
                            ? (italian ? 'Scegli un risultato' : 'Choose a result')
                            : (italian ? 'Apri una riga per i dettagli' : 'Open a row for details')}
                    </span>
                )}
            </div>
            <div className="agent-table-artifact-scroll">
                <table aria-label={`${displayTitle(artifact.title)} · ${artifact.total_rows} ${italian ? 'risultati' : 'results'}`}>
                    <thead>
                        <tr>
                            {artifact.columns.map((column) => <th key={column.key}>{column.label}</th>)}
                            {selectable && (
                                <th className="agent-table-artifact-action-heading">
                                    {italian ? 'Azione' : 'Action'}
                                </th>
                            )}
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
                                    {artifact.columns.map((column) => {
                                        const rawValue = row.values[column.key];
                                        const value = displayValue(rawValue, locale);

                                        return (
                                            <td key={column.key} title={value !== String(rawValue ?? '') ? String(rawValue ?? '') : undefined}>
                                                <span data-empty={value === '—' || undefined}>{value}</span>
                                            </td>
                                        );
                                    })}
                                    {selectable && (
                                        <td className="agent-table-artifact-action">
                                            <Button
                                                className="agent-table-artifact-select"
                                                variant="secondary"
                                                size="sm"
                                                busy={submitting === row.key}
                                                leadingIcon={isSelected ? <Icon.Check size={13} /> : undefined}
                                                trailingIcon={!isSelected && submitting !== row.key ? <Icon.Chevron size={12} /> : undefined}
                                                data-state={submitting === row.key ? 'loading' : isSelected ? 'selected' : 'idle'}
                                                disabled={submitting !== null || selected !== null}
                                                aria-label={`${isSelected ? (italian ? 'Selezionata' : 'Selected') : actionLabel}: ${row.label}`}
                                                onClick={(event) => {
                                                    event.stopPropagation();
                                                    void select(row.key, row.label);
                                                }}
                                                data-testid={`agent-table-select-${row.key}`}
                                            >
                                                {submitting === row.key
                                                    ? (italian ? 'Attendi' : 'Wait')
                                                    : isSelected
                                                        ? (italian ? 'Selezionata' : 'Selected')
                                                        : actionLabel}
                                            </Button>
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

function displayTitle(title: string): string {
    const trimmed = title.trim();
    if (trimmed === '' || /\s/.test(trimmed)) return trimmed;

    const readable = trimmed.replace(/[_-]+/g, ' ').replace(/\s+/g, ' ');

    return readable.charAt(0).toUpperCase() + readable.slice(1);
}

function resultSummary(total: number, visible: number, truncated: boolean, italian: boolean): string {
    const noun = italian
        ? (total === 1 ? 'risultato' : 'risultati')
        : (total === 1 ? 'result' : 'results');
    const base = `${total} ${noun}`;

    return truncated ? `${base} · ${italian ? 'mostrati' : 'showing'} ${visible}` : base;
}

function displayValue(value: string | number | boolean | null | undefined, locale: string): string {
    if (value === null || value === undefined || value === '') return '—';
    if (typeof value === 'boolean') return value ? '✓' : '✕';
    if (typeof value === 'string' && /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/.test(value)) {
        const date = new Date(value);
        if (!Number.isNaN(date.getTime())) {
            try {
                return new Intl.DateTimeFormat(locale, {
                    dateStyle: 'medium',
                    timeStyle: 'short',
                }).format(date);
            } catch {
                return value;
            }
        }
    }

    return String(value);
}
