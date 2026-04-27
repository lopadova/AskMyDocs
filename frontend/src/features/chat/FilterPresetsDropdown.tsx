import { useEffect, useRef, useState, type ReactNode } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
    chatFilterPresetsApi,
    isFilterStateEmpty,
    type ChatFilterPreset,
    type FilterState,
} from './chat.api';

/**
 * T2.9-FE — Saved filter presets dropdown.
 *
 * Sits next to the "+ Filter" trigger in FilterBar. Three actions:
 *  - Load preset → replaces the current filter state in one click.
 *  - Save current → creates a new preset from the live filter state
 *    after the user types a name.
 *  - Delete → removes a preset (with confirm).
 *
 * Per-user isolation is BE-enforced (T2.9-BE — cross-user IDs surface
 * as 404, never 403, to avoid leaking row existence). The FE doesn't
 * filter or scope; it just consumes whatever the API returns.
 *
 * UX rule: "Save current" is disabled when the live filter state is
 * empty — saving an empty preset has no value (user could just clear
 * the bar). This rule is also tested.
 *
 * R11: every interactive element has `data-testid`.
 * R15: dropdown is `role="menu"`, options are `role="menuitem"`.
 */

export interface FilterPresetsDropdownProps {
    /** Current live filter state — saved as a preset on demand. */
    filters: FilterState;
    /** Replaces the live filter state when the user loads a preset. */
    onLoad: (filters: FilterState) => void;
}

export function FilterPresetsDropdown({ filters, onLoad }: FilterPresetsDropdownProps): ReactNode {
    const [open, setOpen] = useState(false);
    const [savePromptOpen, setSavePromptOpen] = useState(false);
    const [name, setName] = useState('');
    const [error, setError] = useState<string | null>(null);
    const dropdownRef = useRef<HTMLDivElement>(null);
    const qc = useQueryClient();

    const presetsQuery = useQuery({
        queryKey: ['chat-filter-presets'],
        queryFn: () => chatFilterPresetsApi.list(),
        staleTime: 30_000,
        enabled: open,
    });

    const createMutation = useMutation({
        mutationFn: ({ name, filters }: { name: string; filters: FilterState }) =>
            chatFilterPresetsApi.create(name, filters),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ['chat-filter-presets'] });
            setSavePromptOpen(false);
            setName('');
            setError(null);
        },
        onError: (err: unknown) => {
            // Surface 422 validation errors (e.g. duplicate name) inline.
            const message = err instanceof Error ? err.message : 'Could not save preset.';
            setError(message);
        },
    });

    const deleteMutation = useMutation({
        mutationFn: (id: number) => chatFilterPresetsApi.delete(id),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ['chat-filter-presets'] });
        },
    });

    // Esc + click-outside to close. Same pattern as FilterPickerPopover.
    useEffect(() => {
        if (!open) return;
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') {
                if (savePromptOpen) {
                    setSavePromptOpen(false);
                    setName('');
                    setError(null);
                } else {
                    setOpen(false);
                }
            }
        };
        const onClick = (e: MouseEvent) => {
            if (dropdownRef.current && !dropdownRef.current.contains(e.target as Node)) {
                setOpen(false);
                setSavePromptOpen(false);
            }
        };
        document.addEventListener('keydown', onKey);
        document.addEventListener('mousedown', onClick, true);
        return () => {
            document.removeEventListener('keydown', onKey);
            document.removeEventListener('mousedown', onClick, true);
        };
    }, [open, savePromptOpen]);

    const presets = presetsQuery.data ?? [];
    const canSave = !isFilterStateEmpty(filters);

    return (
        <div ref={dropdownRef} style={{ position: 'relative' }}>
            <button
                type="button"
                data-testid="chat-filter-presets-trigger"
                aria-label="Saved filter presets"
                aria-expanded={open}
                aria-haspopup="menu"
                onClick={() => setOpen((v) => !v)}
                style={{
                    display: 'inline-flex',
                    alignItems: 'center',
                    gap: 4,
                    padding: '3px 9px',
                    border: '1px solid var(--panel-border, rgba(255,255,255,.30))',
                    borderRadius: 99,
                    background: 'transparent',
                    color: 'var(--fg-2)',
                    cursor: 'pointer',
                    fontSize: 11.5,
                    lineHeight: 1.4,
                }}
            >
                <span aria-hidden="true">★</span>
                Presets
            </button>

            {open && (
                <div
                    role="menu"
                    aria-label="Saved presets"
                    data-testid="chat-filter-presets-menu"
                    style={{
                        position: 'absolute',
                        bottom: '100%',
                        left: 0,
                        marginBottom: 6,
                        minWidth: 240,
                        background: 'var(--panel-solid, #1a1a22)',
                        border: '1px solid var(--panel-border-strong, rgba(255,255,255,.12))',
                        borderRadius: 10,
                        boxShadow: 'var(--shadow, 0 8px 24px rgba(0,0,0,.35))',
                        zIndex: 30,
                        fontSize: 12,
                    }}
                >
                    {!savePromptOpen && (
                        <>
                            <button
                                type="button"
                                role="menuitem"
                                data-testid="chat-filter-presets-save"
                                disabled={!canSave}
                                onClick={() => {
                                    setSavePromptOpen(true);
                                    setError(null);
                                }}
                                style={{
                                    width: '100%',
                                    border: 0,
                                    background: 'transparent',
                                    color: canSave ? 'var(--fg-1)' : 'var(--fg-3)',
                                    textAlign: 'left',
                                    padding: '8px 12px',
                                    cursor: canSave ? 'pointer' : 'not-allowed',
                                    borderBottom: '1px solid var(--panel-border, rgba(255,255,255,.08))',
                                    fontSize: 11.5,
                                }}
                            >
                                + Save current as preset
                            </button>
                            <ul style={{ listStyle: 'none', padding: 4, margin: 0, maxHeight: 240, overflowY: 'auto' }}>
                                {presetsQuery.isLoading && (
                                    <li
                                        data-testid="chat-filter-presets-loading"
                                        style={{ padding: '8px 12px', color: 'var(--fg-3)', fontSize: 11.5 }}
                                    >
                                        Loading…
                                    </li>
                                )}
                                {!presetsQuery.isLoading && presets.length === 0 && (
                                    <li
                                        data-testid="chat-filter-presets-empty"
                                        style={{ padding: '8px 12px', color: 'var(--fg-3)', fontSize: 11.5 }}
                                    >
                                        No saved presets yet.
                                    </li>
                                )}
                                {presets.map((p) => (
                                    <PresetRow
                                        key={p.id}
                                        preset={p}
                                        onLoad={() => {
                                            onLoad(p.filters);
                                            setOpen(false);
                                        }}
                                        onDelete={() => deleteMutation.mutate(p.id)}
                                    />
                                ))}
                            </ul>
                        </>
                    )}
                    {savePromptOpen && (
                        <div data-testid="chat-filter-presets-save-form" style={{ padding: 12 }}>
                            <label
                                htmlFor="chat-filter-presets-name-input"
                                style={{ display: 'flex', flexDirection: 'column', gap: 4, color: 'var(--fg-2)', fontSize: 11 }}
                            >
                                <span>Preset name</span>
                                <input
                                    id="chat-filter-presets-name-input"
                                    data-testid="chat-filter-presets-name-input"
                                    type="text"
                                    value={name}
                                    onChange={(e) => {
                                        setName(e.target.value);
                                        setError(null);
                                    }}
                                    autoFocus
                                    maxLength={120}
                                    placeholder="e.g. HR + PDF only"
                                    style={{
                                        padding: '5px 8px',
                                        borderRadius: 6,
                                        border: '1px solid var(--panel-border, rgba(255,255,255,.15))',
                                        background: 'var(--bg-3, rgba(255,255,255,.04))',
                                        color: 'var(--fg-0)',
                                        fontSize: 12,
                                    }}
                                />
                            </label>
                            {error && (
                                <p
                                    data-testid="chat-filter-presets-save-error"
                                    role="alert"
                                    style={{ marginTop: 6, fontSize: 11, color: 'var(--err)' }}
                                >
                                    {error}
                                </p>
                            )}
                            <div style={{ display: 'flex', gap: 6, marginTop: 8 }}>
                                <button
                                    type="button"
                                    data-testid="chat-filter-presets-save-confirm"
                                    disabled={name.trim() === '' || createMutation.isPending}
                                    onClick={() => {
                                        const trimmed = name.trim();
                                        if (trimmed === '') return;
                                        createMutation.mutate({ name: trimmed, filters });
                                    }}
                                    style={{
                                        padding: '4px 10px',
                                        borderRadius: 6,
                                        border: '1px solid var(--accent, #6366f1)',
                                        background: 'var(--accent, #6366f1)',
                                        color: 'white',
                                        fontSize: 11.5,
                                        cursor: name.trim() === '' ? 'not-allowed' : 'pointer',
                                        opacity: name.trim() === '' ? 0.5 : 1,
                                    }}
                                >
                                    {createMutation.isPending ? 'Saving…' : 'Save'}
                                </button>
                                <button
                                    type="button"
                                    data-testid="chat-filter-presets-save-cancel"
                                    onClick={() => {
                                        setSavePromptOpen(false);
                                        setName('');
                                        setError(null);
                                    }}
                                    style={{
                                        padding: '4px 10px',
                                        borderRadius: 6,
                                        border: '1px solid var(--panel-border, rgba(255,255,255,.15))',
                                        background: 'transparent',
                                        color: 'var(--fg-2)',
                                        fontSize: 11.5,
                                        cursor: 'pointer',
                                    }}
                                >
                                    Cancel
                                </button>
                            </div>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}

interface PresetRowProps {
    preset: ChatFilterPreset;
    onLoad: () => void;
    onDelete: () => void;
}

function PresetRow({ preset, onLoad, onDelete }: PresetRowProps): ReactNode {
    const [confirming, setConfirming] = useState(false);

    return (
        <li
            data-testid={`chat-filter-preset-${preset.id}`}
            data-preset-name={preset.name}
            style={{
                display: 'flex',
                alignItems: 'center',
                gap: 4,
                padding: '4px 8px',
                borderRadius: 6,
            }}
        >
            <button
                type="button"
                role="menuitem"
                data-testid={`chat-filter-preset-${preset.id}-load`}
                onClick={onLoad}
                style={{
                    flex: 1,
                    border: 0,
                    background: 'transparent',
                    color: 'var(--fg-1)',
                    textAlign: 'left',
                    padding: '4px 6px',
                    cursor: 'pointer',
                    fontSize: 11.5,
                    overflow: 'hidden',
                    textOverflow: 'ellipsis',
                    whiteSpace: 'nowrap',
                }}
            >
                {preset.name}
            </button>
            {!confirming && (
                <button
                    type="button"
                    data-testid={`chat-filter-preset-${preset.id}-delete`}
                    aria-label={`Delete preset ${preset.name}`}
                    onClick={() => setConfirming(true)}
                    style={{
                        border: 0,
                        background: 'transparent',
                        color: 'var(--fg-3)',
                        cursor: 'pointer',
                        fontSize: 12,
                        padding: '2px 6px',
                        borderRadius: 4,
                    }}
                >
                    ×
                </button>
            )}
            {confirming && (
                <>
                    <button
                        type="button"
                        data-testid={`chat-filter-preset-${preset.id}-delete-confirm`}
                        onClick={() => {
                            onDelete();
                            setConfirming(false);
                        }}
                        style={{
                            border: '1px solid var(--err, #c4391d)',
                            background: 'var(--err, #c4391d)',
                            color: 'white',
                            cursor: 'pointer',
                            fontSize: 10.5,
                            padding: '2px 6px',
                            borderRadius: 4,
                        }}
                    >
                        Delete
                    </button>
                    <button
                        type="button"
                        data-testid={`chat-filter-preset-${preset.id}-delete-cancel`}
                        onClick={() => setConfirming(false)}
                        style={{
                            border: '1px solid var(--panel-border, rgba(255,255,255,.15))',
                            background: 'transparent',
                            color: 'var(--fg-2)',
                            cursor: 'pointer',
                            fontSize: 10.5,
                            padding: '2px 6px',
                            borderRadius: 4,
                        }}
                    >
                        Cancel
                    </button>
                </>
            )}
        </li>
    );
}
