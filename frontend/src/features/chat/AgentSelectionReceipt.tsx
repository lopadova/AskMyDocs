import type { ReactNode } from 'react';
import { Icon } from '../../components/Icons';
import type { AgentSelectionDisplayField, AgentSelectionMetadata } from './chat.api';

interface AgentSelectionReceiptProps {
    selection: AgentSelectionMetadata;
    locale?: string;
}

const SENSITIVE_FIELD = /(?:^|[._-])(token|secret|password|passcode|api.?key|authorization|cookie|private.?key)(?:$|[._-])/i;

export function AgentSelectionReceipt({
    selection,
    locale = 'en',
}: AgentSelectionReceiptProps): ReactNode {
    const italian = locale.toLowerCase().startsWith('it');
    const title = cleanText(selection.display?.title) || cleanText(selection.label) || selection.row_key;
    const fields = displayFields(selection, title);

    return (
        <div
            className="agent-selection-receipt"
            data-testid="agent-selection-receipt"
            aria-label={italian ? `Selezione effettuata: ${title}` : `Selection saved: ${title}`}
        >
            <div className="agent-selection-receipt-heading">
                <span className="agent-selection-receipt-icon" aria-hidden="true">
                    <Icon.Check size={13} />
                </span>
                <div>
                    <span>{italian ? 'Selezione effettuata' : 'Selection saved'}</span>
                    <strong>{title}</strong>
                </div>
            </div>
            {fields.length > 0 && (
                <dl className="agent-selection-receipt-fields">
                    {fields.map((field) => (
                        <div key={field.key}>
                            <dt>{field.label}</dt>
                            <dd title={rawTitle(field.value)}>{displayValue(field.value, locale, italian)}</dd>
                        </div>
                    ))}
                </dl>
            )}
        </div>
    );
}

function displayFields(selection: AgentSelectionMetadata, title: string): AgentSelectionDisplayField[] {
    const provided = Array.isArray(selection.display?.fields)
        ? selection.display.fields.filter(isDisplayField)
        : [];
    const fields = provided.length > 0 ? provided : fallbackFields(selection.record);

    return fields
        .filter((field) => !SENSITIVE_FIELD.test(field.key))
        .filter((field) => String(field.value ?? '').trim() !== '[REDACTED]')
        .filter((field) => !isDuplicateTitle(field, title))
        .slice(0, 7);
}

function fallbackFields(record: Record<string, unknown>): AgentSelectionDisplayField[] {
    const fields: AgentSelectionDisplayField[] = [];
    for (const [key, value] of Object.entries(record)) {
        if (isScalar(value)) {
            fields.push({ key, label: humanize(key), value });
            continue;
        }
        if (!value || Array.isArray(value) || typeof value !== 'object') continue;
        for (const [nestedKey, nestedValue] of Object.entries(value)) {
            if (!isScalar(nestedValue)) continue;
            const path = `${key}.${nestedKey}`;
            fields.push({ key: path, label: humanize(path), value: nestedValue });
        }
    }

    return fields;
}

function isDisplayField(value: unknown): value is AgentSelectionDisplayField {
    if (!value || typeof value !== 'object' || Array.isArray(value)) return false;
    const field = value as Partial<AgentSelectionDisplayField>;

    return typeof field.key === 'string'
        && field.key.trim() !== ''
        && typeof field.label === 'string'
        && isScalar(field.value);
}

function isScalar(value: unknown): value is string | number | boolean | null {
    return value === null || ['string', 'number', 'boolean'].includes(typeof value);
}

function isDuplicateTitle(field: AgentSelectionDisplayField, title: string): boolean {
    if (typeof field.value !== 'string' || field.value.trim().toLowerCase() !== title.trim().toLowerCase()) {
        return false;
    }
    const leaf = field.key.split('.').at(-1)?.toLowerCase();

    return ['label', 'name', 'full_name', 'display_name', 'title'].includes(leaf ?? '');
}

function displayValue(value: AgentSelectionDisplayField['value'], locale: string, italian: boolean): string {
    if (value === null || value === '') return '—';
    if (typeof value === 'boolean') return value ? (italian ? 'Sì' : 'Yes') : 'No';
    if (typeof value === 'string' && /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/.test(value)) {
        const date = new Date(value);
        if (!Number.isNaN(date.getTime())) {
            try {
                return new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeStyle: 'short' }).format(date);
            } catch {
                return value;
            }
        }
    }

    return String(value);
}

function humanize(key: string): string {
    const text = key.replace(/[._-]+/g, ' ').replace(/\s+/g, ' ').trim();

    return text === '' ? key : text.charAt(0).toUpperCase() + text.slice(1);
}

function cleanText(value: unknown): string {
    return typeof value === 'string' ? value.trim() : '';
}

function rawTitle(value: AgentSelectionDisplayField['value']): string | undefined {
    return value === null ? undefined : String(value);
}
