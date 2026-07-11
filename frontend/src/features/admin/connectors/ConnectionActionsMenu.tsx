import { useEffect, useRef, useState } from 'react';
import type { ConnectionVM } from './connection-vm';

/*
 * The per-connection "⋮ more actions" dropdown, shared by the table and cards
 * views. Which items appear depends on the account status + whether the source
 * is a credential connector (IMAP), mirroring the old per-account card:
 *
 *   pending  → Cancel install
 *   disabled → Enable · Test fetch* · Folders* · Edit · Export* · Remove
 *   active   → Test fetch* · Folders* · Edit · Export* · Disable · Remove
 *   errored  → (same as active; the inline icon offers "Retry sync")
 *     (* credential connectors only)
 *
 * "Sync now / Retry sync" is NOT in this menu — it's the inline icon/button the
 * table row and card render directly (matching the design handoff).
 *
 * Remove is a two-step confirm inside the menu (R: admin-ajax destructive
 * confirmation): the item flips to "Confirm remove" + "Cancel" without closing.
 *
 * R15/R29 — the trigger + every item are real <button>s (keyboard reachable),
 * carry a hierarchical `connector-connection-{id}-{action}` testid, and the
 * dropdown is a `role="menu"` of `role="menuitem"`s; Escape closes it.
 */

export interface ConnectionActionsMenuProps {
    vm: ConnectionVM;
    isOpen: boolean;
    /** Toggle this connection's menu (the parent owns the single open-menu id). */
    onToggle: () => void;
    onClose: () => void;
    onTestFetch: (vm: ConnectionVM) => void;
    onEdit: (vm: ConnectionVM) => void;
    onFolders: (vm: ConnectionVM) => void;
    onExport: (vm: ConnectionVM) => void;
    onDisable: (id: number) => void;
    onEnable: (id: number) => void;
    onRemove: (id: number) => void;
    onCancelInstall: (id: number) => void;
    /** A write action (disable/enable/remove/cancel/sync) is in flight. */
    writeLocked: boolean;
    /** The enable specifically is in flight (only Enable shows "Enabling…"). */
    enabling: boolean;
    /** A disable/remove/cancel is in flight (labels "Cancelling…"). */
    busy: boolean;
    /** The read-only export download is in flight. */
    exporting: boolean;
    /** The read-only test-fetch probe is in flight. */
    probing: boolean;
}

interface MenuItem {
    key: string;
    testid: string;
    label: string;
    danger?: boolean;
    disabled?: boolean;
    onSelect: () => void;
}

export function ConnectionActionsMenu({
    vm,
    isOpen,
    onToggle,
    onClose,
    onTestFetch,
    onEdit,
    onFolders,
    onExport,
    onDisable,
    onEnable,
    onRemove,
    onCancelInstall,
    writeLocked,
    enabling,
    busy,
    exporting,
    probing,
}: ConnectionActionsMenuProps) {
    const [confirmingRemove, setConfirmingRemove] = useState(false);
    const menuRef = useRef<HTMLDivElement>(null);

    // Reset the destructive-confirm whenever the menu closes so re-opening starts
    // clean (never lands mid-confirm). Also focus the first item on open (R15).
    useEffect(() => {
        if (!isOpen) {
            setConfirmingRemove(false);
            return;
        }
        const first = menuRef.current?.querySelector<HTMLButtonElement>('[role="menuitem"]');
        first?.focus();
    }, [isOpen]);

    const { id, status, isCredential } = vm;
    const base = `connector-connection-${id}`;

    function withClose(fn: () => void): () => void {
        return () => {
            onClose();
            fn();
        };
    }

    const items: MenuItem[] = [];

    if (status === 'pending') {
        items.push({
            key: 'cancel',
            testid: `${base}-cancel-install`,
            label: busy ? 'Cancelling…' : 'Cancel install',
            disabled: writeLocked,
            onSelect: withClose(() => onCancelInstall(id)),
        });
    } else {
        if (status === 'disabled') {
            items.push({
                key: 'enable',
                testid: `${base}-enable`,
                label: enabling ? 'Enabling…' : 'Enable',
                disabled: writeLocked,
                onSelect: withClose(() => onEnable(id)),
            });
        }

        // Read-only diagnostic — credential connectors (IMAP) only.
        if (isCredential) {
            items.push({
                key: 'test-fetch',
                testid: `${base}-test-fetch`,
                label: probing ? 'Fetching…' : 'Test fetch',
                disabled: probing,
                onSelect: withClose(() => onTestFetch(vm)),
            });
        }

        items.push({
            key: 'edit',
            testid: `${base}-edit`,
            label: 'Edit',
            disabled: writeLocked,
            onSelect: withClose(() => onEdit(vm)),
        });

        if (isCredential) {
            items.push({
                key: 'folders',
                testid: `${base}-folders`,
                label: 'Folders',
                disabled: writeLocked,
                onSelect: withClose(() => onFolders(vm)),
            });
            items.push({
                key: 'export',
                testid: `${base}-export`,
                label: exporting ? 'Exporting…' : 'Export',
                disabled: exporting,
                onSelect: withClose(() => onExport(vm)),
            });
        }

        if (status === 'active' || status === 'errored') {
            items.push({
                key: 'disable',
                testid: `${base}-disable`,
                label: 'Disable',
                disabled: writeLocked,
                onSelect: withClose(() => onDisable(id)),
            });
        }
    }

    return (
        <div style={{ position: 'relative', display: 'inline-flex' }}>
            <button
                type="button"
                data-testid={`${base}-menu`}
                className="amd-cn-menu-btn focus-ring"
                aria-label="More actions"
                aria-haspopup="menu"
                aria-expanded={isOpen}
                onClick={(e) => {
                    e.stopPropagation();
                    onToggle();
                }}
                style={{
                    width: 30,
                    height: 30,
                    borderRadius: 8,
                    border: '1px solid transparent',
                    background: 'transparent',
                    color: 'var(--fg-3)',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    cursor: 'pointer',
                }}
            >
                <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <circle cx="12" cy="5" r="1.6" />
                    <circle cx="12" cy="12" r="1.6" />
                    <circle cx="12" cy="19" r="1.6" />
                </svg>
            </button>

            {isOpen && (
                <div
                    ref={menuRef}
                    role="menu"
                    aria-label={`Actions for ${vm.account}`}
                    data-testid={`${base}-menu-panel`}
                    onClick={(e) => e.stopPropagation()}
                    onKeyDown={(e) => {
                        if (e.key === 'Escape') {
                            e.stopPropagation();
                            onClose();
                        }
                    }}
                    style={{
                        position: 'absolute',
                        top: 'calc(100% + 6px)',
                        right: 0,
                        width: 190,
                        background: 'var(--panel-solid)',
                        border: '1px solid var(--panel-border-strong)',
                        borderRadius: 11,
                        boxShadow: 'var(--shadow-lg)',
                        padding: 6,
                        zIndex: 40,
                        animation: 'amd-cn-pop .14s ease both',
                    }}
                >
                    {!confirmingRemove &&
                        items.map((item) => (
                            <button
                                key={item.key}
                                type="button"
                                role="menuitem"
                                data-testid={item.testid}
                                className={`amd-cn-menu-item focus-ring${item.danger ? ' danger' : ''}`}
                                disabled={item.disabled}
                                onClick={(e) => {
                                    e.stopPropagation();
                                    item.onSelect();
                                }}
                                style={menuItemStyle(item.danger)}
                            >
                                {item.label}
                            </button>
                        ))}

                    {/* Remove is always the last, destructive, two-step item. */}
                    {status !== 'pending' && !confirmingRemove && (
                        <button
                            type="button"
                            role="menuitem"
                            data-testid={`${base}-remove`}
                            className="amd-cn-menu-item danger focus-ring"
                            disabled={writeLocked}
                            onClick={(e) => {
                                e.stopPropagation();
                                setConfirmingRemove(true);
                            }}
                            style={menuItemStyle(true)}
                        >
                            Remove
                        </button>
                    )}

                    {confirmingRemove && (
                        <>
                            <button
                                type="button"
                                role="menuitem"
                                data-testid={`${base}-remove-confirm`}
                                className="amd-cn-menu-item danger focus-ring"
                                disabled={writeLocked}
                                onClick={(e) => {
                                    e.stopPropagation();
                                    onClose();
                                    onRemove(id);
                                }}
                                style={menuItemStyle(true)}
                            >
                                Confirm remove
                            </button>
                            <button
                                type="button"
                                role="menuitem"
                                data-testid={`${base}-remove-cancel`}
                                className="amd-cn-menu-item focus-ring"
                                onClick={(e) => {
                                    e.stopPropagation();
                                    setConfirmingRemove(false);
                                }}
                                style={menuItemStyle(false)}
                            >
                                Cancel
                            </button>
                        </>
                    )}
                </div>
            )}
        </div>
    );
}

function menuItemStyle(danger?: boolean): React.CSSProperties {
    return {
        width: '100%',
        display: 'flex',
        alignItems: 'center',
        gap: 10,
        background: 'transparent',
        border: 'none',
        font: 'inherit',
        fontSize: 13,
        color: danger ? '#f87171' : 'var(--fg-1)',
        padding: '8px 10px',
        borderRadius: 7,
        cursor: 'pointer',
        textAlign: 'left',
    };
}
