/*
 * Cross-mount port of `vendor/padosoft/eval-harness-ui/resources/js/
 * components/ui/StatusTag.tsx`. Class names rebadged to the
 * cross-mount-scoped equivalents.
 */
const StatusTag = ({ status, label }: { status: 'improved' | 'regressed' | 'stable' | string; label?: string }) => {
  const text = label ?? status;

  if (status === 'improved') {
    return <span className="ehu-status-ok ehu-rounded px-2 py-1 text-xs font-semibold">{text}</span>;
  }

  if (status === 'regressed') {
    return <span className="ehu-status-danger ehu-rounded px-2 py-1 text-xs font-semibold">{text}</span>;
  }

  return <span className="ehu-status-warn ehu-rounded px-2 py-1 text-xs font-semibold">{text}</span>;
};

export default StatusTag;
