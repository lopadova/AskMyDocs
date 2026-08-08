import type { ReactNode } from 'react';
import type { AgentRunEvent } from '../../lib/agent-run-events';

export interface AgentActivityBarProps {
    events: AgentRunEvent[];
    active: boolean;
    awaitingConfirmation: boolean;
    onCancel: () => void;
    onContinue: () => void;
}

export function AgentActivityBar({
    events,
    active,
    awaitingConfirmation,
    onCancel,
    onContinue,
}: AgentActivityBarProps): ReactNode {
    if (events.length === 0 && !active && !awaitingConfirmation) return null;
    const latest = events[events.length - 1];
    const locale = latest?.locale?.toLowerCase().startsWith('it') ? 'it' : 'en';
    const copy = locale === 'it'
        ? { details: 'Dettagli attività', cancel: 'Annulla', proceed: 'Continua la ricerca', calls: 'chiamate', seconds: 's rimanenti' }
        : { details: 'Activity details', cancel: 'Cancel', proceed: 'Continue search', calls: 'calls', seconds: 's remaining' };
    const progress = latest?.progress;
    const physical = progress?.physical;
    const logical = progress?.logical;
    const metric = physical && physical.estimated.likely > 0 ? physical : logical;
    const completed = metric?.completed ?? 0;
    const likely = Math.max(completed, metric?.estimated.likely ?? 0);
    const percent = likely > 0 ? Math.min(100, Math.round((completed / likely) * 100)) : (active ? 12 : 100);
    const messages = events.filter((event) => typeof event.message === 'string' && event.message !== '');

    return (
        <aside
            data-testid="agent-activity-bar"
            data-state={awaitingConfirmation ? 'confirmation' : active ? 'active' : 'settled'}
            aria-live="polite"
            aria-busy={active}
            style={{
                margin: '10px 24px 0',
                padding: '10px 12px',
                border: '1px solid var(--panel-border)',
                borderRadius: 12,
                background: 'color-mix(in srgb, var(--panel-solid) 92%, var(--accent) 8%)',
                boxShadow: 'var(--shadow)',
            }}
        >
            <div style={{ display: 'flex', gap: 12, alignItems: 'center' }}>
                <span aria-hidden="true" style={{ color: 'var(--accent)', fontSize: 15 }}>{active ? '◌' : awaitingConfirmation ? '!' : '✓'}</span>
                <div style={{ flex: 1, minWidth: 0 }}>
                    <div data-testid="agent-activity-message" style={{ fontSize: 12.5, color: 'var(--fg-1)' }}>
                        {latest?.message ?? (locale === 'it' ? 'L’assistente sta lavorando.' : 'The assistant is working.')}
                    </div>
                    <div style={{ display: 'flex', gap: 10, marginTop: 5, fontSize: 10.5, color: 'var(--fg-3)', fontFamily: 'var(--font-mono)' }}>
                        {likely > 0 && <span data-testid="agent-activity-calls">{completed} / ~{likely} {copy.calls}</span>}
                        {progress?.eta_ms != null && <span>{Math.ceil(progress.eta_ms / 1000)} {copy.seconds}</span>}
                    </div>
                    <div style={{ height: 3, marginTop: 7, borderRadius: 99, background: 'var(--bg-3)', overflow: 'hidden' }}>
                        <div data-testid="agent-activity-progress" style={{ width: `${percent}%`, height: '100%', background: 'var(--grad-accent)', transition: 'width .25s ease' }} />
                    </div>
                </div>
                {active && (
                    <button type="button" className="btn sm ghost" data-testid="agent-activity-cancel" onClick={onCancel}>
                        {copy.cancel}
                    </button>
                )}
                {awaitingConfirmation && (
                    <button type="button" className="btn sm primary" data-testid="agent-activity-continue" onClick={onContinue}>
                        {copy.proceed}
                    </button>
                )}
            </div>
            {messages.length > 1 && (
                <details style={{ marginTop: 8, fontSize: 11, color: 'var(--fg-2)' }}>
                    <summary style={{ cursor: 'pointer' }}>{copy.details} · {messages.length}</summary>
                    <ol style={{ margin: '7px 0 0', paddingLeft: 24 }}>
                        {messages.map((event) => <li key={event.sequence} style={{ padding: '2px 0' }}>{event.message}</li>)}
                    </ol>
                </details>
            )}
        </aside>
    );
}
