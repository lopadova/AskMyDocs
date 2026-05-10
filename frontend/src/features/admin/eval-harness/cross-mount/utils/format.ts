/*
 * Cross-mount port of `vendor/padosoft/eval-harness-ui/resources/js/
 * utils/format.ts`. Verbatim copy.
 */
export const formatDateTime = (value: string): string => {
  const parsed = new Date(value);

  if (Number.isNaN(parsed.getTime())) {
    return value;
  }

  return new Intl.DateTimeFormat('it-IT', {
    dateStyle: 'short',
    timeStyle: 'short',
  }).format(parsed);
};

export const formatPercent = (value: number): string => {
  if (Number.isNaN(value)) {
    return '—';
  }

  return `${(value * 100).toFixed(1)}%`;
};

export const formatNumber = (value: number): string => new Intl.NumberFormat('it-IT').format(value);
