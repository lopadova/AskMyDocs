/**
 * Transport — layer di rete del widget verso /api/widget/* (port di KITT
 * core/Transport.js). Niente cookie: il canale è token-based (header
 * X-Widget-Key); l'Origin lo aggiunge il browser. Gli errori vengono
 * sollevati come WidgetError con status + codice così la UI può mostrarli
 * (R14: mai trattare un fallimento come successo).
 */
import type { HostExecResponse, HostManifest, HostTool, HostToolResult, Snapshot, ToolResult, TurnResponse } from '../types';
import type { WidgetConfig } from '../types';
import type { ExecToolResponse } from './bridge';

export class WidgetError extends Error {
    constructor(
        message: string,
        public readonly status: number,
        public readonly code?: string,
    ) {
        super(message);
        this.name = 'WidgetError';
    }
}

export class Transport {
    private readonly base: string;
    private readonly key: string;
    /** M5.2: session token (wt_…); when set, sent as Authorization: Bearer
     *  instead of X-Widget-Key. Consumed after one use (single-shot). */
    private sessionToken: string | null = null;

    constructor(cfg: WidgetConfig) {
        this.base = (cfg.apiBase ?? '').replace(/\/+$/, '');
        this.key = cfg.key;
    }

    /** M5.2: mint a session token via POST /api/widget/session-token.
     *  The token replaces X-Widget-Key on subsequent requests until consumed. */
    async mintSessionToken(sessionId?: string): Promise<{ token: string; expires_at: string }> {
        const res = await fetch(this.url('/session-token'), {
            method: 'POST',
            headers: this.pkHeaders(),
            body: JSON.stringify(sessionId ? { session_id: sessionId } : {}),
        });

        const result = await this.parse<{ token: string; expires_at: string }>(res);
        this.sessionToken = result.token;

        return result;
    }

    /** M5.2: set an externally-obtained session token (e.g. from proxy mode B). */
    setSessionToken(token: string): void {
        this.sessionToken = token;
    }

    /** M5.13: expose current session token for test inspection. */
    getSessionToken(): string | null {
        return this.sessionToken;
    }

    async setup(skill?: string): Promise<Record<string, unknown>> {
        const query = skill ? `?skill=${encodeURIComponent(skill)}` : '';
        const res = await fetch(this.url(`/setup${query}`), { method: 'GET', headers: this.headers() });

        return this.parse<Record<string, unknown>>(res);
    }

    async start(snapshot: Snapshot, message: string | null): Promise<TurnResponse> {
        const res = await fetch(this.url('/sessions/start'), {
            method: 'POST',
            headers: this.headers(),
            body: JSON.stringify({ snapshot, message, page_url: location.href }),
        });

        return this.parse<TurnResponse>(res);
    }

    async step(
        sessionId: string,
        snapshot: Snapshot,
        message: string | null,
        toolResult: ToolResult | HostToolResult | null,
    ): Promise<TurnResponse> {
        const res = await fetch(this.url(`/sessions/${encodeURIComponent(sessionId)}/step`), {
            method: 'POST',
            headers: this.headers(),
            body: JSON.stringify({ snapshot, message, tool_result: toolResult }),
        });

        return this.parse<TurnResponse>(res);
    }

    async cancel(sessionId: string): Promise<void> {
        await fetch(this.url(`/sessions/${encodeURIComponent(sessionId)}/cancel`), {
            method: 'POST',
            headers: this.headers(),
        });
    }

    /** M4: chiama POST /sessions/{id}/exec-tool per i tool BE. */
    async execTool(
        sessionId: string,
        tool: string,
        args: Record<string, unknown>,
    ): Promise<ExecToolResponse> {
        const res = await fetch(this.url(`/sessions/${encodeURIComponent(sessionId)}/exec-tool`), {
            method: 'POST',
            headers: this.headers(),
            body: JSON.stringify({ tool, args }),
        });

        return this.parse<ExecToolResponse>(res);
    }

    /**
     * F1.7 — Recupera il manifest host tools dall'app ospite.
     * `fetch(hostManifestUrl, { credentials: 'same-origin' })`, si aspetta
     * `{ schema_version, tools: [...] }`. Non bloccante: su qualsiasi errore
     * (rete, status non-OK, JSON malformato, shape inattesa) ritorna `[]` e logga,
     * così il widget continua a funzionare in solo-RAG.
     */
    async fetchHostManifest(hostManifestUrl: string): Promise<HostTool[]> {
        try {
            const res = await fetch(hostManifestUrl, {
                method: 'GET',
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (!res.ok) {
                // eslint-disable-next-line no-console
                console.warn(`[AskMyDocsWidget] host manifest fetch non-OK (${res.status}); continuo in solo-RAG.`);

                return [];
            }
            const data = (await res.json()) as Partial<HostManifest>;
            const tools = Array.isArray(data?.tools) ? data.tools : [];

            // Difesa in profondità: tieni solo le voci con shape host-tool valida.
            return tools.filter(
                (t): t is HostTool =>
                    !!t &&
                    typeof t.name === 'string' &&
                    t.name !== '' &&
                    t.execution === 'host' &&
                    typeof t.parameters === 'object' &&
                    t.parameters !== null,
            );
        } catch (error) {
            const message = error instanceof Error ? error.message : String(error);
            // eslint-disable-next-line no-console
            console.warn(`[AskMyDocsWidget] host manifest fetch fallito: ${message}; continuo in solo-RAG.`);

            return [];
        }
    }

    /**
     * F1.7 — Esegue un host tool sull'app ospite (FE-proxied). A differenza di
     * /exec-tool (canale token-based verso AskMyDocs), questa chiamata va all'app
     * ospite stessa: usa il cookie di sessione (`credentials: 'same-origin'`) e
     * l'header `X-CSRF-TOKEN` (pattern Laravel). Non passa per ResolveWidgetKey.
     *
     * Ritorna sempre il body parsato (`{ ok, artifact | error, message }`): un
     * `ok:false` 422 dall'host NON è un errore di trasporto, va gestito dal Bridge
     * inviando comunque un tool_result così l'LLM può reagire (no sessione appesa).
     * Solleva WidgetError solo su fallimento di rete o body non-JSON con status non-OK
     * e senza payload `ok`.
     */
    async execHostTool(
        hostExecUrl: string,
        tool: string,
        args: Record<string, unknown>,
        sessionRef: string,
        csrfToken: string,
    ): Promise<HostExecResponse> {
        const headers: Record<string, string> = {
            'Content-Type': 'application/json',
            Accept: 'application/json',
        };
        if (csrfToken !== '') {
            headers['X-CSRF-TOKEN'] = csrfToken;
        }

        let res: Response;
        try {
            res = await fetch(hostExecUrl, {
                method: 'POST',
                headers,
                credentials: 'same-origin',
                body: JSON.stringify({ tool, args, session_ref: sessionRef }),
            });
        } catch (error) {
            const message = error instanceof Error ? error.message : String(error);
            throw new WidgetError(`Host tool request failed: ${message}`, 0, 'host_exec_network_error');
        }

        const text = await res.text();
        let data: Record<string, unknown> = {};
        try {
            data = text ? (JSON.parse(text) as Record<string, unknown>) : {};
        } catch {
            data = {};
        }

        // L'host può rispondere 200 con ok:true o 422 con ok:false: in entrambi i casi
        // il body porta la chiave `ok` ed è un esito di dominio, non un errore di rete.
        if (typeof data.ok === 'boolean') {
            return data as unknown as HostExecResponse;
        }

        // Nessun contratto `ok` riconoscibile e risposta non-OK → errore di trasporto.
        if (!res.ok) {
            const message =
                (typeof data.message === 'string' && data.message) ||
                (typeof data.error === 'string' && data.error) ||
                `Host tool request failed (${res.status}).`;
            throw new WidgetError(message, res.status, typeof data.error === 'string' ? data.error : 'host_exec_error');
        }

        // 2xx ma senza `ok`: normalizziamo a ok:true con artifact eventuale.
        return { ok: true, ...(data as Record<string, unknown>) } as unknown as HostExecResponse;
    }

    private url(path: string): string {
        return `${this.base}/api/widget${path}`;
    }

    /** M5.2: headers with session token (Authorization: Bearer wt_…) when available,
     *  falling back to X-Widget-Key (pk_…) in pk mode. Token is consumed after use. */
    private headers(): Record<string, string> {
        if (this.sessionToken) {
            const token = this.sessionToken;
            this.sessionToken = null; // consume after one request (single-shot)
            return {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                Authorization: `Bearer ${token}`,
            };
        }

        return this.pkHeaders();
    }

    /** Headers using the public key via X-Widget-Key (mode A / browser). */
    private pkHeaders(): Record<string, string> {
        return {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Widget-Key': this.key,
        };
    }

    private async parse<T>(res: Response): Promise<T> {
        const text = await res.text();
        let data: Record<string, unknown> = {};
        try {
            data = text ? (JSON.parse(text) as Record<string, unknown>) : {};
        } catch {
            data = {};
        }

        if (!res.ok) {
            const message =
                (typeof data.message === 'string' && data.message) ||
                (typeof data.error === 'string' && data.error) ||
                `Request failed (${res.status}).`;
            throw new WidgetError(message, res.status, typeof data.error === 'string' ? data.error : undefined);
        }

        return data as T;
    }
}
