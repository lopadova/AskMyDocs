/*
 * Cross-mount port of `vendor/padosoft/eval-harness-ui/resources/js/
 * components/ui/ErrorPanel.tsx`. Two material differences vs upstream:
 *
 *   1. Relative-path import for `ApiErrorState`.
 *   2. `role="alert"` + `data-testid="ehu-error-panel"` added so the
 *      panel is announced to assistive tech (R15) AND deterministically
 *      assertable in vitest / Playwright (R11).
 *
 * R7 / R14: failures rendered LOUDLY — no silent swallow.
 */
import type { ApiErrorState } from '../../types/api';

const ErrorPanel = ({ error }: { error: ApiErrorState }) => (
  <div
    className="ehu-rounded border border-rose-200 bg-rose-50 px-4 py-3 text-rose-800"
    role="alert"
    data-testid="ehu-error-panel"
  >
    <p className="text-sm font-semibold uppercase tracking-wide text-rose-900">{error.kind}</p>
    <p className="mt-1 text-sm text-rose-800">{error.message}</p>
  </div>
);

export default ErrorPanel;
