import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useMemo, type CSSProperties, type ReactNode } from 'react';
import { AreaChart } from '../../components/charts';
import { Button } from '../../components/Button';
import { Icon } from '../../components/Icons';
import { ToastHost, useToast } from '../admin/shared/Toast';
import {
    localIntegrationsApi,
    type LocalIntegrationAction,
    type LocalIntegrationEvent,
    type LocalIntegrationServiceStatus,
    type LocalIntegrationStatus,
    type LocalServiceName,
} from './local-integrations.api';

const POLL_INTERVAL_MS = 2_000;
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
    const toast = useToast();
    const statusQuery = useQuery({
        queryKey: ['developer', 'local-integrations'],
        queryFn: localIntegrationsApi.status,
        staleTime: POLL_INTERVAL_MS,
        refetchInterval: POLL_INTERVAL_MS,
        refetchOnWindowFocus: true,
    });
    const control = useMutation({
        mutationFn: localIntegrationsApi.control,
        onSuccess: ({ message, status }) => {
            queryClient.setQueryData(['developer', 'local-integrations'], status);
            toast.success(message, 'toast-local-integrations-control');
        },
        onError: () => {
            toast.error('Impossibile aggiornare i servizi locali. Controlla lo stato e riprova.', 'toast-local-integrations-error');
        },
    });

    const status = statusQuery.data;
    const bothRunning = status?.api.state === 'running' && status.mcp.state === 'running';
    const bothStopped = status?.api.state === 'stopped' && status.mcp.state === 'stopped';
    const activity = useMemo(() => buildActivity(status), [status]);
    const calls = useMemo(() => collectCalls(status), [status]);

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
            <header style={{ display: 'flex', flexWrap: 'wrap', justifyContent: 'space-between', gap: 20, marginBottom: 18 }}>
                <div style={{ maxWidth: 690 }}>
                    <p style={eyebrowStyle}>Sviluppo locale · servizio tecnico</p>
                    <h1 style={{ fontSize: 'clamp(24px, 3vw, 32px)', letterSpacing: '-0.035em', margin: '0 0 7px' }}>
                        API e MCP locali
                    </h1>
                    <p style={{ color: 'var(--fg-2)', fontSize: 13.5, lineHeight: 1.55, margin: 0 }}>
                        Stato dei due mock Node, chiamate recenti e traffico degli ultimi sessanta secondi. Il pannello si aggiorna ogni 2 secondi e non è disponibile fuori da <code>APP_ENV=local</code>.
                    </p>
                </div>
                <div style={{ display: 'flex', flexWrap: 'wrap', alignItems: 'center', alignContent: 'flex-start', gap: 8 }}>
                    <Button
                        variant="primary"
                        leadingIcon={<Icon.Play size={14} />}
                        busy={control.isPending && control.variables === 'start'}
                        disabled={bothRunning || control.isPending}
                        onClick={() => controlServices('start')}
                    >
                        Avvia servizi
                    </Button>
                    <Button
                        variant="secondary"
                        leadingIcon={<Icon.Refresh size={14} />}
                        busy={control.isPending && control.variables === 'restart'}
                        disabled={control.isPending}
                        onClick={() => controlServices('restart')}
                    >
                        Riavvia
                    </Button>
                    <Button
                        variant="danger"
                        leadingIcon={<Icon.StopCircle size={14} />}
                        busy={control.isPending && control.variables === 'stop'}
                        disabled={bothStopped || control.isPending}
                        onClick={() => controlServices('stop')}
                    >
                        Arresta
                    </Button>
                    <Button
                        variant="quiet"
                        iconOnly
                        aria-label="Aggiorna lo stato dei servizi locali"
                        title="Aggiorna ora"
                        busy={statusQuery.isFetching && !control.isPending}
                        onClick={() => void statusQuery.refetch()}
                    >
                        <Icon.Refresh size={15} />
                    </Button>
                </div>
            </header>

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

            <section aria-label="Stato dei servizi" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(280px, 1fr))', gap: 14, marginBottom: 14 }}>
                <ServiceCard name="api" title="API statica" status={status?.api} />
                <ServiceCard name="mcp" title="MCP Streamable HTTP" status={status?.mcp} />
            </section>

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
                                {calls.map((call) => <CallRow call={call} key={`${call.service}-${call.id}-${call.at}`} />)}
                            </tbody>
                        </table>
                    </div>
                )}
            </section>
            <ToastHost />
        </main>
    );
}

function ServiceCard({ name, title, status }: { name: LocalServiceName; title: string; status?: LocalIntegrationServiceStatus }): ReactNode {
    const active = status?.state === 'running';
    const IconComponent = name === 'api' ? Icon.Api : Icon.Mcp;
    const datasetRevision = typeof status?.health?.datasetRevision === 'string' ? status.health.datasetRevision : null;

    return (
        <article data-testid={`local-integration-${name}-status`} style={{ ...panelStyle, padding: 18 }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', gap: 16, alignItems: 'flex-start' }}>
                <div style={{ display: 'flex', gap: 10, alignItems: 'center' }}>
                    <span style={{ display: 'inline-grid', placeItems: 'center', width: 32, height: 32, borderRadius: 9, background: 'var(--bg-2)', color: name === 'api' ? '#8b5cf6' : '#22d3ee' }}><IconComponent size={17} /></span>
                    <div><h2 style={{ fontSize: 15, margin: 0 }}>{title}</h2><span style={{ fontSize: 11.5, color: 'var(--fg-3)' }}>{name === 'api' ? '127.0.0.1:4310' : '127.0.0.1:4311'}</span></div>
                </div>
                <StatusPill state={status?.state ?? 'stopped'} />
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

function StatusPill({ state }: { state: 'running' | 'stopped' | 'degraded' }): ReactNode {
    const label = state === 'running' ? 'Attivo' : state === 'degraded' ? 'Degradato' : 'Fermo';
    const color = state === 'running' ? '#34d399' : state === 'degraded' ? '#fbbf24' : '#94a3b8';
    return <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6, color, fontSize: 11.5, fontWeight: 650 }}><span aria-hidden="true" style={{ width: 7, height: 7, borderRadius: 99, background: color, boxShadow: state === 'running' ? `0 0 0 4px color-mix(in srgb, ${color} 16%, transparent)` : undefined }} />{label}</span>;
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

function collectCalls(status?: LocalIntegrationStatus): ListedCall[] {
    if (!status) return [];
    return (['api', 'mcp'] as const)
        .flatMap((service) => (status[service].metrics?.events ?? []).map((event) => ({ ...event, service })))
        .sort((a, b) => Date.parse(b.at) - Date.parse(a.at))
        .slice(0, 24);
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
