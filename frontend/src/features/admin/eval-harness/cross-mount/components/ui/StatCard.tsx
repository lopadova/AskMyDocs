/*
 * Cross-mount port of `vendor/padosoft/eval-harness-ui/resources/js/
 * components/ui/StatCard.tsx`. Class names rebadged to the
 * cross-mount-scoped equivalents (`ehu-panel`, `ehu-rounded`,
 * `ehu-screen-title`, `ehu-screen-subtitle`).
 */
import type { ReactNode } from 'react';

const StatCard = ({ label, value, helper }: { label: string; value: ReactNode; helper?: ReactNode }) => (
  <article className="ehu-panel ehu-rounded">
    <p className="ehu-screen-subtitle">{label}</p>
    <p className="mt-2 text-2xl font-semibold">{value}</p>
    {helper ? <p className="ehu-screen-subtitle mt-1">{helper}</p> : null}
  </article>
);

export default StatCard;
