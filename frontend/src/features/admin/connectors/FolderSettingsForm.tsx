import { useEffect, useMemo, useState, type FormEvent, type ReactNode } from 'react';
import type { ConnectorInstallationDto } from './connectors.api';
import { useInstallationFolders } from './connectors-hooks';

/**
 * v8.24 — connection-settings picker for a credential (IMAP) account.
 *
 * Opens against an existing installation, fetches its LIVE folder list
 * (`GET /api/admin/connectors/{id}/folders`) and lets the operator multi-select
 * which folders to sync (`config_json.folders.include`) plus the look-back
 * window (`date_window_days`). Both are saved via the same PATCH the edit form
 * uses, so the capability stays one contract.
 *
 * Semantics surfaced to the user:
 *  - empty selection = sync ALL non-excluded folders (the connector default).
 *  - a NON-empty selection is a whitelist that BYPASSES the default
 *    Trash/Spam/Junk exclusions — shown as a warning so it isn't a surprise.
 *
 * The option set is the UNION of the live folders and any already-saved include
 * paths, so a previously-picked folder that has since vanished from the server
 * (e.g. a deleted Gmail label) is still shown — checked, flagged "not found" —
 * instead of silently dropping out of the whitelist on the next save.
 *
 * R11/R29 testids `connector-{key}-folders-form*`; R15 every control has a bound
 * label, dialog is role=dialog + aria-modal, Esc closes; R14 the fetch
 * loading/error/empty/ready states are all observable via `data-state`.
 */

export interface FolderSettingsValues {
    include: string[];
    /** null = leave the connector default window untouched. */
    dateWindowDays: number | null;
}

export interface FolderSettingsFormProps {
    connectorKey: string;
    account: ConnectorInstallationDto;
    onSubmit: (values: FolderSettingsValues) => void;
    onClose: () => void;
    submitError?: string | null;
    fieldErrors?: Record<string, string>;
    isSubmitting?: boolean;
}

function slug(path: string): string {
    return path.replace(/[^a-zA-Z0-9]+/g, '-').replace(/^-+|-+$/g, '').toLowerCase() || 'folder';
}

export function FolderSettingsForm({
    connectorKey,
    account,
    onSubmit,
    onClose,
    submitError,
    fieldErrors,
    isSubmitting,
}: FolderSettingsFormProps): ReactNode {
    const foldersQuery = useInstallationFolders(account.id, true);

    const saved = account.folders?.include ?? [];
    const [selected, setSelected] = useState<ReadonlySet<string>>(() => new Set(saved));
    const [dateWindow, setDateWindow] = useState<string>(
        account.date_window_days == null ? '' : String(account.date_window_days),
    );

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') onClose();
        };
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [onClose]);

    const live = foldersQuery.data ?? [];
    // Union of live folders + already-saved include paths (saved-but-missing stays
    // visible so a save never silently drops it). Stable, sorted for a predictable
    // order regardless of server enumeration order.
    const options = useMemo(() => {
        const set = new Set<string>([...live, ...saved]);
        return Array.from(set).sort((a, b) => a.localeCompare(b));
    }, [live, saved]);

    const liveSet = useMemo(() => new Set(live), [live]);

    const fetchState: 'loading' | 'error' | 'empty' | 'ready' = foldersQuery.isLoading
        ? 'loading'
        : foldersQuery.isError
          ? 'error'
          : options.length === 0
            ? 'empty'
            : 'ready';

    const toggle = (path: string) => {
        setSelected((cur) => {
            const next = new Set(cur);
            if (next.has(path)) {
                next.delete(path);
            } else {
                next.add(path);
            }
            return next;
        });
    };

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        const trimmed = dateWindow.trim();
        const parsed = trimmed === '' ? null : Number(trimmed);
        onSubmit({
            // Preserve the option order for a deterministic include payload.
            include: options.filter((p) => selected.has(p)),
            dateWindowDays: parsed != null && Number.isFinite(parsed) ? parsed : null,
        });
    };

    const titleId = `connector-${connectorKey}-folders-form-title`;
    const dateId = `connector-${connectorKey}-folders-form-date-window`;
    const selectedCount = selected.size;

    return (
        <div
            data-testid={`connector-${connectorKey}-folders-form-backdrop`}
            onClick={(e) => {
                if (e.target === e.currentTarget) onClose();
            }}
            style={{
                position: 'fixed',
                inset: 0,
                background: 'rgba(0,0,0,.4)',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                zIndex: 100,
            }}
        >
            <form
                role="dialog"
                aria-modal="true"
                aria-labelledby={titleId}
                aria-busy={isSubmitting}
                data-testid={`connector-${connectorKey}-folders-form`}
                data-state={fetchState}
                onSubmit={handleSubmit}
                style={{
                    background: 'var(--panel-solid, #1a1a22)',
                    border: '1px solid var(--panel-border-strong, rgba(255,255,255,.12))',
                    borderRadius: 12,
                    boxShadow: 'var(--shadow, 0 8px 24px rgba(0,0,0,.4))',
                    minWidth: 380,
                    maxWidth: 520,
                    maxHeight: '85vh',
                    padding: 16,
                    display: 'flex',
                    flexDirection: 'column',
                    gap: 12,
                    overflow: 'hidden',
                }}
            >
                <h2 id={titleId} style={{ margin: 0, fontSize: 14, color: 'var(--fg-0)' }}>
                    Connection settings — {account.label}
                </h2>

                <fieldset style={{ border: 0, margin: 0, padding: 0, minHeight: 0, display: 'flex', flexDirection: 'column', gap: 6 }}>
                    <legend style={{ color: 'var(--fg-2)', fontSize: 11, padding: 0 }}>
                        Folders to sync
                    </legend>

                    {fetchState === 'loading' && (
                        <div
                            data-testid={`connector-${connectorKey}-folders-form-loading`}
                            role="status"
                            aria-busy="true"
                            style={emptyBoxStyle()}
                        >
                            Loading folders…
                        </div>
                    )}

                    {fetchState === 'error' && (
                        <div
                            data-testid={`connector-${connectorKey}-folders-form-fetch-error`}
                            role="alert"
                            style={{ ...emptyBoxStyle(), color: '#fca5a5', borderColor: 'rgba(239,68,68,.30)' }}
                        >
                            Could not reach the mailbox to list folders.{' '}
                            <button
                                type="button"
                                data-testid={`connector-${connectorKey}-folders-form-retry`}
                                className="focus-ring"
                                onClick={() => foldersQuery.refetch()}
                                style={ghostButton()}
                            >
                                Retry
                            </button>
                        </div>
                    )}

                    {fetchState === 'empty' && (
                        <div
                            data-testid={`connector-${connectorKey}-folders-form-empty`}
                            role="status"
                            style={emptyBoxStyle()}
                        >
                            No folders found. Saving with none selected syncs all non-excluded folders.
                        </div>
                    )}

                    {fetchState === 'ready' && (
                        <ul
                            data-testid={`connector-${connectorKey}-folders-form-list`}
                            style={{
                                listStyle: 'none',
                                margin: 0,
                                padding: 0,
                                display: 'flex',
                                flexDirection: 'column',
                                gap: 2,
                                overflowY: 'auto',
                                maxHeight: '40vh',
                                border: '1px solid var(--hairline)',
                                borderRadius: 8,
                            }}
                        >
                            {options.map((path) => {
                                const id = `connector-${connectorKey}-folders-form-folder-${slug(path)}`;
                                const missing = !liveSet.has(path);
                                return (
                                    <li key={path} style={{ display: 'flex' }}>
                                        <label
                                            htmlFor={id}
                                            style={{
                                                display: 'flex',
                                                alignItems: 'center',
                                                gap: 8,
                                                padding: '6px 10px',
                                                fontSize: 12,
                                                color: 'var(--fg-1)',
                                                width: '100%',
                                                cursor: 'pointer',
                                            }}
                                        >
                                            <input
                                                id={id}
                                                data-testid={id}
                                                type="checkbox"
                                                checked={selected.has(path)}
                                                onChange={() => toggle(path)}
                                            />
                                            <span style={{ fontFamily: 'var(--font-mono)' }}>{path}</span>
                                            {missing && (
                                                <span
                                                    data-testid={`${id}-missing`}
                                                    style={{ fontSize: 10, color: 'var(--fg-3)' }}
                                                >
                                                    (not found on server)
                                                </span>
                                            )}
                                        </label>
                                    </li>
                                );
                            })}
                        </ul>
                    )}

                    {selectedCount > 0 && (
                        <p
                            data-testid={`connector-${connectorKey}-folders-form-whitelist-warning`}
                            role="note"
                            style={{ margin: 0, fontSize: 10.5, color: 'var(--fg-3)' }}
                        >
                            {selectedCount} folder{selectedCount === 1 ? '' : 's'} selected. A non-empty
                            selection is a whitelist and bypasses the default Trash/Spam/Junk
                            exclusions.
                        </p>
                    )}
                    {fieldErrors?.['folders.include'] && (
                        <span
                            data-testid={`connector-${connectorKey}-folders-form-folders-error`}
                            role="alert"
                            style={{ fontSize: 10.5, color: 'var(--err, #fca5a5)' }}
                        >
                            {fieldErrors['folders.include']}
                        </span>
                    )}
                </fieldset>

                <label htmlFor={dateId} style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
                    <span style={{ color: 'var(--fg-2)', fontSize: 11 }}>
                        Sync window (days) — leave empty for the connector default
                    </span>
                    <input
                        id={dateId}
                        data-testid={dateId}
                        type="number"
                        min={0}
                        max={3650}
                        value={dateWindow}
                        onChange={(e) => setDateWindow(e.target.value)}
                        placeholder="e.g. 365"
                        style={inputStyle()}
                    />
                    {fieldErrors?.date_window_days && (
                        <span
                            data-testid={`${dateId}-error`}
                            role="alert"
                            style={{ fontSize: 10.5, color: 'var(--err, #fca5a5)' }}
                        >
                            {fieldErrors.date_window_days}
                        </span>
                    )}
                </label>

                {submitError && (
                    <p
                        data-testid={`connector-${connectorKey}-folders-form-error`}
                        role="alert"
                        style={{ margin: 0, fontSize: 11.5, color: 'var(--err, #fca5a5)' }}
                    >
                        {submitError}
                    </p>
                )}

                <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 4 }}>
                    <button
                        type="button"
                        data-testid={`connector-${connectorKey}-folders-form-cancel`}
                        onClick={onClose}
                        disabled={isSubmitting}
                        style={buttonStyle('secondary', !!isSubmitting)}
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        data-testid={`connector-${connectorKey}-folders-form-submit`}
                        disabled={isSubmitting || fetchState === 'loading'}
                        style={buttonStyle('primary', !!isSubmitting || fetchState === 'loading')}
                    >
                        {isSubmitting ? 'Saving…' : 'Save settings'}
                    </button>
                </div>
            </form>
        </div>
    );
}

function emptyBoxStyle(): React.CSSProperties {
    return {
        padding: 14,
        textAlign: 'center',
        color: 'var(--fg-3)',
        fontSize: 12,
        border: '1px dashed var(--hairline)',
        borderRadius: 8,
    };
}

function inputStyle(): React.CSSProperties {
    return {
        padding: '5px 8px',
        borderRadius: 6,
        border: '1px solid var(--panel-border, rgba(255,255,255,.15))',
        background: 'var(--bg-3, rgba(255,255,255,.04))',
        color: 'var(--fg-0)',
        fontSize: 12,
    };
}

function ghostButton(): React.CSSProperties {
    return {
        marginLeft: 8,
        padding: '3px 10px',
        fontSize: 11,
        background: 'transparent',
        color: 'inherit',
        border: '1px solid currentColor',
        borderRadius: 6,
        cursor: 'pointer',
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
