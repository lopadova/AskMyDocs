/*
 * PiiRedactorAdminApp — cross-mount port of the
 * `padosoft/laravel-pii-redactor-admin` v1.0.2 SPA.
 *
 * v4.4/W2 — vendored from
 * `vendor/padosoft/laravel-pii-redactor-admin/resources/js/app.tsx`.
 *
 * Three material differences vs the upstream `app.tsx`:
 *
 *   1. The bottom-of-file `createRoot(...).render(<App />)` is REMOVED.
 *      The host's TanStack Router mounts <PiiRedactorAdminApp /> via
 *      <PiiRedactorView /> instead — sharing the host's React +
 *      ReactDOM + Sanctum cookie + axios instance instead of running
 *      a second React tree inside an iframe.
 *
 *   2. `getAdminConfig()` is REMOVED. The cross-mount caller passes
 *      the resolved config payload as a `config` prop. We no longer
 *      read from a `window.PII_REDACTOR_ADMIN` global — that global
 *      only exists on the package's standalone blade shell, which the
 *      host bypasses entirely.
 *
 *   3. The local `dark` state + `document.documentElement.dataset.theme
 *      = ...` effect is REMOVED. The host already drives `data-theme`
 *      on `<html>` (see `frontend/src/components/shell/hooks.ts`); the
 *      cross-mounted SPA inherits the host theme automatically. The
 *      Sun/Moon toggle in the top-right is dropped along with it —
 *      operators use the host's existing theme switcher.
 *
 * Everything else — the page enum, hooks-driven navigation, the 8
 * sub-views (Overview / Playground / Audit / Tokens / Detokenise /
 * Detectors / Custom rules / Settings), the `pra-*` className contract
 * — is preserved verbatim so the rendered output is byte-identical to
 * the iframe predecessor (modulo theme inheritance).
 *
 * R7/R14: API errors bubble through `AdminApiError` and surface as
 * `<InlineNotice tone="danger">` + `<div className="pra-alert">` —
 * never silently swallowed.
 *
 * R11: every interactive element + observable async state carries a
 * `data-testid` derived from the `feature-resource-{id}-{action}`
 * convention (R29).
 */
import React, { useEffect, useMemo, useState } from 'react';
import {
    Activity,
    Database,
    EyeOff,
    FileSearch,
    Filter,
    Gauge,
    KeyRound,
    ListChecks,
    LoaderCircle,
    PackageCheck,
    RotateCcw,
    Search,
    Settings,
    ShieldAlert,
} from 'lucide-react';
import { AdminApiError, adminFetch, buildAdminQuery } from './adminApi';
import type { DataRow, Page, PiiRedactorAdminAbilities, PiiRedactorAdminConfig, StatusPayload } from './types';

const nav: Array<{ page: Page; label: string; icon: React.ComponentType<{ size?: number }> }> = [
    { page: 'overview', label: 'Overview', icon: Gauge },
    { page: 'playground', label: 'Playground', icon: FileSearch },
    { page: 'audit', label: 'Audit log', icon: ListChecks },
    { page: 'tokens', label: 'Token map', icon: Database },
    { page: 'detokenise', label: 'Detokenise', icon: ShieldAlert },
    { page: 'detectors', label: 'Detectors', icon: PackageCheck },
    { page: 'custom-rules', label: 'Custom rules', icon: Activity },
    { page: 'settings', label: 'Settings', icon: Settings },
];

export default function PiiRedactorAdminApp({ config }: { config: PiiRedactorAdminConfig }) {
    const [page, setPage] = useState<Page>('overview');
    const [status, setStatus] = useState<StatusPayload | null>(null);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        let active = true;
        adminFetch<StatusPayload>(config.apiBase, 'status')
            .then((payload) => {
                if (active) {
                    setStatus(payload);
                }
            })
            .catch((cause) => {
                if (active) {
                    setError(errorMessage(cause));
                }
            });
        return () => {
            active = false;
        };
    }, [config.apiBase]);

    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
                event.preventDefault();
                setPage('playground');
            }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, []);

    return (
        <div
            className="pra-shell"
            data-testid="admin-pii-redactor-app"
            data-page={page}
            data-status-state={status === null ? (error ? 'error' : 'loading') : 'ready'}
        >
            <aside className="pra-sidebar" aria-label="PII Redactor sections">
                <div className="pra-brand">
                    <div className="pra-mark"><KeyRound size={18} /></div>
                    <div>
                        <strong>PII Redactor</strong>
                        <span>Admin console</span>
                    </div>
                </div>
                <nav className="pra-nav">
                    {nav.map(({ page: item, label, icon: Icon }) => (
                        <button
                            key={item}
                            type="button"
                            className={page === item ? 'is-active' : ''}
                            data-testid={`admin-pii-redactor-nav-${item}`}
                            aria-current={page === item ? 'page' : undefined}
                            onClick={() => setPage(item)}
                        >
                            <Icon size={16} /> {label}
                        </button>
                    ))}
                </nav>
            </aside>
            <main className="pra-main">
                <header className="pra-topbar">
                    <div>
                        <h1>{nav.find((item) => item.page === page)?.label}</h1>
                        <p>{config.userDisplay} · safe operational access</p>
                    </div>
                    <div className="pra-actions">
                        <button
                            type="button"
                            title="Open playground"
                            aria-label="Open playground"
                            aria-keyshortcuts="Control+K"
                            data-testid="admin-pii-redactor-shortcut-playground"
                            onClick={() => setPage('playground')}
                        >
                            <Search size={16} aria-hidden="true" /> Ctrl K
                        </button>
                    </div>
                </header>
                {error && (
                    <div className="pra-alert" role="alert" data-testid="admin-pii-redactor-status-error">
                        {error}
                    </div>
                )}
                <PageView
                    page={page}
                    status={status}
                    statusError={error}
                    abilities={config.abilities}
                    apiBase={config.apiBase}
                />
            </main>
        </div>
    );
}

function PageView({
    page,
    status,
    statusError,
    abilities,
    apiBase,
}: {
    page: Page;
    status: StatusPayload | null;
    statusError: string | null;
    abilities: PiiRedactorAdminAbilities;
    apiBase: string;
}) {
    if (page === 'playground') {
        return (
            <Playground
                apiBase={apiBase}
                abilities={abilities}
                strategies={status?.strategies ?? ['mask', 'hash', 'tokenise', 'drop']}
            />
        );
    }
    if (page === 'detokenise') {
        return <Detokenise apiBase={apiBase} abilities={abilities} />;
    }
    if (page === 'tokens') {
        return (
            <DataBrowser
                apiBase={apiBase}
                kind="tokens"
                endpoint="token-maps"
                rootKey="maps"
                empty="Token metadata is unavailable for this token-store driver."
            />
        );
    }
    if (page === 'audit') {
        return (
            <DataBrowser
                apiBase={apiBase}
                kind="audit"
                endpoint="audit-events"
                rootKey="data"
                empty="No admin audit events yet."
            />
        );
    }
    if (page === 'detectors') {
        return <Detectors apiBase={apiBase} />;
    }
    if (page === 'custom-rules') {
        return <CustomRules apiBase={apiBase} />;
    }
    if (page === 'settings') {
        return <JsonPanel apiBase={apiBase} endpoint="settings" />;
    }
    return <Overview status={status} statusError={statusError} />;
}

/*
 * Copilot iter 1 fix (R14): the previous Overview body fell through to
 * 'Disabled' / '0' whenever `status === null` — i.e. on the first render
 * before `/status` resolved, AND on a fetch failure (e.g. when
 * `PII_REDACTOR_ADMIN_ENABLED=false` so the package routes 404). Both
 * are 'unknown' states and presenting them as definitive 'Disabled' /
 * '0' contradicted the rest of the cards (Strategy / Token store
 * already used a 'loading' placeholder via `?? 'loading'`). The card
 * grid now uses one consistent placeholder ('—' for loading, 'unavailable'
 * for error) for ALL four cards while `status === null`, and only
 * renders concrete values once the fetch resolves successfully.
 */
function Overview({
    status,
    statusError,
}: {
    status: StatusPayload | null;
    statusError: string | null;
}) {
    const overviewState: 'loading' | 'error' | 'ready' =
        status === null ? (statusError ? 'error' : 'loading') : 'ready';
    const placeholder = overviewState === 'error' ? 'unavailable' : '—';
    const snapshot = status?.snapshot;

    const cards: Array<[string, string]> =
        overviewState === 'ready'
            ? [
                  ['Engine', snapshot?.enabled ? 'Enabled' : 'Disabled'],
                  ['Strategy', String(snapshot?.default_strategy ?? placeholder)],
                  ['Token store', String(snapshot?.token_store?.driver ?? placeholder)],
                  ['Detectors', String(snapshot?.detectors?.length ?? 0)],
              ]
            : [
                  ['Engine', placeholder],
                  ['Strategy', placeholder],
                  ['Token store', placeholder],
                  ['Detectors', placeholder],
              ];

    return (
        <section
            className="pra-grid"
            data-testid="admin-pii-redactor-overview"
            data-state={overviewState}
            aria-busy={overviewState === 'loading'}
        >
            {cards.map(([label, value]) => (
                <MetricPanel key={label} label={label} value={value} />
            ))}
        </section>
    );
}

function Playground({
    apiBase,
    abilities,
    strategies,
}: {
    apiBase: string;
    abilities: PiiRedactorAdminAbilities;
    strategies: string[];
}) {
    const [text, setText] = useState(
        'Mario Rossi email mario.rossi@example.test IBAN IT60X0542811101000000123456',
    );
    const [strategy, setStrategy] = useState(strategies[0] ?? 'mask');
    const [raw, setRaw] = useState(false);
    const [result, setResult] = useState<unknown>(null);
    const [error, setError] = useState<string | null>(null);

    async function run(kind: 'scan' | 'redact') {
        setError(null);
        try {
            setResult(
                await adminFetch(apiBase, kind, {
                    method: 'POST',
                    body: JSON.stringify({ text, strategy, include_raw_samples: raw }),
                }),
            );
        } catch (cause) {
            setError(errorMessage(cause));
        }
    }

    return (
        <section className="pra-workbench" data-testid="admin-pii-redactor-playground">
            <label htmlFor="admin-pii-redactor-playground-text" className="pra-sr-only">
                Sample text
            </label>
            <textarea
                id="admin-pii-redactor-playground-text"
                value={text}
                onChange={(event) => setText(event.target.value)}
                data-testid="admin-pii-redactor-playground-text"
            />
            <div className="pra-toolbar">
                {strategies.map((name) => (
                    <button
                        key={name}
                        type="button"
                        className={strategy === name ? 'is-active' : ''}
                        data-testid={`admin-pii-redactor-playground-strategy-${name}`}
                        aria-pressed={strategy === name}
                        onClick={() => setStrategy(name)}
                    >
                        {name}
                    </button>
                ))}
                <label>
                    <input
                        type="checkbox"
                        checked={raw}
                        disabled={!abilities.rawSamples}
                        onChange={(event) => setRaw(event.target.checked)}
                        data-testid="admin-pii-redactor-playground-raw"
                    />{' '}
                    raw samples
                </label>
                <button
                    type="button"
                    onClick={() => run('scan')}
                    data-testid="admin-pii-redactor-playground-scan"
                >
                    Scan
                </button>
                <button
                    type="button"
                    onClick={() => run('redact')}
                    data-testid="admin-pii-redactor-playground-redact"
                >
                    Redact
                </button>
            </div>
            {error && (
                <div className="pra-alert" role="alert" data-testid="admin-pii-redactor-playground-error">
                    {error}
                </div>
            )}
            <pre data-testid="admin-pii-redactor-playground-result">
                {JSON.stringify(result, null, 2)}
            </pre>
        </section>
    );
}

function Detokenise({ apiBase, abilities }: { apiBase: string; abilities: PiiRedactorAdminAbilities }) {
    const [text, setText] = useState('[tok:email:012345abcdef]');
    const [justification, setJustification] = useState('');
    const [ack, setAck] = useState(false);
    const [armed, setArmed] = useState(false);
    const [result, setResult] = useState<unknown>(null);
    const [error, setError] = useState<string | null>(null);
    const canReveal = abilities.detokenise && justification.length >= 10 && ack && armed;

    useEffect(() => {
        setArmed(false);
        setResult(null);
        setError(null);
    }, [text, justification, ack]);

    async function reveal() {
        setError(null);
        try {
            setResult(
                await adminFetch(apiBase, 'detokenise', {
                    method: 'POST',
                    body: JSON.stringify({ text, justification }),
                }),
            );
        } catch (cause) {
            setError(errorMessage(cause));
        }
    }

    return (
        <section className="pra-workbench danger" data-testid="admin-pii-redactor-detokenise">
            <p>
                Detokenise can reveal raw originals. Results are displayed only after confirmation and
                are not stored by this UI.
            </p>
            {!abilities.detokenise && (
                <InlineNotice tone="danger">
                    The configured detokenise ability is denied for this operator.
                </InlineNotice>
            )}
            <label htmlFor="admin-pii-redactor-detokenise-text" className="pra-sr-only">
                Tokenised text
            </label>
            <textarea
                id="admin-pii-redactor-detokenise-text"
                value={text}
                onChange={(event) => setText(event.target.value)}
                data-testid="admin-pii-redactor-detokenise-text"
            />
            <label htmlFor="admin-pii-redactor-detokenise-justification" className="pra-sr-only">
                Justification
            </label>
            <input
                id="admin-pii-redactor-detokenise-justification"
                value={justification}
                onChange={(event) => setJustification(event.target.value)}
                placeholder="Justification, at least 10 characters"
                data-testid="admin-pii-redactor-detokenise-justification"
            />
            <label>
                <input
                    type="checkbox"
                    checked={ack}
                    onChange={(event) => setAck(event.target.checked)}
                    data-testid="admin-pii-redactor-detokenise-ack"
                />{' '}
                I understand this reveals sensitive data.
            </label>
            <div className="pra-toolbar">
                <button
                    type="button"
                    disabled={!abilities.detokenise}
                    onClick={() => setArmed(true)}
                    data-testid="admin-pii-redactor-detokenise-arm"
                >
                    Arm reveal
                </button>
                <button
                    type="button"
                    disabled={!canReveal}
                    onClick={reveal}
                    data-testid="admin-pii-redactor-detokenise-reveal"
                >
                    Reveal
                </button>
            </div>
            {error && <InlineNotice tone="danger">{error}</InlineNotice>}
            <pre data-testid="admin-pii-redactor-detokenise-result">
                {JSON.stringify(result, null, 2)}
            </pre>
        </section>
    );
}

function DataBrowser({
    apiBase,
    kind,
    endpoint,
    rootKey,
    empty,
}: {
    apiBase: string;
    kind: 'audit' | 'tokens';
    endpoint: string;
    rootKey: string;
    empty: string;
}) {
    const [filters, setFilters] = useState({ search: '', detector: '', event_type: '', status_code: '' });
    const [applied, setApplied] = useState(filters);
    const [data, setData] = useState<unknown>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const query = useMemo(
        () =>
            buildAdminQuery(
                kind === 'tokens'
                    ? { search: applied.search, detector: applied.detector }
                    : { event_type: applied.event_type, status_code: applied.status_code },
            ),
        [applied, kind],
    );

    useEffect(() => {
        let active = true;
        setLoading(true);
        setError(null);
        adminFetch(apiBase, `${endpoint}${query}`)
            .then((payload) => {
                if (active) {
                    setData(payload);
                }
            })
            .catch((cause) => {
                if (active) {
                    setError(errorMessage(cause));
                }
            })
            .finally(() => {
                if (active) {
                    setLoading(false);
                }
            });
        return () => {
            active = false;
        };
    }, [apiBase, endpoint, query]);

    const rows = useMemo(() => {
        const root =
            data && typeof data === 'object' && rootKey in (data as Record<string, unknown>)
                ? (data as Record<string, unknown>)[rootKey]
                : data;
        const inner = root as { data?: unknown } | unknown;
        if (inner && typeof inner === 'object' && 'data' in inner && Array.isArray((inner as { data: unknown }).data)) {
            return (inner as { data: DataRow[] }).data;
        }
        return Array.isArray(root) ? (root as DataRow[]) : [];
    }, [data, rootKey]);

    function reset() {
        const cleared = { search: '', detector: '', event_type: '', status_code: '' };
        setFilters(cleared);
        setApplied(cleared);
    }

    return (
        <section
            className="pra-workbench"
            data-testid={`admin-pii-redactor-${kind === 'tokens' ? 'tokens' : 'audit'}`}
            data-state={loading ? 'loading' : error ? 'error' : rows.length === 0 ? 'empty' : 'ready'}
        >
            <div className="pra-filterbar">
                {kind === 'tokens' ? (
                    <>
                        <label>
                            Search token
                            <input
                                value={filters.search}
                                onChange={(event) => setFilters({ ...filters, search: event.target.value })}
                                placeholder="[tok:email"
                                data-testid="admin-pii-redactor-tokens-search"
                            />
                        </label>
                        <label>
                            Detector
                            <input
                                value={filters.detector}
                                onChange={(event) => setFilters({ ...filters, detector: event.target.value })}
                                placeholder="email"
                                data-testid="admin-pii-redactor-tokens-detector"
                            />
                        </label>
                    </>
                ) : (
                    <>
                        <label>
                            Event type
                            <input
                                value={filters.event_type}
                                onChange={(event) =>
                                    setFilters({ ...filters, event_type: event.target.value })
                                }
                                placeholder="detokenise.denied"
                                data-testid="admin-pii-redactor-audit-event-type"
                            />
                        </label>
                        <label>
                            Status
                            <input
                                value={filters.status_code}
                                onChange={(event) =>
                                    setFilters({ ...filters, status_code: event.target.value })
                                }
                                placeholder="403"
                                inputMode="numeric"
                                data-testid="admin-pii-redactor-audit-status-code"
                            />
                        </label>
                    </>
                )}
                <button
                    type="button"
                    onClick={() => setApplied(filters)}
                    data-testid={`admin-pii-redactor-${kind}-apply`}
                >
                    <Filter size={16} /> Apply
                </button>
                <button type="button" onClick={reset} data-testid={`admin-pii-redactor-${kind}-reset`}>
                    <RotateCcw size={16} /> Reset
                </button>
            </div>
            {loading && <EmptyState icon={LoaderCircle} label="Loading records..." />}
            {error && <InlineNotice tone="danger">{error}</InlineNotice>}
            {!loading && !error && rows.length === 0 && (
                <EmptyState icon={EyeOff} label={empty} testid={`admin-pii-redactor-${kind}-empty`} />
            )}
            {!loading && !error && rows.length > 0 && <DataTable rows={rows} />}
        </section>
    );
}

function Detectors({ apiBase }: { apiBase: string }) {
    const [data, setData] = useState<{ detectors?: Array<{ name: string; class: string }> } | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        let active = true;
        setLoading(true);
        setError(null);
        adminFetch<{ detectors?: Array<{ name: string; class: string }> }>(apiBase, 'detectors')
            .then((payload) => {
                if (active) {
                    setData(payload);
                }
            })
            .catch((cause) => {
                if (active) {
                    setError(errorMessage(cause));
                }
            })
            .finally(() => {
                if (active) {
                    setLoading(false);
                }
            });
        return () => {
            active = false;
        };
    }, [apiBase]);

    const detectors = data?.detectors ?? [];

    return (
        <section className="pra-grid" data-testid="admin-pii-redactor-detectors">
            {loading && <EmptyState icon={LoaderCircle} label="Loading detectors..." />}
            {error && <InlineNotice tone="danger">{error}</InlineNotice>}
            {!loading && !error && detectors.length === 0 && (
                <EmptyState icon={EyeOff} label="No detectors are configured." />
            )}
            {!loading &&
                !error &&
                detectors.map((detector) => (
                    <MetricPanel key={detector.name} label={detector.class} value={detector.name} />
                ))}
        </section>
    );
}

type CustomRulePack = { name?: string; path: string; valid: boolean; rule_count?: number; error?: string };

function CustomRules({ apiBase }: { apiBase: string }) {
    const [data, setData] = useState<{ packs?: CustomRulePack[] } | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        let active = true;
        setLoading(true);
        setError(null);
        adminFetch<{ packs?: CustomRulePack[] }>(apiBase, 'custom-rules')
            .then((payload) => {
                if (active) {
                    setData(payload);
                }
            })
            .catch((cause) => {
                if (active) {
                    setError(errorMessage(cause));
                }
            })
            .finally(() => {
                if (active) {
                    setLoading(false);
                }
            });
        return () => {
            active = false;
        };
    }, [apiBase]);

    const packs = data?.packs ?? [];

    return (
        <section className="pra-grid" data-testid="admin-pii-redactor-custom-rules">
            {loading && <EmptyState icon={LoaderCircle} label="Loading custom rules..." />}
            {error && <InlineNotice tone="danger">{error}</InlineNotice>}
            {!loading && !error && packs.length === 0 && (
                <EmptyState icon={EyeOff} label="No custom rule packs are configured." />
            )}
            {!loading &&
                !error &&
                packs.map((pack) => (
                    <div className="pra-panel" key={pack.name || pack.path}>
                        <span>{pack.path}</span>
                        <strong>{pack.name || 'Invalid pack'}</strong>
                        <p>{pack.valid ? `${pack.rule_count} rules` : pack.error}</p>
                    </div>
                ))}
        </section>
    );
}

function JsonPanel({ apiBase, endpoint }: { apiBase: string; endpoint: string }) {
    const [data, setData] = useState<unknown>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        let active = true;
        setLoading(true);
        setError(null);
        adminFetch(apiBase, endpoint)
            .then((payload) => {
                if (active) {
                    setData(payload);
                }
            })
            .catch((cause) => {
                if (active) {
                    setError(errorMessage(cause));
                }
            })
            .finally(() => {
                if (active) {
                    setLoading(false);
                }
            });
        return () => {
            active = false;
        };
    }, [apiBase, endpoint]);

    if (loading) {
        return <EmptyState icon={LoaderCircle} label="Loading settings..." />;
    }

    if (error) {
        return <InlineNotice tone="danger">{error}</InlineNotice>;
    }

    return <pre data-testid="admin-pii-redactor-settings">{JSON.stringify(data, null, 2)}</pre>;
}

function errorMessage(cause: unknown): string {
    if (cause instanceof AdminApiError) {
        return cause.message;
    }
    if (cause instanceof Error) {
        return cause.message;
    }
    return 'Request failed.';
}

function MetricPanel({ label, value }: { label: string; value: string }) {
    return (
        <div className="pra-panel">
            <span>{label}</span>
            <strong>{value}</strong>
        </div>
    );
}

function EmptyState({
    icon: Icon,
    label,
    testid,
}: {
    icon: React.ComponentType<{ size?: number; className?: string }>;
    label: string;
    testid?: string;
}) {
    return (
        <div className="pra-empty" data-testid={testid}>
            <Icon size={18} className="pra-empty-icon" /> {label}
        </div>
    );
}

function InlineNotice({
    children,
    tone = 'default',
}: {
    children: React.ReactNode;
    tone?: 'default' | 'danger';
}) {
    return <div className={tone === 'danger' ? 'pra-alert compact' : 'pra-notice'}>{children}</div>;
}

function DataTable({ rows }: { rows: DataRow[] }) {
    const safeRows = rows.map((row) =>
        Object.fromEntries(
            Object.entries(row).filter(
                ([key]) => !['original', 'raw_text', 'redacted_output', 'detokenised_output'].includes(key),
            ),
        ),
    );

    return (
        <table className="pra-table">
            <tbody>
                {safeRows.map((row, i) => (
                    <tr key={i}>
                        {Object.entries(row).map(([k, v]) => (
                            <td key={k}>
                                <span>{k}</span>
                                {formatValue(v)}
                            </td>
                        ))}
                    </tr>
                ))}
            </tbody>
        </table>
    );
}

function formatValue(value: unknown): string {
    if (value === null || value === undefined) {
        return 'null';
    }

    if (typeof value === 'object') {
        return JSON.stringify(value);
    }

    return String(value);
}
