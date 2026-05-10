/*
 * Cross-mount port of `vendor/padosoft/eval-harness-ui/resources/js/
 * components/ui/DataTable.tsx`. Verbatim copy.
 */
import { type ReactNode } from 'react';

type Column<T> = {
  key: keyof T | 'actions' | (string & {});
  label: string;
  render?: (item: T) => ReactNode;
};

const DataTable = <T extends object>({
  columns,
  rows,
  rowKey,
}: {
  columns: Column<T>[];
  rows: T[];
  rowKey: (item: T, index: number) => string;
}) => (
  <div className="overflow-x-auto">
    <table className="min-w-full divide-y divide-slate-200">
      <thead>
        <tr className="text-left text-xs text-slate-500">
          {columns.map((col) => (
            <th key={String(col.key)} className="px-3 py-2 font-semibold">
              {col.label}
            </th>
          ))}
        </tr>
      </thead>
      <tbody className="divide-y divide-slate-100">
        {rows.length === 0 ? (
          <tr>
            <td className="px-3 py-4 text-sm text-slate-500" colSpan={columns.length}>
              Nessun elemento disponibile
            </td>
          </tr>
        ) : (
          rows.map((row, index) => (
            <tr key={rowKey(row, index)} className="text-sm">
              {columns.map((col) => {
                const value = col.key === 'actions' ? null : ((row as Record<string, unknown>)[String(col.key)] as unknown);

                return (
                  <td key={`${rowKey(row, index)}-${String(col.key)}`} className="px-3 py-2">
                    {col.render ? col.render(row) : String(value ?? '')}
                  </td>
                );
              })}
            </tr>
          ))
        )}
      </tbody>
    </table>
  </div>
);

export default DataTable;
