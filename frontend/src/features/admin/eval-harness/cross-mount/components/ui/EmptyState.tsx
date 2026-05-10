/*
 * Cross-mount port of `vendor/padosoft/eval-harness-ui/resources/js/
 * components/ui/EmptyState.tsx`. Verbatim copy.
 */
const EmptyState = ({ title, children }: { title: string; children: React.ReactNode }) => (
  <div className="ehu-panel ehu-rounded border-dashed text-slate-600" data-testid="ehu-empty-state">
    <h3 className="ehu-screen-title">{title}</h3>
    <p className="ehu-screen-subtitle mt-1">{children}</p>
  </div>
);

export default EmptyState;
