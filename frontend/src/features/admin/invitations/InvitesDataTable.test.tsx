import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { InvitesDataTable, type Column } from './InvitesDataTable';

interface Row {
    id: number;
    name: string;
}

const COLUMNS: Column<Row>[] = [{ key: 'name', header: 'Name', render: (r) => r.name }];
const ROWS: Row[] = [
    { id: 1, name: 'alpha' },
    { id: 2, name: 'beta' },
];

function renderTable(props: Partial<React.ComponentProps<typeof InvitesDataTable<Row>>> = {}) {
    return render(
        <InvitesDataTable<Row>
            testid="t"
            ariaLabel="Test table"
            state="ready"
            rows={ROWS}
            columns={COLUMNS}
            getRowId={(r) => r.id}
            {...props}
        />,
    );
}

describe('InvitesDataTable', () => {
    it('shows the loading branch', () => {
        renderTable({ state: 'loading', rows: [] });
        expect(screen.getByTestId('t-loading')).toHaveAttribute('data-state', 'loading');
    });

    it('shows the error branch as an alert (R14)', () => {
        renderTable({ state: 'error', rows: [] });
        const err = screen.getByTestId('t-error');
        expect(err).toHaveAttribute('role', 'alert');
    });

    it('shows the empty branch', () => {
        renderTable({ state: 'empty', rows: [] });
        expect(screen.getByTestId('t-empty')).toHaveAttribute('data-state', 'empty');
    });

    it('renders one row per item in the ready branch, no cap notice by default', () => {
        renderTable();
        expect(screen.getByTestId('t-table')).toBeVisible();
        expect(screen.getByTestId('t-row-1')).toBeVisible();
        expect(screen.getByTestId('t-row-2')).toBeVisible();
        expect(screen.queryByTestId('t-capped')).not.toBeInTheDocument();
    });

    it('surfaces the truncation notice when the server cap is hit (R3/R14)', () => {
        renderTable({ capped: true });
        const notice = screen.getByTestId('t-capped');
        expect(notice).toBeVisible();
        expect(notice).toHaveTextContent(/first 500 rows/i);
    });
});
