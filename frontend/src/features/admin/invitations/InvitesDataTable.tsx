import type { ReactNode } from 'react';
import { READ_ROW_CAP } from './invitations.api';
import type { ListState } from './format';

/*
 * Column-driven table shared by every read tab (referrals / rewards / waitlist
 * / anti-abuse) and the codes inventory. It owns the four-state machine
 * (loading / empty / error / ready) on `data-state` plus the honest
 * truncation notice when the server cap is hit (R3/R14). Each tab supplies its
 * own column set; the chrome, a11y and testids stay uniform (R11/R15/R29).
 */

export interface Column<T> {
    key: string;
    header: string;
    align?: 'left' | 'right' | 'center';
    render: (row: T) => ReactNode;
}

export interface InvitesDataTableProps<T> {
    /** Base testid, e.g. `admin-invitations-referrals`. */
    testid: string;
    /** Accessible name for the <table>. */
    ariaLabel: string;
    state: ListState;
    rows: T[];
    columns: Column<T>[];
    getRowId: (row: T) => string | number;
    /** True when rows.length hit the server row cap (likely truncated). */
    capped?: boolean;
    emptyLabel?: string;
    errorLabel?: string;
}

const cell = (align: Column<unknown>['align']): React.CSSProperties => ({
    padding: '8px 10px',
    textAlign: align ?? 'left',
    verticalAlign: 'middle',
});

export function InvitesDataTable<T>({
    testid,
    ariaLabel,
    state,
    rows,
    columns,
    getRowId,
    capped = false,
    emptyLabel = 'Nothing here yet.',
    errorLabel = "Couldn't load — check that you're signed in and the invitations API is reachable.",
}: InvitesDataTableProps<T>) {
    return (
        <div data-testid={testid} data-state={state} aria-busy={state === 'loading'}>
            {state === 'loading' && (
                <p data-testid={`${testid}-loading`} data-state="loading" style={{ color: 'var(--fg-3)', padding: 16 }}>
                    <span className="shimmer">Loading…</span>
                </p>
            )}

            {state === 'error' && (
                <p
                    data-testid={`${testid}-error`}
                    role="alert"
                    style={{ color: 'var(--danger-fg, #f87171)', padding: 16, fontSize: 13 }}
                >
                    {errorLabel}
                </p>
            )}

            {state === 'empty' && (
                <p
                    data-testid={`${testid}-empty`}
                    data-state="empty"
                    style={{
                        color: 'var(--fg-3)',
                        padding: 24,
                        textAlign: 'center',
                        border: '1px dashed var(--panel-border, rgba(255,255,255,.12))',
                        borderRadius: 8,
                    }}
                >
                    {emptyLabel}
                </p>
            )}

            {state === 'ready' && (
                <>
                    {capped && (
                        <p
                            data-testid={`${testid}-capped`}
                            role="status"
                            style={{ margin: '0 0 8px', fontSize: 12, color: 'var(--fg-3)' }}
                        >
                            Showing the first {READ_ROW_CAP} rows — refine the filters to narrow the result.
                        </p>
                    )}
                    <table
                        data-testid={`${testid}-table`}
                        aria-label={ariaLabel}
                        style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12.5 }}
                    >
                        <thead>
                            <tr
                                style={{
                                    textAlign: 'left',
                                    color: 'var(--fg-2)',
                                    fontSize: 11,
                                    textTransform: 'uppercase',
                                    letterSpacing: '.04em',
                                }}
                            >
                                {columns.map((col) => (
                                    <th key={col.key} style={cell(col.align)} scope="col">
                                        {col.header}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((row) => {
                                const id = getRowId(row);
                                return (
                                    <tr
                                        key={id}
                                        data-testid={`${testid}-row-${id}`}
                                        style={{ borderTop: '1px solid var(--panel-border, rgba(255,255,255,.06))' }}
                                    >
                                        {columns.map((col) => (
                                            <td key={col.key} style={cell(col.align)}>
                                                {col.render(row)}
                                            </td>
                                        ))}
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </>
            )}
        </div>
    );
}
