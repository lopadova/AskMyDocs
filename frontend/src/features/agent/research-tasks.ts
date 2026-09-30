import type { AgentRunEvent } from '../../lib/agent-run-events';

export type ResearchTaskStatus = 'pending' | 'documents' | 'awaiting_tools' | 'researching'
    | 'collected' | 'verifying' | 'answered' | 'partial' | 'unverified' | 'conflicting' | 'failed' | 'cancelled';

export interface ResearchTask {
    id: number;
    question: string;
    status: ResearchTaskStatus;
    settled: boolean;
}

const statuses: ResearchTaskStatus[] = ['pending', 'documents', 'awaiting_tools', 'researching', 'collected',
    'verifying', 'answered', 'partial', 'unverified', 'conflicting', 'failed', 'cancelled'];

const finalStatuses: ResearchTaskStatus[] = ['answered', 'partial', 'unverified', 'conflicting', 'failed', 'cancelled'];

/** Reconstructible from persisted SSE events; no fabricated completion or timer-based task progress. */
export function researchTasks(events: AgentRunEvent[]): ResearchTask[] {
    const runId = events.at(-1)?.run_id;
    const tasks = new Map<number, ResearchTask>();
    const seen = new Set<number>();
    for (const event of [...events].filter((item) => item.run_id === runId).sort((a, b) => a.sequence - b.sequence)) {
        if (seen.has(event.sequence)) continue;
        seen.add(event.sequence);
        if (event.type === 'research.planned' && Array.isArray(event.data.tasks)) {
            for (const value of event.data.tasks.slice(0, 10)) {
                if (!isRecord(value) || !isTaskId(value.id) || typeof value.question !== 'string' || !value.question.trim()) continue;
                const previous = tasks.get(value.id);
                tasks.set(value.id, { id: value.id, question: value.question, status: previous?.status ?? 'pending', settled: previous?.settled ?? false });
            }
        }
        if (event.type === 'research.task' && isTaskId(event.data.research_flow_id)) {
            const task = tasks.get(event.data.research_flow_id);
            if (task && !task.settled && isStatus(event.data.task_status)) {
                task.status = event.data.task_status;
            }
        }
        if (event.type === 'synthesis.started' && event.data.research_flow_id == null) {
            for (const task of tasks.values()) {
                if (!task.settled && !['failed', 'cancelled', 'partial'].includes(task.status)) task.status = 'verifying';
            }
        }
        if (event.type === 'research.finished' && Array.isArray(event.data.tasks)) {
            for (const value of event.data.tasks) {
                if (!isRecord(value) || !isTaskId(value.id) || !isStatus(value.task_status) || !finalStatuses.includes(value.task_status)) continue;
                const task = tasks.get(value.id);
                if (task) {
                    task.status = value.task_status;
                    task.settled = true;
                }
            }
        }
        if (['run.completed', 'run.partial', 'run.failed', 'run.cancelled'].includes(event.type) && event.data.research_flow_id == null) {
            for (const task of tasks.values()) {
                if (!task.settled) {
                    // A global "completed" event is not proof that every task has a verified answer.
                    task.status = event.type === 'run.cancelled' ? 'cancelled'
                        : event.type === 'run.failed' ? 'failed'
                            : finalStatuses.includes(task.status) ? task.status : 'unverified';
                    task.settled = true;
                }
            }
        }
    }
    return [...tasks.values()].sort((a, b) => a.id - b.id);
}

export function researchTaskCopy(locale: string) {
    const italian = locale.toLowerCase().startsWith('it');
    const labels: Record<ResearchTaskStatus, string> = italian ? {
        pending: 'In attesa', documents: 'Ricerca nei documenti', awaiting_tools: 'In attesa degli strumenti',
        researching: 'Ricerca in corso', collected: 'Fonti raccolte', verifying: 'Verifica della risposta',
        answered: 'Risposta verificata', partial: 'Risultati parziali', unverified: 'Non verificata',
        conflicting: 'Fonti in conflitto', failed: 'Ricerca non riuscita', cancelled: 'Interrotta',
    } : {
        pending: 'Queued', documents: 'Searching documents', awaiting_tools: 'Waiting for tools',
        researching: 'Researching', collected: 'Sources collected', verifying: 'Verifying answer',
        answered: 'Verified answer', partial: 'Partial results', unverified: 'Not verified',
        conflicting: 'Conflicting sources', failed: 'Research failed', cancelled: 'Cancelled',
    };
    return {
        title: italian ? 'Richieste individuate' : 'Detected requests',
        subtitle: italian ? 'Ricerche indipendenti per ciascuna domanda' : 'Independent research for each question',
        progress: (done: number, total: number) => italian ? `${done} di ${total} concluse` : `${done} of ${total} finished`,
        question: (index: number) => italian ? `Domanda ${index + 1}` : `Question ${index + 1}`,
        allQuestions: italian ? 'Tutte le domande' : 'All questions',
        unknownQuestion: italian ? 'Domanda non associata' : 'Unassigned question',
        labels,
    };
}

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}
function isTaskId(value: unknown): value is number {
    return typeof value === 'number' && Number.isInteger(value) && value >= 0 && value < 10;
}
function isStatus(value: unknown): value is ResearchTaskStatus {
    return typeof value === 'string' && statuses.includes(value as ResearchTaskStatus);
}
