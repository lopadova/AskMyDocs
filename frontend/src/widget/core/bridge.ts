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
import type { Citation, HostTool, HostToolResult, Snapshot, ToolCall, TurnResponse, WidgetConfig } from '../types';
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
    /**
     * M4.8 — feedback visivo agentico. `move_cursor` → freccia sul target;
     * `tour_step` → backdrop+spotlight+tooltip; un nuovo messaggio utente o un
     * esito terminale (done/blocked) → clear. `target` è `null` quando il nome
     * non risolve nessun elemento (il loop prosegue comunque).
     */
    onPointAt: (target: HTMLElement | null, name: string) => void;
    onTourStep: (target: HTMLElement | null, message: string, index: number, total: number) => void;
    onClearOverlay: () => void;
}

const MAX_AUTO_STEPS = 12;

/**
 * F1.7 — riconosce un host tool nella risposta dell'orchestrator. L'orchestrator
 * marca la tool_call con `execution:"host"` e/o `is_host_tool:true`; basta uno dei
 * due per instradarla sul percorso host (FE-proxied verso l'app ospite).
 */
function isHostTool(call: ToolCall): boolean {
    return call.execution === 'host' || call.is_host_tool === true;
}

export class Bridge {
    private readonly transport: Transport;
    private readonly executor = new Executor();
    private readonly skill?: string;
    private sessionId: string | null = null;
    private busy = false;
    /**
     * F1.7 — host tools risolti dal manifest dell'app ospite, recuperati una
     * sola volta all'avvio della sessione e allegati allo snapshot di start.
     * `null` = non ancora recuperati; `[]` = recuperati ma vuoti (o nessun
     * manifest configurato / fetch fallito → degrado solo-RAG).
     */
    private hostTools: HostTool[] | null = null;

    constructor(
        private readonly cfg: WidgetConfig,
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
        // M4.8: un nuovo messaggio utente chiude qualsiasi overlay agentico
        // (freccia/tour) lasciato dal turno precedente.
        this.events.onClearOverlay();
        await this.guard(async () => {
            if (this.sessionId) {
                const snapshot = this.snapshotWithHostTools();
                const res = await this.transport.step(this.sessionId, snapshot, message, null);
                await this.handle(res, 0);

                return;
            }
            // Primo turno: risolvi gli host tools (manifest dell'app ospite) e
            // allegali allo snapshot di start così l'orchestrator li passa all'LLM.
            const snapshot = await this.startSnapshot();
            const res = await this.transport.start(snapshot, message);
            await this.handle(res, 0);
        });
    }

    /**
     * F1.7 — costruisce lo snapshot di start, recuperando una sola volta gli host
     * tools dal manifest dell'app ospite (`data-host-manifest-url`) e allegandoli
     * come `snapshot.host_tools`. Non bloccante: se non c'è manifest o il fetch
     * fallisce, `host_tools` resta omesso e il widget procede in solo-RAG.
     */
    private async startSnapshot(): Promise<Snapshot> {
        if (this.hostTools === null) {
            this.hostTools = this.cfg.hostManifestUrl
                ? await this.transport.fetchHostManifest(this.cfg.hostManifestUrl)
                : [];
        }

        return this.snapshotWithHostTools();
    }

    /**
     * #8 — costruisce lo snapshot ALLEGANDO sempre gli host tools già risolti.
     * L'orchestrator rilegge `snapshot.host_tools` ad OGNI turno (non solo il
     * primo): se i turni successivi inviassero uno snapshot "nudo", gli host
     * tool sparirebbero dal turno 2 in poi e l'LLM non potrebbe più chiamarli
     * (validator → "tool non abilitato" → consecutive_errors → blocked).
     */
    private snapshotWithHostTools(): Snapshot {
        const snapshot = buildSnapshot();
        if (this.hostTools && this.hostTools.length > 0) {
            snapshot.host_tools = this.hostTools;
        }

        return snapshot;
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
            this.events.onClearOverlay();
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
            // M4.8: fine task → chiudi l'overlay agentico (es. fine tour).
            this.events.onClearOverlay();
            this.events.onDone(String(call.args.summary ?? 'Done.'));

            return;
        }
        if (call.tool === 'report_blocked') {
            this.events.onClearOverlay();
            this.events.onBlocked(String(call.args.reason ?? 'Blocked.'));

            return;
        }
        // #16 — il cap MAX_AUTO_STEPS vale per OGNI tool che continua il loop
        // (host, BE, DOM), non solo i DOM tool. I percorsi host/BE NON devono
        // resettare la profondità a 0: altrimenti il cap client (12) non scatta
        // mai e una catena di tool BE/host gira fino al cap server (~100) →
        // amplificazione costo/DoS da un singolo messaggio utente.
        if (depth >= MAX_AUTO_STEPS) {
            this.events.onError('Reached the maximum number of automatic actions.');

            return;
        }

        if (isHostTool(call)) {
            // F1.7: host tool dell'app ospite → instrada all'endpoint exec dell'host
            // (FE-proxied), renderizza l'artifact e reinietta il risultato in /step.
            // NON passa per l'executor DOM né per /exec-tool.
            await this.handleHostTool(call, depth);
            return;
        }
        if (call.is_be_tool) {
            // M4: tool server-side → chiama /exec-tool, renderizza artifact, poi
            // decide se continuare il loop o attendere l'utente.
            await this.handleBeTool(call, depth);
            return;
        }

        const execute = async (): Promise<void> => {
            this.events.onAction(call.tool, call.args);
            // M4.8: i tool visivi attivano l'overlay agentico PRIMA di eseguire il
            // runner DOM. L'esecuzione (scroll/focus) e la reiniezione del
            // tool_result restano invariate: il loop prosegue normalmente.
            this.maybeEmitOverlay(call);
            const result = await this.executor.run(call.tool, call.args);
            const snapshot = this.snapshotWithHostTools();
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
     * M4.8 — intercetta i tool visivi (`move_cursor` / `tour_step`) e attiva
     * l'overlay agentico tramite gli eventi del Bridge. Risolve il target con la
     * stessa logica DOM dell'executor (`resolveTarget`), così l'overlay indica
     * esattamente l'elemento che il runner tocca. Non altera il loop: l'executor
     * esegue comunque il tool (scroll/focus) e il tool_result viene reiniettato.
     */
    private maybeEmitOverlay(call: ToolCall): void {
        if (call.tool === 'move_cursor') {
            const name = String(call.args.target ?? '');
            this.events.onPointAt(this.executor.resolveTarget(name), name);

            return;
        }
        if (call.tool === 'tour_step') {
            const name = String(call.args.highlight_target ?? '');
            const message = String(call.args.message ?? '');
            const index = Number(call.args.step_index ?? 0);
            const total = Number(call.args.step_total ?? 1);
            this.events.onTourStep(
                this.executor.resolveTarget(name),
                message,
                Number.isFinite(index) ? index : 0,
                Number.isFinite(total) ? total : 1,
            );
        }
    }

    /**
     * M4: gestisce un tool BE (AiTool) chiamando /exec-tool.
     * Dopo aver ricevuto l'artifact:
     *   - has_results=false → manda un auto-msg al LLM ("Il tool non ha trovato risultati")
     *   - has_results=true, interactionMode='selection' → WaitingUser, attende selezione
     *   - has_results=true, interactionMode='view' → WaitingUser, attende prossimo messaggio
     */
    private async handleBeTool(call: ToolCall, depth: number): Promise<void> {
        try {
            const result = await this.transport.execTool(
                this.sessionId as string,
                call.tool,
                call.args,
            );

            // Emetti l'artifact nella UI
            this.events.onArtifact(result.artifact, result.has_results, result.interaction_mode);

            if (!result.has_results) {
                // Il tool non ha prodotto risultati → auto-msg al LLM per continuare.
                // #16 — depth+1 (NON 0): mantiene il cap MAX_AUTO_STEPS attraverso
                // la catena BE.
                const autoMsg = `Il tool "${call.tool}" non ha trovato risultati per la query richiesta.`;
                const snapshot = this.snapshotWithHostTools();
                const next = await this.transport.step(this.sessionId as string, snapshot, autoMsg, null);
                await this.handle(next, depth + 1);
            }
            // has_results=true → la UI mostra l'artifact e il bridge attende il prossimo
            // messaggio utente (se interactionMode='selection' l'utente seleziona una riga;
            // se 'view' l'utente può scrivere un nuovo messaggio).
        } catch (error) {
            const message = error instanceof WidgetError || error instanceof Error ? error.message : String(error);
            this.events.onError(`BE tool execution failed: ${message}`);
        }
    }

    /**
     * F1.7 — gestisce un host tool (definito ed eseguito dall'app ospite, modo
     * FE-proxied). Esegue `POST {hostExecUrl}` con cookie di sessione + CSRF,
     * renderizza l'artifact restituito riusando lo stesso renderer dei tool BE,
     * poi reinietta il risultato in /step come `tool_result.execution="host"` e
     * continua il loop così l'LLM può proseguire.
     *
     * Esiti gestiti:
     *   - ok:true  → render artifact + tool_result ok:true → loop
     *   - ok:false → messaggio d'errore nel thread + tool_result ok:false → loop
     *                (l'LLM reagisce; la sessione non resta appesa)
     *   - errore di rete / config mancante → errore nel thread + tool_result
     *                ok:false → loop (best effort, mai sessione appesa)
     */
    private async handleHostTool(call: ToolCall, depth: number): Promise<void> {
        const sessionId = this.sessionId as string;
        const hostExecUrl = (this.cfg.hostExecUrl ?? '').trim();

        if (hostExecUrl === '') {
            // Config incompleta: l'LLM ha chiamato un host tool ma il widget non sa
            // dove instradarlo. Segnala e reinietta un fallimento così non si blocca.
            this.events.onError(`Host tool "${call.tool}" non eseguibile: data-host-exec-url non configurato.`);
            await this.reinjectHostResult(sessionId, {
                tool: call.tool,
                execution: 'host',
                ok: false,
                error: 'host_exec_url_missing',
                message: 'Host exec URL is not configured on the embed.',
            }, depth);

            return;
        }

        try {
            const result = await this.transport.execHostTool(
                hostExecUrl,
                call.tool,
                call.args,
                sessionId,
                (this.cfg.csrfToken ?? '').trim(),
            );

            if (result.ok && result.artifact) {
                const artifact: Artifact = {
                    componentType: result.artifact.componentType,
                    componentProps: result.artifact.componentProps ?? {},
                };
                // Riusa il renderer artifact esistente (interactionMode dal componentProps).
                const interactionMode = String(result.artifact.componentProps?.interactionMode ?? 'view');
                this.events.onArtifact(artifact, true, interactionMode);

                await this.reinjectHostResult(sessionId, {
                    tool: call.tool,
                    execution: 'host',
                    ok: true,
                    artifact: result.artifact,
                }, depth);

                return;
            }

            // ok:false (o ok:true senza artifact): mostra l'errore e reinietta il
            // fallimento così l'LLM può correggere il tiro.
            const errorMessage = result.message || result.error || 'Host tool returned no result.';
            this.events.onError(`Host tool "${call.tool}" failed: ${errorMessage}`);
            await this.reinjectHostResult(sessionId, {
                tool: call.tool,
                execution: 'host',
                ok: false,
                error: result.error ?? 'host_tool_failed',
                message: errorMessage,
            }, depth);
        } catch (error) {
            // Errore di trasporto/rete: best effort, reinietta comunque un ok:false.
            const message = error instanceof WidgetError || error instanceof Error ? error.message : String(error);
            this.events.onError(`Host tool execution failed: ${message}`);
            await this.reinjectHostResult(sessionId, {
                tool: call.tool,
                execution: 'host',
                ok: false,
                error: 'host_exec_network_error',
                message,
            }, depth);
        }
    }

    /**
     * F1.7 — invia il `tool_result` host all'orchestrator via /step e continua il
     * loop. Isolato in un metodo perché la reiniezione va fatta in tutti i rami
     * (ok / ok:false / errore di rete) per non lasciare la sessione appesa. Se anche
     * lo /step fallisce, l'errore risale alla guard() che lo mostra nel thread.
     */
    private async reinjectHostResult(sessionId: string, toolResult: HostToolResult, depth: number): Promise<void> {
        const snapshot = this.snapshotWithHostTools();
        const next = await this.transport.step(sessionId, snapshot, null, toolResult);
        // #16 — depth+1 (NON 0): la reiniezione host mantiene il cap del loop.
        await this.handle(next, depth + 1);
    }
}
