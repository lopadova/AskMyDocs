/*
 * Cross-mount port of `vendor/padosoft/eval-harness-ui/resources/js/
 * components/ui/DataTable.tsx`.
 *
 * v4.4 GA Copilot iter 1 fix — the empty-state message used to be a
 * hard-coded Italian literal (`Nessun elemento disponibile`) which
 * rendered untranslated under the `en` locale. The component now
 * resolves the message through the cross-mount i18n catalogue
 * (`table_empty` key), and callers can override per-table via the
 * optional `emptyLabel` prop when a more specific copy fits the
 * domain (e.g. "No reports found").
 */
import { type ReactNode } from 'react';
import { useI18n } from '../../hooks/useI18n';

type Column<T> = {
  key: keyof T | 'actions' | (string & {});
  label: string;
  render?: (item: T) => ReactNode;
};

const DataTable = <T extends object>({
  columns,
  rows,
  rowKey,
  emptyLabel,
}: {
  columns: Column<T>[];
  rows: T[];
  rowKey: (item: T, index: number) => string;
  emptyLabel?: string;
}) => {
  const { t } = useI18n();
  const resolvedEmptyLabel = emptyLabel ?? t('table_empty', 'No items available');

  return (
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
                {resolvedEmptyLabel}
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
};

export default DataTable;
