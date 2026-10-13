import type { AgentRunEvent } from '../../lib/agent-run-events';
import type { AgentTurnStarted } from './chat.api';
import { englishWaitingPhrases, italianWaitingPhrases, type RealtimeResearchPhase } from './realtime-waiting-phrases';

export const VOICE_PROGRESS_FIRST_DELAY_MS = 8_000;
export const VOICE_PROGRESS_INTERVAL_MS = 15_000;
const SPEECH_QUIET_MS = 6_000;
const terminalEvents = new Set(['run.completed', 'run.partial', 'run.failed', 'run.cancelled', 'run.awaiting_confirmation', 'run.mcp_interaction_required']);
const terminalStatuses = new Set(['completed', 'partial', 'failed', 'cancelled', 'awaiting_confirmation']);

/** Narrates public progress of one canonical turn; never starts work or reads source payloads. */
export class RealtimeResearchNarrator {
    private timer: ReturnType<typeof setTimeout> | null = null;
    private pending = false;
    private runId: string | null = null;
    private sequence = 0;
    private locale = 'en';
    private phase: RealtimeResearchPhase = 'waiting';
    private quietUntil = 0;
    private nextAt = 0;
    private spoken = 0;
    private update: { key: string; content: string; priority: number } | null = null;
    private readonly announced = new Set<string>();
    private readonly tasks = new Map<number, string>();
    private readonly phraseIndices = new Map<string, number>();

    constructor(private readonly speak: (content: string) => Promise<void>, private readonly random = Math.random) {}

    begin(locale = 'en'): void {
        this.stop();
        this.pending = true;
        this.locale = locale;
        this.runId = null;
        this.sequence = 0;
        this.phase = 'waiting';
        this.quietUntil = 0;
        this.spoken = 0;
        this.announced.clear();
        this.tasks.clear();
        this.phraseIndices.clear();
        this.nextAt = Date.now() + VOICE_PROGRESS_FIRST_DELAY_MS;
        this.schedule();
    }

    bindRun(run: AgentTurnStarted): void {
        if (!this.pending) return;
        this.runId = run.run_id;
        this.locale = run.locale;
        if (terminalStatuses.has(run.status)) this.stop();
    }

    observe(events: AgentRunEvent[]): void {
        if (!this.pending || this.runId === null) return;
        for (const event of events.filter((item) => item.run_id === this.runId).sort((a, b) => a.sequence - b.sequence)) {
            if (event.sequence <= this.sequence) continue;
            this.sequence = event.sequence;
            if (terminalEvents.has(event.type) && event.data.research_flow_id == null) {
                this.stop();
                return;
            }
            this.readProgress(event);
        }
    }

    /** Transcripts arrive during both user speech and provider playback. Leave room for both. */
    pauseForSpeech(): void {
        this.quietUntil = Date.now() + SPEECH_QUIET_MS;
    }

    stop(): void {
        if (this.timer !== null) clearTimeout(this.timer);
        this.timer = null;
        this.pending = false;
        this.update = null;
    }

    private readProgress(event: AgentRunEvent): void {
        const it = this.locale.toLowerCase().startsWith('it');
        if (event.type === 'research.planned' && Array.isArray(event.data.tasks)) {
            for (const task of event.data.tasks.slice(0, 10)) {
                if (task && typeof task === 'object' && 'id' in task && 'question' in task && typeof task.id === 'number') {
                    const question = shortText(task.question);
                    if (question) this.tasks.set(task.id, question);
                }
            }
        }
        if (event.type === 'retrieval.started') this.phase = 'documents';
        if (event.type === 'retrieval.completed') {
            this.phase = 'planning';
            const count = event.message_params.count;
            if (typeof count === 'number' && Number.isSafeInteger(count) && count >= 0) {
                const content = it
                    ? count === 0 ? 'Questa ricerca non ha trovato fonti documentali pertinenti. Sto proseguendo con i controlli.'
                        : `Questa ricerca ha trovato ${count} ${count === 1 ? 'fonte documentale pertinente' : 'fonti documentali pertinenti'}. Sto proseguendo con i controlli.`
                    : `This search returned ${count} relevant document ${count === 1 ? 'source' : 'sources'}. I’m continuing the checks.`;
                this.queue(`sources:${event.data.research_flow_id ?? 'main'}:${count}`, content, 2);
            }
        }
        if (event.type === 'plan.created' || event.type === 'plan.updated') this.phase = 'planning';
        if (event.type === 'tool.started' || event.type === 'tool.progress') {
            this.phase = 'receiving';
            const tool = shortText(event.message_params.tool);
            if (tool) this.queue(`tool:${tool}`, it ? `Sto chiedendo i dati a ${tool}.` : `I’m requesting data from ${tool}.`, 1);
        }
        if (['tool.completed', 'tool.failed', 'retrieval.failed'].includes(event.type)) {
            this.phase = 'planning';
            // A queued "requesting data" notice is obsolete as soon as the source returns.
            if (this.update?.key.startsWith('tool:')) this.update = null;
        }
        if (event.type === 'research.task') {
            const status = event.data.task_status;
            if (status === 'documents') this.phase = 'documents';
            if (status === 'researching' || status === 'awaiting_tools') this.phase = 'waiting';
            if (status === 'collected' || status === 'verifying') this.phase = 'preparing';
            const question = typeof event.data.research_flow_id === 'number' ? this.tasks.get(event.data.research_flow_id) : null;
            if (question && ['documents', 'researching', 'awaiting_tools'].includes(String(status))) {
                this.queue(`task:${event.data.research_flow_id}:${status}`, it
                    ? `Sto approfondendo questo punto della tua richiesta: ${question}`
                    : `I’m looking into this part of your request: ${question}`, 1);
            }
        }
        if (event.type === 'synthesis.started' && event.data.research_flow_id == null) {
            this.phase = 'preparing';
            this.queue('preparing', it ? 'Sto mettendo insieme le informazioni raccolte e controllando la risposta.'
                : 'I’m putting the collected information together and checking the response.', 3);
        }
    }

    private queue(key: string, content: string, priority: number): void {
        if (!this.announced.has(key) && (!this.update || priority >= this.update.priority)) this.update = { key, content, priority };
    }

    private schedule(): void {
        if (!this.pending) return;
        this.timer = setTimeout(() => this.tick(), Math.max(0, Math.max(this.nextAt, this.quietUntil) - Date.now()));
    }

    private tick(): void {
        this.timer = null;
        if (!this.pending) return;
        if (Date.now() < this.quietUntil) { this.schedule(); return; }
        const update = this.update;
        const content = update?.content ?? this.nextPhrase();
        this.update = null;
        if (update) this.announced.add(update.key);
        this.spoken++;
        // Delivery is best effort and independent from the canonical result.
        void this.speak(content).catch(() => undefined);
        this.nextAt = Date.now() + VOICE_PROGRESS_INTERVAL_MS;
        this.schedule();
    }

    private nextPhrase(): string {
        const category = this.spoken % 3 === 2 ? 'playful' : this.phase;
        const phrases = (this.locale.toLowerCase().startsWith('it') ? italianWaitingPhrases : englishWaitingPhrases)[category];
        const index = this.phraseIndices.get(category) ?? Math.floor(this.random() * phrases.length);
        this.phraseIndices.set(category, (index + 1) % phrases.length);
        return phrases[index];
    }
}

function shortText(value: unknown): string | null {
    if (typeof value !== 'string') return null;
    return value.replace(/[\u0000-\u001f\u007f]/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 140) || null;
}
