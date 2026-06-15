import { useState, type ReactNode } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { adminKbApi } from '../admin.api';
import { lintWiki, fixWiki } from './wiki-health.api';

/**
 * v8.11/P10 — Wiki Health: the deterministic lint report for a project's
 * Auto-Wiki graph (dangling / orphan / stale cross-refs / missing index) with a
 * one-click safe auto-fix (prune leftover dangling nodes).
 *
 * R11 testids + observable data-state · R14 distinct loading/empty/error states
 * + loud mutation errors · R15 labelled controls · R18 project options derived
 * from the DB (`GET /api/admin/kb/projects`), never a literal subset.
 */
export function WikiHealthView(): ReactNode {
    const qc = useQueryClient();
    const [project, setProject] = useState<string>('');
    const [actionError, setActionError] = useState<string | null>(null);
    const [fixNote, setFixNote] = useState<string | null>(null);

    const projectsQuery = useQuery({
        queryKey: ['admin-kb-projects'],
        queryFn: () => adminKbApi.projects(),
        staleTime: 60_000,
    });

    const lintQuery = useQuery({
        queryKey: ['admin-wiki-lint', project],
        queryFn: () => lintWiki(project),
        enabled: project !== '',
        staleTime: 10_000,
    });

    const fixMutation = useMutation({
        mutationFn: () => fixWiki(project),
        onSuccess: (res) => {
            setActionError(null);
            setFixNote(`Pruned ${res.pruned_dangling} leftover dangling node(s).`);
            qc.invalidateQueries({ queryKey: ['admin-wiki-lint', project] });
        },
        onError: (err: unknown) => {
            setFixNote(null);
            setActionError(err instanceof Error ? err.message : 'Could not apply the auto-fix.');
        },
    });

    const report = lintQuery.data;
    const rootState =
        project === '' ? 'idle'
            : lintQuery.isLoading ? 'loading'
                : lintQuery.isError ? 'error'
                    : report?.healthy ? 'empty'
                        : 'ready';

    return (
        <div
            data-testid="admin-wiki-health-view"
            data-state={rootState}
            aria-busy={lintQuery.isLoading || lintQuery.isFetching || fixMutation.isPending}
            style={{ padding: 24 }}
        >
            <header style={{ display: 'flex', alignItems: 'baseline', gap: 12, flexWrap: 'wrap', marginBottom: 8 }}>
                <h1 style={{ margin: 0, fontSize: 18, color: 'var(--fg-0)' }}>Wiki Health</h1>
                <span style={{ flex: 1 }} />
                <label style={{ fontSize: 11.5, color: 'var(--fg-3)', display: 'flex', gap: 6, alignItems: 'center' }}>
                    Project
                    <select
                        data-testid="admin-wiki-health-project"
                        aria-label="Select project to lint"
                        value={project}
                        onChange={(e) => { setProject(e.target.value); setActionError(null); setFixNote(null); }}
                        style={{ fontSize: 11.5, padding: '2px 4px' }}
                    >
                        <option value="">Select a project…</option>
                        {(projectsQuery.data?.projects ?? []).map((p) => (
                            <option key={p} value={p}>{p}</option>
                        ))}
                    </select>
                </label>
            </header>
            <p style={{ margin: '0 0 16px', color: 'var(--fg-3)', fontSize: 11.5, maxWidth: 680 }}>
                Structural health of the Auto-Wiki graph: <strong>dangling</strong> targets with no
                owning doc, <strong>orphan</strong> pages nothing links to, <strong>stale</strong>{' '}
                cross-references to deprecated/deleted docs, and a <strong>missing index</strong>. The
                safe auto-fix only prunes leftover dangling nodes; everything else is reported for review.
            </p>

            {actionError && (
                <p data-testid="admin-wiki-health-action-error" role="alert" style={{ color: 'var(--err)', fontSize: 12, marginBottom: 8 }}>
                    {actionError}
                </p>
            )}
            {fixNote && (
                <p data-testid="admin-wiki-health-fix-note" role="status" style={{ color: 'var(--ok, #3fb950)', fontSize: 12, marginBottom: 8 }}>
                    {fixNote}
                </p>
            )}

            {project === '' && (
                <p data-testid="admin-wiki-health-idle" data-state="idle" style={{ color: 'var(--fg-3)', padding: 24, textAlign: 'center', border: '1px dashed var(--panel-border)', borderRadius: 8 }}>
                    Select a project to run the wiki health lint.
                </p>
            )}
            {project !== '' && lintQuery.isLoading && (
                <p data-testid="admin-wiki-health-loading" data-state="loading" style={{ color: 'var(--fg-3)' }}>Linting…</p>
            )}
            {project !== '' && !lintQuery.isLoading && lintQuery.isError && (
                <p data-testid="admin-wiki-health-error" data-state="error" role="alert" style={{ color: 'var(--err)', padding: 24, textAlign: 'center', border: '1px dashed var(--err)', borderRadius: 8 }}>
                    Failed to lint the wiki. {lintQuery.error instanceof Error ? lintQuery.error.message : ''}
                </p>
            )}
            {report?.healthy && (
                <p data-testid="admin-wiki-health-empty" data-state="empty" style={{ color: 'var(--ok, #3fb950)', padding: 24, textAlign: 'center', border: '1px dashed var(--panel-border)', borderRadius: 8 }}>
                    ✓ Wiki is healthy — no dangling, orphan, stale, or missing-index issues.
                </p>
            )}

            {report && !report.healthy && (
                <div data-testid="admin-wiki-health-report" data-state="ready">
                    <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginBottom: 16 }}>
                        <Stat testid="admin-wiki-health-count-dangling" label="Dangling" value={report.counts.dangling} />
                        <Stat testid="admin-wiki-health-count-orphan" label="Orphan" value={report.counts.orphan} />
                        <Stat testid="admin-wiki-health-count-stale" label="Stale refs" value={report.counts.stale_cross_ref} />
                        <Stat testid="admin-wiki-health-count-missing-index" label="Missing index" value={report.findings.missing_index ? 1 : 0} />
                        <span style={{ flex: 1 }} />
                        <button
                            type="button"
                            data-testid="admin-wiki-health-fix"
                            onClick={() => fixMutation.mutate()}
                            disabled={fixMutation.isPending || report.counts.dangling === 0}
                            aria-label="Apply safe auto-fix (prune leftover dangling nodes)"
                            style={{ padding: '4px 12px', borderRadius: 6, fontSize: 12, cursor: report.counts.dangling === 0 ? 'not-allowed' : 'pointer', border: '1px solid var(--accent, #6366f1)', color: 'var(--accent, #6366f1)', background: 'transparent' }}
                        >
                            {fixMutation.isPending ? 'Fixing…' : 'Auto-fix dangling'}
                        </button>
                    </div>

                    <FindingList testid="admin-wiki-health-dangling" title="Dangling nodes" items={report.findings.dangling} />
                    <FindingList testid="admin-wiki-health-orphan" title="Orphan pages" items={report.findings.orphan} />
                    <FindingList
                        testid="admin-wiki-health-stale"
                        title="Stale cross-references"
                        items={report.findings.stale_cross_ref.map((s) => `${s.edge} → ${s.target} (${s.reason})`)}
                    />
                    {report.findings.missing_index && (
                        <p data-testid="admin-wiki-health-missing-index" style={{ fontSize: 12, color: 'var(--fg-3)', marginTop: 8 }}>
                            ⚠ This project has pages but no index — run a wiki-index rebuild.
                        </p>
                    )}
                </div>
            )}
        </div>
    );
}

function Stat({ testid, label, value }: { testid: string; label: string; value: number }): ReactNode {
    return (
        <span data-testid={testid} style={{ fontSize: 12, color: value > 0 ? 'var(--err)' : 'var(--fg-3)', border: '1px solid var(--panel-border)', borderRadius: 6, padding: '3px 8px' }}>
            {label}: <strong>{value}</strong>
        </span>
    );
}

function FindingList({ testid, title, items }: { testid: string; title: string; items: string[] }): ReactNode {
    if (items.length === 0) {
        return null;
    }
    return (
        <section data-testid={testid} style={{ marginBottom: 12 }}>
            <h2 style={{ fontSize: 13, color: 'var(--fg-0)', margin: '0 0 4px' }}>{title} ({items.length})</h2>
            <ul style={{ margin: 0, paddingLeft: 18, color: 'var(--fg-2)', fontSize: 12 }}>
                {items.map((item) => (
                    <li key={item} data-testid={`${testid}-item`}>{item}</li>
                ))}
            </ul>
        </section>
    );
}
