/**
 * Transport — layer di rete del widget verso /api/widget/* (port di KITT
 * core/Transport.js). Niente cookie: il canale è token-based (header
 * X-Widget-Key); l'Origin lo aggiunge il browser. Gli errori vengono
 * sollevati come WidgetError con status + codice così la UI può mostrarli
 * (R14: mai trattare un fallimento come successo).
 */
import type { Snapshot, ToolResult, TurnResponse } from '../types';
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
        toolResult: ToolResult | null,
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
