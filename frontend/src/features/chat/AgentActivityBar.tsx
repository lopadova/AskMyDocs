import type { ReactNode } from 'react';
import type { AgentRunEvent } from '../../lib/agent-run-events';
import { Icon } from '../../components/Icons';

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
        ? {
            active: 'Ricerca in corso',
            settled: 'Attività completata',
            confirmation: 'Conferma necessaria',
            fallback: 'L’assistente sta lavorando.',
            details: 'Cronologia attività',
            cancel: 'Annulla',
            proceed: 'Continua la ricerca',
            calls: 'chiamate',
            seconds: 's rimanenti',
        }
        : {
            active: 'Search in progress',
            settled: 'Activity completed',
            confirmation: 'Confirmation required',
            fallback: 'The assistant is working.',
            details: 'Activity timeline',
            cancel: 'Cancel',
            proceed: 'Continue search',
            calls: 'calls',
            seconds: 's remaining',
        };
    const progress = latest?.progress;
    const physical = progress?.physical;
    const logical = progress?.logical;
    const metric = physical && physical.estimated.likely > 0 ? physical : logical;
    const completed = metric?.completed ?? 0;
    const likely = Math.max(completed, metric?.estimated.likely ?? 0);
    const percent = likely > 0 ? Math.min(100, Math.round((completed / likely) * 100)) : (active ? 12 : 100);
    const messages = events.filter((event) => typeof event.message === 'string' && event.message !== '');
    const state = awaitingConfirmation ? 'confirmation' : active ? 'active' : 'settled';
    const heading = copy[state];

    return (
        <aside
            data-testid="agent-activity-bar"
            data-state={state}
            aria-live="polite"
            aria-busy={active}
            className="agent-activity-card"
        >
            <div className="agent-activity-main">
                <span className="agent-activity-icon" aria-hidden="true">
                    {active ? <Icon.Activity size={15} /> : awaitingConfirmation ? <Icon.Bolt size={15} /> : <Icon.Check size={15} />}
                </span>
                <div className="agent-activity-content">
                    <div className="agent-activity-heading-row">
                        <strong data-testid="agent-activity-heading" className="agent-activity-heading">
                            {heading}
                        </strong>
                        <div className="agent-activity-metrics">
                            {likely > 0 && <span data-testid="agent-activity-calls">{completed} / ~{likely} {copy.calls}</span>}
                            {active && progress?.eta_ms != null && <span>{Math.ceil(progress.eta_ms / 1000)} {copy.seconds}</span>}
                        </div>
                    </div>
                    <div data-testid="agent-activity-message" className="agent-activity-message">
                        {latest?.message ?? copy.fallback}
                    </div>
                    <div
                        className="agent-activity-progress-track"
                        role="progressbar"
                        aria-valuemin={0}
                        aria-valuemax={100}
                        aria-valuenow={percent}
                    >
                        <div
                            data-testid="agent-activity-progress"
                            className="agent-activity-progress-value"
                            style={{ width: `${percent}%` }}
                        />
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
                <details className="agent-activity-details">
                    <summary>
                        <span>{copy.details}</span>
                        <span className="agent-activity-count">{messages.length}</span>
                    </summary>
                    <ol>
                        {messages.map((event) => <li key={event.sequence}>{event.message}</li>)}
                    </ol>
                </details>
            )}
        </aside>
    );
}
