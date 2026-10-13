import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useMemo, type CSSProperties, type ReactNode } from 'react';
import { AreaChart } from '../../components/charts';
import { Button } from '../../components/Button';
import { Icon } from '../../components/Icons';
import { parseLaravelError } from '../../lib/laravel-errors';
import {
    localIntegrationsApi,
    type LocalIntegrationAction,
    type LocalIntegrationEvent,
    type LocalIntegrationServiceStatus,
    type LocalIntegrationStatus,
    type LocalServiceName,
} from './local-integrations.api';
import './local-integrations.css';

const POLL_INTERVAL_MS = 2_000;
const STATUS_QUERY_KEY = ['developer', 'local-integrations'];
const CONTROL_PROGRESS: Record<LocalIntegrationAction, string> = {
    start: 'Avvio dei server in corso…',
    stop: 'Arresto dei server in corso…',
    restart: 'Riavvio dei server in corso…',
};
const ACTIVITY_BUCKETS = 12;
const ACTIVITY_BUCKET_MS = 5_000;

const panelStyle: CSSProperties = {
    background: 'var(--bg-1)',
    border: '1px solid var(--panel-border)',
    borderRadius: 12,
    boxShadow: 'var(--panel-shadow)',
};

export function LocalIntegrationFixturesView(): ReactNode {
    const queryClient = useQueryClient();
    const control = useMutation({
        mutationFn: localIntegrationsApi.control,
        onMutate: () => queryClient.cancelQueries({ queryKey: STATUS_QUERY_KEY }),
        onSuccess: ({ status }) => {
            queryClient.setQueryData(STATUS_QUERY_KEY, status);
        },
        onError: () => {
            void queryClient.invalidateQueries({ queryKey: STATUS_QUERY_KEY });
        },
    });
    const statusQuery = useQuery({
        queryKey: STATUS_QUERY_KEY,
        queryFn: ({ signal }) => localIntegrationsApi.status(signal),
        staleTime: POLL_INTERVAL_MS,
        refetchInterval: control.isPending ? false : POLL_INTERVAL_MS,
        refetchOnWindowFocus: !control.isPending,
    });

    const status = statusQuery.data;
    const bothRunning = status?.api.state === 'running' && status.mcp.state === 'running';
    const bothStopped = status?.api.state === 'stopped' && status.mcp.state === 'stopped';
    const controlsDisabled = !status || statusQuery.isError || control.isPending;
    const activity = useMemo(() => buildActivity(status), [status]);
    const calls = useMemo(() => collectCalls(status), [status]);
    const mostRecentMcpExchange = calls.find((call) => call.service === 'mcp' && call.exchange);

    function controlServices(action: LocalIntegrationAction) {
        control.mutate(action);
    }

    return (
        <main
            data-testid="local-integration-fixtures-view"
            data-state={statusQuery.isLoading ? 'loading' : statusQuery.isError ? 'error' : 'ready'}
            style={{
                width: '100%',
                maxWidth: 1380,
                margin: '0 auto',
                padding: '28px clamp(18px, 3vw, 42px) 42px',
                color: 'var(--fg-0)',
            }}
        >
            <header style={{ marginBottom: 18 }}>
                <div style={{ maxWidth: 690 }}>
                    <p style={eyebrowStyle}>Sviluppo locale · servizio tecnico</p>
                    <h1 style={{ fontSize: 'clamp(24px, 3vw, 32px)', letterSpacing: '-0.035em', margin: '0 0 7px' }}>
                        API e MCP locali
                    </h1>
                    <p style={{ color: 'var(--fg-2)', fontSize: 13.5, lineHeight: 1.55, margin: 0 }}>
                        Stato dei due mock Node, chiamate recenti e traffico degli ultimi sessanta secondi. Per ogni chiamata MCP puoi aprire richiesta JSON-RPC e risposta. Il pannello si aggiorna ogni 2 secondi e non è disponibile fuori da <code>APP_ENV=local</code>.
                    </p>
                </div>
            </header>

            <section className="local-integration-server-panel" aria-labelledby="local-integration-servers-title" style={{ ...panelStyle, padding: 18, marginBottom: 14 }}>
                <div className="local-integration-server-heading">
                    <div>
                        <h2 id="local-integration-servers-title" style={{ fontSize: 18, margin: '0 0 6px' }}>Server mock</h2>
                        <p style={{ color: 'var(--fg-2)', fontSize: 13, lineHeight: 1.55, margin: 0 }}>
                            Avvia o arresta insieme il mock API e il server MCP dei tre casi studio.
                        </p>
                    </div>
                    <div className="local-integration-server-actions" role="group" aria-label="Controlli dei server mock">
                        <Button
                            variant="primary"
                            leadingIcon={<Icon.Play size={14} />}
                            busy={control.isPending && control.variables === 'start'}
                            disabled={bothRunning || controlsDisabled}
                            onClick={() => controlServices('start')}
                        >
                            Avvia server
                        </Button>
                        <Button
                            variant="secondary"
                            leadingIcon={<Icon.Refresh size={14} />}
                            busy={control.isPending && control.variables === 'restart'}
                            disabled={controlsDisabled}
                            onClick={() => controlServices('restart')}
                        >
                            Riavvia
                        </Button>
                        <Button
                            variant="secondary"
                            leadingIcon={<Icon.StopCircle size={14} />}
                            busy={control.isPending && control.variables === 'stop'}
                            disabled={bothStopped || controlsDisabled}
                            onClick={() => controlServices('stop')}
                        >
                            Arresta server
                        </Button>
                        <Button
                            variant="quiet"
                            iconOnly
                            aria-label="Aggiorna lo stato dei servizi locali"
                            title="Aggiorna ora"
                            aria-busy={statusQuery.isFetching && !control.isPending}
                            disabled={control.isPending}
                            onClick={() => void statusQuery.refetch()}
                        >
                            <Icon.Refresh size={15} aria-hidden="true" />
                        </Button>
                    </div>
                </div>
                <p style={{ color: 'var(--fg-3)', fontSize: 12, margin: '12px 0 16px', minHeight: 18 }}>
                    <span role="status" aria-live="polite">
                        {control.isPending && control.variables ? CONTROL_PROGRESS[control.variables]
                            : statusQuery.isError ? 'Stato dei server non disponibile.'
                                : control.isSuccess ? control.data.message
                                    : status ? 'Aggiornamento automatico ogni 2 secondi.' : 'Verifica dello stato dei server…'}
                    </span>
                    {status && !statusQuery.isError && !control.isPending ? (
                        <> Ultimo controllo: <time dateTime={status.refreshed_at}>{formatTimestamp(status.refreshed_at)}</time></>
                    ) : null}
                </p>
                <div className="local-integration-services" role="group" aria-label="Stato dei servizi">
                    <ServiceCard name="api" title="Mock API" status={status?.api} unavailable={statusQuery.isError} />
                    <ServiceCard name="mcp" title="Server MCP" status={status?.mcp} unavailable={statusQuery.isError} />
                </div>
            </section>

            {control.isError ? (
                <section role="alert" style={{ ...alertStyle, borderColor: 'var(--err)' }}>
                    <Icon.Alert size={17} aria-hidden="true" />
                    <div style={{ minWidth: 0, overflowWrap: 'anywhere' }}>
                        <strong>Comando non riuscito.</strong><br />
                        {parseLaravelError(control.error, 'Impossibile aggiornare i servizi locali. Controlla lo stato e riprova.').message}
                    </div>
                </section>
            ) : null}

            {statusQuery.isError ? (
                <section role="alert" style={{ ...alertStyle, borderColor: 'rgba(239, 68, 68, 0.45)' }}>
                    <Icon.Alert size={17} />
                    <div><strong>Stato non disponibile.</strong><br />Verifica di essere in locale con un account admin o super-admin, poi aggiorna il pannello.</div>
                </section>
            ) : null}

            {status && !status.fixture_gate_enabled ? (
                <section role="status" style={{ ...alertStyle, borderColor: 'rgba(245, 158, 11, 0.45)' }}>
                    <Icon.Alert size={17} />
                    <div><strong>Connettori locali non ancora abilitati.</strong><br />I processi possono avviarsi, ma per configurare i tenant esegui <code>php artisan dev:reset-local-integration-fixtures</code>.</div>
                </section>
            ) : null}

            <section style={{ ...panelStyle, padding: '18px 18px 12px', marginBottom: 14 }} aria-labelledby="local-integration-activity-title">
                <div style={{ display: 'flex', alignItems: 'baseline', justifyContent: 'space-between', gap: 14, flexWrap: 'wrap', marginBottom: 12 }}>
                    <div>
                        <p style={eyebrowStyle}>Attività live</p>
                        <h2 id="local-integration-activity-title" style={{ fontSize: 16, margin: '2px 0 0' }}>Chiamate negli ultimi 60 secondi</h2>
                    </div>
                    <span style={{ fontSize: 12, color: 'var(--fg-3)' }}>{activity.total} chiamate osservate</span>
                </div>
                <AreaChart data={activity.counts} labels={activity.labels} height={190} />
                <div style={{ display: 'flex', gap: 16, color: 'var(--fg-2)', fontSize: 12, margin: '6px 4px 0' }}>
                    <Legend color="#8b5cf6" label={`API ${status?.api.metrics?.totals.requests ?? 0}`} />
                    <Legend color="#22d3ee" label={`MCP ${status?.mcp.metrics?.totals.requests ?? 0}`} />
                    <Legend color="#ef4444" label={`Errori ${(status?.api.metrics?.totals.errors ?? 0) + (status?.mcp.metrics?.totals.errors ?? 0)}`} />
                </div>
            </section>

            <section style={{ ...panelStyle, overflow: 'hidden' }} aria-labelledby="local-integration-calls-title">
                <div style={{ padding: '16px 18px 12px', borderBottom: '1px solid var(--hairline)' }}>
                    <p style={eyebrowStyle}>Registro effimero</p>
                    <div style={{ display: 'flex', justifyContent: 'space-between', gap: 12, alignItems: 'baseline' }}>
                        <h2 id="local-integration-calls-title" style={{ fontSize: 16, margin: '2px 0 0' }}>Chiamate recenti</h2>
                        <span data-testid="local-integration-call-count" style={{ color: 'var(--fg-3)', fontSize: 11.5 }}>{calls.length} mostrate</span>
                    </div>
                </div>
                {calls.length === 0 ? (
                    <div data-testid="local-integration-calls-empty" style={{ padding: '32px 18px', color: 'var(--fg-3)', fontSize: 13 }}>
                        Nessuna chiamata applicativa ancora. Le richieste di health check e di monitoraggio non vengono conteggiate.
                    </div>
                ) : (
                    <div style={{ overflowX: 'auto' }}>
                        <table style={{ width: '100%', borderCollapse: 'collapse', minWidth: 700, fontSize: 12.5 }}>
                            <thead>
                                <tr style={{ color: 'var(--fg-3)', textAlign: 'left', fontSize: 11, letterSpacing: '0.04em', textTransform: 'uppercase' }}>
                                    <th style={cellStyle}>Quando</th><th style={cellStyle}>Servizio</th><th style={cellStyle}>Richiesta</th><th style={cellStyle}>Tenant</th><th style={cellStyle}>Esito</th><th style={cellStyle}>Durata</th>
                                </tr>
                            </thead>
                            <tbody>
                                {calls.map((call) => (
                                    <CallRows
                                        call={call}
                                        key={`${call.service}-${call.id}-${call.at}`}
                                        openExchange={call === mostRecentMcpExchange}
                                    />
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </section>
        </main>
    );
}

function ServiceCard({ name, title, status, unavailable }: { name: LocalServiceName; title: string; status?: LocalIntegrationServiceStatus; unavailable: boolean }): ReactNode {
    const active = status?.state === 'running';
    const IconComponent = name === 'api' ? Icon.Api : Icon.Mcp;
    const datasetRevision = typeof status?.health?.datasetRevision === 'string' ? status.health.datasetRevision : null;

    return (
        <article data-testid={`local-integration-${name}-status`} style={{ ...panelStyle, padding: 18, minWidth: 0, overflowWrap: 'anywhere' }}>
            <div style={{ display: 'flex', flexWrap: 'wrap', justifyContent: 'space-between', gap: 16, alignItems: 'flex-start' }}>
                <div style={{ display: 'flex', gap: 10, alignItems: 'center' }}>
                    <span style={{ display: 'inline-grid', placeItems: 'center', width: 32, height: 32, borderRadius: 9, background: 'var(--bg-2)', color: name === 'api' ? '#8b5cf6' : '#22d3ee' }}><IconComponent size={17} /></span>
                    <div><h2 style={{ fontSize: 15, margin: 0 }}>{title}</h2><span style={{ fontSize: 11.5, color: 'var(--fg-3)' }}>{status?.address ?? 'Indirizzo in verifica…'}</span></div>
                </div>
                <StatusPill state={unavailable ? 'unavailable' : status?.state ?? 'checking'} />
            </div>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, minmax(0, 1fr))', gap: 8, marginTop: 20 }}>
                <Metric label="Chiamate" value={status?.metrics?.totals.requests ?? 0} />
                <Metric label="Errori" value={status?.metrics?.totals.errors ?? 0} />
                <Metric label="Uptime" value={active ? formatDuration(status?.metrics?.uptime_seconds ?? 0) : '—'} />
            </div>
            <p style={{ color: status?.error ? 'var(--fg-3)' : 'var(--fg-2)', fontSize: 11.5, margin: '16px 0 0', minHeight: 18 }}>
                {status?.error ?? (datasetRevision ? `Dataset ${datasetRevision}` : 'In attesa dello stato…')}
            </p>
        </article>
    );
}

function StatusPill({ state }: { state: 'running' | 'stopped' | 'degraded' | 'checking' | 'unavailable' }): ReactNode {
    const label = state === 'running' ? 'Attivo' : state === 'degraded' ? 'Degradato' : state === 'checking' ? 'In verifica' : state === 'unavailable' ? 'Non disponibile' : 'Fermo';
    return <span className="local-integration-service-state" data-state={state} role="status" aria-live="polite"><span aria-hidden="true" />{label}</span>;
}

function Metric({ label, value }: { label: string; value: string | number }): ReactNode {
    return <div><div style={{ fontSize: 17, fontWeight: 620, letterSpacing: '-0.02em' }}>{value}</div><div style={{ color: 'var(--fg-3)', fontSize: 10.5, marginTop: 2 }}>{label}</div></div>;
}

function Legend({ color, label }: { color: string; label: string }): ReactNode {
    return <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5 }}><span aria-hidden="true" style={{ width: 7, height: 7, borderRadius: 99, background: color }} />{label}</span>;
}

type ListedCall = LocalIntegrationEvent & { service: LocalServiceName };

function CallRow({ call }: { call: ListedCall }): ReactNode {
    const success = call.status < 400;
    return (
        <tr style={{ borderTop: '1px solid var(--hairline)' }}>
            <td style={cellStyle}>{formatTimestamp(call.at)}</td>
            <td style={cellStyle}><span style={{ display: 'inline-flex', alignItems: 'center', gap: 5 }}><span aria-hidden="true" style={{ width: 6, height: 6, borderRadius: 99, background: call.service === 'api' ? '#8b5cf6' : '#22d3ee' }} />{call.service.toUpperCase()}</span></td>
            <td style={{ ...cellStyle, fontFamily: 'var(--font-mono)', color: 'var(--fg-1)' }}>{call.method} {call.path}</td>
            <td style={cellStyle}>{call.company_key ?? '—'}</td>
            <td style={cellStyle}><span style={{ color: success ? '#34d399' : '#f87171', fontWeight: 650 }}>{call.status}</span></td>
            <td style={cellStyle}>{call.duration_ms} ms</td>
        </tr>
    );
}

function CallRows({ call, openExchange }: { call: ListedCall; openExchange: boolean }): ReactNode {
    return (
        <>
            <CallRow call={call} />
            {call.service === 'mcp' && call.exchange ? <McpExchangeRow call={call} open={openExchange} /> : null}
        </>
    );
}

function McpExchangeRow({ call, open }: { call: ListedCall; open: boolean }): ReactNode {
    const exchange = call.exchange;
    if (!exchange) return null;

    return (
        <tr style={{ borderTop: '1px solid var(--hairline)', background: 'var(--bg-2)' }}>
            <td colSpan={6} style={{ padding: '0 18px 14px' }}>
                <details open={open} data-testid={`local-integration-mcp-exchange-${call.id}`}>
                    <summary style={{ cursor: 'pointer', color: 'var(--fg-1)', fontSize: 12, fontWeight: 650, paddingTop: 12 }}>
                        {describeMcpRequest(exchange.request)} — richiesta e risposta JSON-RPC
                    </summary>
                    <p style={{ color: 'var(--fg-3)', fontSize: 11.5, margin: '8px 0 12px' }}>
                        Dati effimeri del mock locale: niente header o credenziali. Il contenuto si azzera al riavvio del servizio.
                    </p>
                    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(280px, 1fr))', gap: 10 }}>
                        <JsonPayload title="Chiamata JSON-RPC" payload={exchange.request} testId={`local-integration-mcp-request-${call.id}`} />
                        <JsonPayload title="Risposta MCP" payload={exchange.response} testId={`local-integration-mcp-response-${call.id}`} />
                    </div>
                    {exchange.response_truncated ? (
                        <p style={{ color: '#fbbf24', fontSize: 11.5, margin: '10px 0 0' }}>Risposta troncata a 32 KB nel monitor locale.</p>
                    ) : null}
                </details>
            </td>
        </tr>
    );
}

function JsonPayload({ title, payload, testId }: { title: string; payload: unknown; testId: string }): ReactNode {
    return (
        <section style={{ border: '1px solid var(--hairline)', borderRadius: 8, background: 'var(--bg-1)', overflow: 'hidden' }}>
            <p style={{ color: 'var(--fg-2)', fontSize: 11, fontWeight: 650, letterSpacing: '0.04em', margin: '10px 12px 0', textTransform: 'uppercase' }}>{title}</p>
            <pre
                data-testid={testId}
                style={{ color: 'var(--fg-1)', fontFamily: 'var(--font-mono)', fontSize: 11, lineHeight: 1.55, margin: 0, maxHeight: 360, overflow: 'auto', padding: 12, whiteSpace: 'pre-wrap', wordBreak: 'break-word' }}
            >
                {formatPayload(payload)}
            </pre>
        </section>
    );
}

function collectCalls(status?: LocalIntegrationStatus): ListedCall[] {
    if (!status) return [];
    return (['api', 'mcp'] as const)
        .flatMap((service) => (status[service].metrics?.events ?? []).map((event) => ({ ...event, service })))
        .sort((a, b) => Date.parse(b.at) - Date.parse(a.at))
        .slice(0, 24);
}

function describeMcpRequest(payload: unknown): string {
    if (!isRecord(payload)) return 'Dettaglio MCP';
    const method = typeof payload.method === 'string' ? payload.method : 'JSON-RPC';
    const params = isRecord(payload.params) ? payload.params : null;
    const toolName = params && typeof params.name === 'string' ? params.name : null;

    return toolName ? `${method} · ${toolName}` : method;
}

function formatPayload(payload: unknown): string {
    if (payload === undefined || payload === null) return 'Nessun payload disponibile';
    if (typeof payload === 'string') return payload;

    try {
        return JSON.stringify(payload, null, 2);
    } catch {
        return String(payload);
    }
}

function isRecord(value: unknown): value is Record<string, unknown> {
    return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function buildActivity(status?: LocalIntegrationStatus): { counts: number[]; labels: string[]; total: number } {
    const now = Date.now();
    const start = now - ACTIVITY_BUCKETS * ACTIVITY_BUCKET_MS;
    const counts = Array.from({ length: ACTIVITY_BUCKETS }, () => 0);
    const calls = collectCalls(status);
    calls.forEach((call) => {
        const index = Math.floor((Date.parse(call.at) - start) / ACTIVITY_BUCKET_MS);
        if (index >= 0 && index < counts.length) counts[index] += 1;
    });
    const labels = counts.map((_, index) => index === 0 || index === counts.length - 1 || index % 3 === 0
        ? new Intl.DateTimeFormat('it-IT', { minute: '2-digit', second: '2-digit' }).format(new Date(start + index * ACTIVITY_BUCKET_MS))
        : '');

    return { counts, labels, total: calls.length };
}

function formatTimestamp(timestamp: string): string {
    return new Intl.DateTimeFormat('it-IT', { hour: '2-digit', minute: '2-digit', second: '2-digit' }).format(new Date(timestamp));
}

function formatDuration(seconds: number): string {
    if (seconds < 60) return `${seconds}s`;
    const minutes = Math.floor(seconds / 60);
    return `${minutes}m ${seconds % 60}s`;
}

const eyebrowStyle: CSSProperties = { color: 'var(--fg-3)', fontSize: 10.5, fontWeight: 650, letterSpacing: '0.11em', margin: 0, textTransform: 'uppercase' };
const alertStyle: CSSProperties = { ...panelStyle, display: 'flex', alignItems: 'flex-start', gap: 10, padding: '12px 14px', marginBottom: 14, color: 'var(--fg-1)', fontSize: 12.5, lineHeight: 1.5 };
const cellStyle: CSSProperties = { padding: '10px 18px', whiteSpace: 'nowrap', fontWeight: 400 };
