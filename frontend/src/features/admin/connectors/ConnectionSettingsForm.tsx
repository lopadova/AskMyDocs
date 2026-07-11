import { useEffect, useMemo, useState, type FormEvent, type ReactNode } from 'react';
import type { ConnectorInstallationDto } from './connectors.api';
import { useInstallationFolders } from './connectors-hooks';
import {
    buildSettingsPayload,
    FieldRow,
    groupFields,
    isFieldVisible,
    seedValue,
    slug,
} from './settings-fields';

/**
 * v8.25 — the GENERIC schema-driven connection-settings editor for a connector
 * account. Renders the connector's full `connection_settings_schema` (grouped by
 * `group`) seeded from the account's current `settings`, and PATCHes a nested
 * `settings` object keyed by each field's dotted name. NO connector-specific
 * markup (R23) — every field is rendered by its `type` via the shared
 * {@see FieldRow} (settings-fields.tsx).
 *
 * v8.31 — the redesigned Edit modal (design handoff "Config Modals") renders the
 * IMAP-shaped schema through the opinionated tri-state {@see SyncSettingsForm}
 * instead; this generic form remains the FALLBACK for any credential connector
 * whose schema is NOT folder-shaped (and the standalone folder-picker modal).
 *
 * R11/R29 testids `connector-{key}-settings-form*` + per-field
 * `connector-{key}-settings-{slug(name)}*`; R15 every control has a bound label,
 * the dialog is role=dialog + aria-modal, Esc closes; R14 the folder fetch
 * loading/error/empty/ready states are observable via `data-state`.
 */

export interface ConnectionSettingsFormProps {
    connectorKey: string;
    account: ConnectorInstallationDto;
    /** The nested `settings` payload (a partial of config_json). */
    onSubmit: (settings: Record<string, unknown>) => void;
    onClose: () => void;
    submitError?: string | null;
    /** BE 422 field errors keyed by the dotted setting path (e.g. `settings.date_window_days`). */
    fieldErrors?: Record<string, string>;
    isSubmitting?: boolean;
    /** v8.29 — render as a plain panel (no backdrop / title) for the tabbed modal. */
    embedded?: boolean;
    /** v8.31 — omit the form's own footer so the host tabbed modal owns it;
     *  `formId` lets the external Save submit this form. */
    footerless?: boolean;
    formId?: string;
}

export function ConnectionSettingsForm({
    connectorKey,
    account,
    onSubmit,
    onClose,
    submitError,
    fieldErrors,
    isSubmitting,
    embedded = false,
    footerless = false,
    formId,
}: ConnectionSettingsFormProps): ReactNode {
    const schema = account.connection_settings_schema ?? [];
    const needsFolders = schema.some((f) => f.discovery === 'folders');
    const foldersQuery = useInstallationFolders(account.id, needsFolders);

    const [values, setValues] = useState<Record<string, unknown>>(() => {
        const seed: Record<string, unknown> = {};
        for (const f of schema) seed[f.name] = seedValue(f, account.settings ?? {});
        return seed;
    });

    useEffect(() => {
        const onKey = (e: globalThis.KeyboardEvent) => {
            if (e.key === 'Escape') onClose();
        };
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [onClose]);

    const groups = useMemo(() => groupFields(schema), [schema]);

    const live = foldersQuery.data ?? [];
    const fetchState: 'loading' | 'error' | 'ready' = foldersQuery.isLoading
        ? 'loading'
        : foldersQuery.isError
          ? 'error'
          : 'ready';

    const setValue = (name: string, v: unknown) => setValues((cur) => ({ ...cur, [name]: v }));

    // Surface both field-level (`settings.<name>`) AND element-level
    // (`settings.<name>.0`) validation errors — Laravel keys list-item failures
    // to the element, so without the prefix scan a 422 on a multiselect/tags entry
    // would render no actionable feedback (R14).
    const errorFor = (name: string): string | undefined => {
        if (!fieldErrors) return undefined;
        const exact = fieldErrors[`settings.${name}`];
        if (exact) return exact;
        const prefix = `settings.${name}.`;
        for (const [key, msg] of Object.entries(fieldErrors)) {
            if (key.startsWith(prefix)) return msg;
        }
        return undefined;
    };

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        onSubmit(buildSettingsPayload(schema, values));
    };

    const titleId = `connector-${connectorKey}-settings-form-title`;
    const formState = needsFolders ? fetchState : 'ready';

    const dialog = (
        <form
            role={embedded ? undefined : 'dialog'}
            aria-modal={embedded ? undefined : 'true'}
            aria-labelledby={embedded ? undefined : titleId}
            aria-busy={isSubmitting}
            id={formId}
            data-testid={`connector-${connectorKey}-settings-form`}
            data-state={formState}
            onSubmit={handleSubmit}
            style={embedded ? embeddedDialogStyle() : dialogStyle()}
        >
            {!embedded && (
                <h2 id={titleId} style={{ margin: 0, fontSize: 14, color: 'var(--fg-0)' }}>
                    Connection settings — {account.label}
                </h2>
            )}

            <div style={{ overflowY: 'auto', display: 'flex', flexDirection: 'column', gap: 14, paddingRight: 4 }}>
                {groups.map((g) => (
                    <fieldset
                        key={g.group}
                        data-testid={`connector-${connectorKey}-settings-group-${slug(g.group)}`}
                        style={{ border: 0, margin: 0, padding: 0, display: 'flex', flexDirection: 'column', gap: 8 }}
                    >
                        <legend style={{ color: 'var(--fg-2)', fontSize: 11, padding: 0, fontWeight: 600 }}>
                            {g.group}
                        </legend>
                        {g.fields
                            .filter((f) => isFieldVisible(f, values))
                            .map((field) => (
                                <FieldRow
                                    key={field.name}
                                    connectorKey={connectorKey}
                                    field={field}
                                    value={values[field.name]}
                                    onChange={(v) => setValue(field.name, v)}
                                    liveFolders={live}
                                    fetchState={fetchState}
                                    onRetryFolders={() => foldersQuery.refetch()}
                                    error={errorFor(field.name)}
                                />
                            ))}
                    </fieldset>
                ))}
            </div>

            {submitError && (
                <p
                    data-testid={`connector-${connectorKey}-settings-form-error`}
                    role="alert"
                    style={{ margin: 0, fontSize: 11.5, color: 'var(--err, #fca5a5)' }}
                >
                    {submitError}
                </p>
            )}

            {!footerless && (
                <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 4 }}>
                    <button
                        type="button"
                        data-testid={`connector-${connectorKey}-settings-form-cancel`}
                        onClick={onClose}
                        disabled={isSubmitting}
                        style={buttonStyle('secondary', !!isSubmitting)}
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        data-testid={`connector-${connectorKey}-settings-form-submit`}
                        disabled={isSubmitting || formState === 'loading'}
                        style={buttonStyle('primary', !!isSubmitting || formState === 'loading')}
                    >
                        {isSubmitting ? 'Saving…' : 'Save settings'}
                    </button>
                </div>
            )}
        </form>
    );

    if (embedded) {
        return dialog;
    }

    return (
        <div
            data-testid={`connector-${connectorKey}-settings-form-backdrop`}
            onClick={(e) => {
                if (e.target === e.currentTarget) onClose();
            }}
            style={backdropStyle()}
        >
            {dialog}
        </div>
    );
}

function backdropStyle(): React.CSSProperties {
    return {
        position: 'fixed',
        inset: 0,
        background: 'rgba(0,0,0,.4)',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        zIndex: 100,
    };
}

function dialogStyle(): React.CSSProperties {
    return {
        background: 'var(--panel-solid, #1a1a22)',
        border: '1px solid var(--panel-border-strong, rgba(255,255,255,.12))',
        borderRadius: 12,
        boxShadow: 'var(--shadow, 0 8px 24px rgba(0,0,0,.4))',
        minWidth: 420,
        maxWidth: 560,
        maxHeight: '88vh',
        padding: 16,
        display: 'flex',
        flexDirection: 'column',
        gap: 12,
        overflow: 'hidden',
    };
}

function embeddedDialogStyle(): React.CSSProperties {
    return {
        display: 'flex',
        flexDirection: 'column',
        gap: 12,
        width: '100%',
        overflow: 'hidden',
    };
}

function buttonStyle(variant: 'primary' | 'secondary', disabled: boolean): React.CSSProperties {
    const isPrimary = variant === 'primary';
    return {
        padding: '5px 14px',
        borderRadius: 6,
        border: '1px solid ' + (isPrimary ? 'var(--accent, #6366f1)' : 'var(--panel-border, rgba(255,255,255,.15))'),
        background: isPrimary ? 'var(--accent, #6366f1)' : 'transparent',
        color: isPrimary ? 'white' : 'var(--fg-1)',
        fontSize: 11.5,
        cursor: disabled ? 'not-allowed' : 'pointer',
        opacity: disabled ? 0.6 : 1,
    };
}
