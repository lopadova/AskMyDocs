/**
 * Bridge — la state machine del loop ReAct lato widget (port di KITT
 * core/BotBridge.js). Coordina Transport (rete) ed Executor (DOM) ed emette
 * eventi che la UI consuma:
 *
 *   user message → snapshot → start/step → risposta:
 *     - message  → onAnswer (testo + citazioni)
 *     - tool_call DOM → esegue, ri-snapshot, step → loop (cap MAX_AUTO_STEPS)
 *     - ask_user → onAsk (attende messaggio utente)
 *     - report_done/blocked → terminale
 *   le azioni con confirmation_required passano da onConfirm.
 */
import { buildSnapshot } from '../dom/snapshot';
import type { Citation, ToolCall, TurnResponse, WidgetConfig } from '../types';
import { Executor } from './executor';
import { Transport, WidgetError } from './transport';

/** Artifact renderizzabile nella chat (spec §5.3). */
export interface Artifact {
    componentType: string;
    componentProps: Record<string, unknown>;
}

/** Risposta del backend a /exec-tool (spec §7.3). */
export interface ExecToolResponse {
    artifact: Artifact;
    has_results: boolean;
    interaction_mode: string;
}

export interface BridgeEvents {
    onBusy: (busy: boolean) => void;
    onAnswer: (text: string, citations: Citation[]) => void;
    onBotText: (text: string) => void;
    onAction: (tool: string, args: Record<string, unknown>) => void;
    onAsk: (question: string, options: string[]) => void;
    onDone: (summary: string) => void;
    onBlocked: (reason: string) => void;
    onError: (message: string) => void;
    onConfirm: (toolCall: ToolCall, accept: () => void, reject: () => void) => void;
    /** M4: emette l'artifact restituito da un tool BE nella chat. */
    onArtifact: (artifact: Artifact, hasResults: boolean, interactionMode: string) => void;
}

const MAX_AUTO_STEPS = 12;

export class Bridge {
    private readonly transport: Transport;
    private readonly executor = new Executor();
    private readonly skill?: string;
    private sessionId: string | null = null;
    private busy = false;

    constructor(
        cfg: WidgetConfig,
        private readonly events: BridgeEvents,
    ) {
        this.transport = new Transport(cfg);
        this.skill = cfg.skill;
    }

    isBusy(): boolean {
        return this.busy;
    }

    /**
     * Carica il manifest da GET /api/widget/setup (skill, tool, e — nuovo — il
     * `theme` server). Resiliente (R14 lato widget): un fallimento NON rompe la
     * chat, il widget resta sul tema inline+default. Ritorna l'oggetto setup o
     * null se la chiamata fallisce.
     */
    async loadSetup(): Promise<Record<string, unknown> | null> {
        try {
            return await this.transport.setup(this.skill);
        } catch {
            return null;
        }
    }

    async sendUserMessage(message: string): Promise<void> {
        if (this.busy) {
            return;
        }
        await this.guard(async () => {
            const snapshot = buildSnapshot();
            const res = this.sessionId
                ? await this.transport.step(this.sessionId, snapshot, message, null)
                : await this.transport.start(snapshot, message);
            await this.handle(res, 0);
        });
    }

    async cancel(): Promise<void> {
        if (this.sessionId) {
            try {
                await this.transport.cancel(this.sessionId);
            } catch {
                /* best effort */
            }
        }
    }

    private async guard(fn: () => Promise<void>): Promise<void> {
        this.busy = true;
        this.events.onBusy(true);
        try {
            await fn();
        } catch (error) {
            const message = error instanceof WidgetError || error instanceof Error ? error.message : String(error);
            this.events.onError(message);
        } finally {
            this.busy = false;
            this.events.onBusy(false);
        }
    }

    private async handle(res: TurnResponse, depth: number): Promise<void> {
        this.sessionId = res.session.id;

        if (res.type === 'message') {
            this.events.onAnswer(res.answer ?? '', res.citations ?? []);

            return;
        }
        if (res.type === 'blocked') {
            this.events.onBlocked(res.reason ?? 'Blocked.');

            return;
        }

        const call = res.tool_call;
        if (!call) {
            this.events.onError('Empty tool call from the assistant.');

            return;
        }
        if (res.bot_message) {
            this.events.onBotText(res.bot_message);
        }

        if (call.tool === 'ask_user') {
            const options = Array.isArray(call.args.options) ? call.args.options.map(String) : [];
            this.events.onAsk(String(call.args.question ?? ''), options);

            return;
        }
        if (call.tool === 'report_done') {
            this.events.onDone(String(call.args.summary ?? 'Done.'));

            return;
        }
        if (call.tool === 'report_blocked') {
            this.events.onBlocked(String(call.args.reason ?? 'Blocked.'));

            return;
        }
        if (call.is_be_tool) {
            // M4: tool server-side → chiama /exec-tool, renderizza artifact, poi
            // decide se continuare il loop o attendere l'utente.
            await this.handleBeTool(call);
            return;
        }
        if (depth >= MAX_AUTO_STEPS) {
            this.events.onError('Reached the maximum number of automatic actions.');

            return;
        }

        const execute = async (): Promise<void> => {
            this.events.onAction(call.tool, call.args);
            const result = await this.executor.run(call.tool, call.args);
            const snapshot = buildSnapshot();
            const next = await this.transport.step(this.sessionId as string, snapshot, null, result);
            await this.handle(next, depth + 1);
        };

        if (call.confirmation_required) {
            this.events.onConfirm(
                call,
                () => {
                    void this.guard(execute);
                },
                () => {
                    /* rejected — no-op, the user can type a new instruction */
                },
            );

            return;
        }

        await execute();
    }

    /**
     * M4: gestisce un tool BE (AiTool) chiamando /exec-tool.
     * Dopo aver ricevuto l'artifact:
     *   - has_results=false → manda un auto-msg al LLM ("Il tool non ha trovato risultati")
     *   - has_results=true, interactionMode='selection' → WaitingUser, attende selezione
     *   - has_results=true, interactionMode='view' → WaitingUser, attende prossimo messaggio
     */
    private async handleBeTool(call: ToolCall): Promise<void> {
        try {
            const result = await this.transport.execTool(
                this.sessionId as string,
                call.tool,
                call.args,
            );

            // Emetti l'artifact nella UI
            this.events.onArtifact(result.artifact, result.has_results, result.interaction_mode);

            if (!result.has_results) {
                // Il tool non ha prodotto risultati → auto-msg al LLM per continuare
                const autoMsg = `Il tool "${call.tool}" non ha trovato risultati per la query richiesta.`;
                const snapshot = buildSnapshot();
                const next = await this.transport.step(this.sessionId as string, snapshot, autoMsg, null);
                await this.handle(next, 0);
            }
            // has_results=true → la UI mostra l'artifact e il bridge attende il prossimo
            // messaggio utente (se interactionMode='selection' l'utente seleziona una riga;
            // se 'view' l'utente può scrivere un nuovo messaggio).
        } catch (error) {
            const message = error instanceof WidgetError || error instanceof Error ? error.message : String(error);
            this.events.onError(`BE tool execution failed: ${message}`);
        }
    }
}
