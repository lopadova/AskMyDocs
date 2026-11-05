import type { AgentRunEvent } from '../../lib/agent-run-events';
import type { MessageCitation } from '../chat/chat.api';

export interface ProgressiveFinding {
    id: string;
    text: string;
    quote: string;
    citation: MessageCitation;
}

/** Replay-safe deltas from the current run; never interpret tool/debug payloads as findings. */
export function progressiveFindings(events: AgentRunEvent[]): ProgressiveFinding[] {
    const runId = events.at(-1)?.run_id;
    const findings = new Map<string, ProgressiveFinding>();
    for (const event of events) {
        if (event.run_id !== runId || event.type !== 'research.findings' || !event.data
            || (event.data.validation as Record<string, unknown> | undefined)?.status !== 'accepted'
            || !Array.isArray(event.data.findings)) continue;
        for (const item of event.data.findings) {
            if (!item || typeof item !== 'object' || typeof item.id !== 'string'
                || typeof item.text !== 'string' || !item.text.trim() || item.text.length > 1600
                || typeof item.quote !== 'string' || !item.quote.trim() || item.quote.length > 2400
                || !item.citation || !Number.isInteger(item.citation.document_id) || item.citation.document_id < 1
                || typeof item.citation.title !== 'string') continue;
            if (findings.size < 24 || findings.has(item.id)) findings.set(item.id, item as ProgressiveFinding);
        }
    }
    return [...findings.values()];
}

export function progressiveCopy(locale: string, state: 'researching' | 'consolidating' | 'paused' | 'interrupted' | 'disconnected') {
    const italian = locale.toLowerCase().startsWith('it');
    return italian ? {
        title: 'Risultati verificati finora',
        status: { researching: 'Ricerca in corso · Sono in arrivo altri dati', consolidating: 'Sto unendo i risultati nella risposta finale',
            paused: 'Ricerca in pausa · I risultati restano disponibili', interrupted: 'Ricerca interrotta · Risultati parziali',
            disconnected: 'Aggiornamenti interrotti · I risultati verificati restano disponibili' }[state],
        note: 'Ogni risultato è verificato rispetto alla fonte citata. La risposta finale confronterà aggiornamenti e possibili contraddizioni.',
        quote: 'Passaggio della fonte', source: 'Apri fonte',
    } : {
        title: 'Verified findings so far',
        status: { researching: 'Research in progress · More information is on the way', consolidating: 'Combining findings into the final answer',
            paused: 'Research paused · Findings remain available', interrupted: 'Research interrupted · Partial findings',
            disconnected: 'Updates interrupted · Verified findings remain available' }[state],
        note: 'Each finding is checked against its cited source. The final answer will compare updates and possible contradictions.',
        quote: 'Source passage', source: 'Open source',
    };
}
