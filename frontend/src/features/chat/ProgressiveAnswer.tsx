import { Check, FileText, Activity } from 'lucide-react';
import { Button } from '../../components/Button';
import type { AgentRunEvent } from '../../lib/agent-run-events';
import { progressiveCopy, progressiveFindings } from '../agent/progressive-findings';
import type { MessageCitation } from './chat.api';

export function ProgressiveAnswer({ events, active, paused = false, onOpenSource }: {
    events: AgentRunEvent[];
    active: boolean;
    paused?: boolean;
    onOpenSource?: (citation: MessageCitation) => void;
}) {
    const findings = progressiveFindings(events);
    if (findings.length === 0) return null;
    const latest = events.at(-1);
    const interrupted = latest?.type === 'run.failed' || latest?.type === 'run.cancelled';
    const state = interrupted ? 'interrupted' : paused ? 'paused'
        : ['synthesis.started', 'run.completed', 'run.partial'].includes(latest?.type ?? '') ? 'consolidating'
            : active ? 'researching' : 'disconnected';
    const copy = progressiveCopy(latest?.locale ?? 'en', state);
    return (
        <section className="agent-progressive-answer" data-testid="agent-progressive-answer" data-state={state} aria-label={copy.title}>
            <header>
                <strong>{copy.title}</strong>
                <span className="agent-progressive-status" role="status"><Activity size={15} aria-hidden="true" />{copy.status}</span>
            </header>
            <p className="agent-progressive-note">{copy.note}</p>
            <ol>
                {findings.map((finding) => (
                    <li key={finding.id} data-finding-id={finding.id}>
                        <Check className="agent-progressive-check" size={16} aria-hidden="true" />
                        <div className="agent-progressive-content">
                            <p>{finding.text}</p>
                            {onOpenSource ? <Button variant="secondary" size="sm" className="agent-progressive-source" leadingIcon={<FileText size={14} />}
                                onClick={() => onOpenSource(finding.citation)} aria-label={`${copy.source}: ${finding.citation.title}`}>
                                {finding.citation.title}
                            </Button> : <span className="agent-progressive-source-label"><FileText size={14} aria-hidden="true" />{finding.citation.title}</span>}
                            <details><summary>{copy.quote}</summary><blockquote>{finding.quote}</blockquote></details>
                        </div>
                    </li>
                ))}
            </ol>
        </section>
    );
}
