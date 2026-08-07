/**
 * Transport — layer di rete del widget verso /api/widget/* (port di KITT
 * core/Transport.js). Niente cookie: il canale è token-based (header
 * X-Widget-Key); l'Origin lo aggiunge il browser. Gli errori vengono
 * sollevati come WidgetError con status + codice così la UI può mostrarli
 * (R14: mai trattare un fallimento come successo).
 */
import type {
    HostExecResponse,
    HostManifest,
    HostTool,
    HostToolResult,
    Snapshot,
    ToolResult,
    TurnResponse,
    WidgetDocumentPreview,
} from '../types';
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

export interface WidgetSessionSummary {
    id: string;
    status: string;
    summary: string | null;
    page_url: string | null;
    created_at: string;
    updated_at: string;
}

export class Transport {
    private readonly base: string;
    private readonly key: string;
    /** Authenticated-user token: memory-only and intentionally mutable so a
     *  short-lived `wu_…` can be renewed without rebuilding the widget. */
    private userToken: string | null;
    private readonly userTokenUrl: string | null;
    private readonly userAuthenticationConfigured: boolean;
    private userTokenExpiresAt: number | null = null;
    private userAuthenticationPrepared = false;
    private userTokenRefresh: Promise<void> | null = null;
    /** M5.2: session token (wt_…); when set, sent as Authorization: Bearer
     *  instead of X-Widget-Key. Consumed after one use (single-shot). */
    private sessionToken: string | null = null;

    constructor(cfg: WidgetConfig) {
        this.base = (cfg.apiBase ?? '').replace(/\/+$/, '');
        this.key = cfg.key;
        this.userToken = typeof cfg.userToken === 'string' && cfg.userToken.trim() !== ''
            ? cfg.userToken.trim()
            : null;
        this.userTokenUrl = typeof cfg.userTokenUrl === 'string' && cfg.userTokenUrl.trim() !== ''
            ? cfg.userTokenUrl.trim()
            : null;
        this.userAuthenticationConfigured = this.userToken !== null || this.userTokenUrl !== null;
    }

    /**
     * Resolve authenticated-user credentials before the first AskMyDocs call.
     * A configured URL is authoritative and is always fetched at boot; a
     * static token remains supported for existing embeds but has no refresh
     * channel. Invalid auth config fails closed instead of silently using pk.
     */
    async prepareUserAuthentication(): Promise<void> {
        await this.ensureUserAuthentication();
    }

    isUserAuthenticationConfigured(): boolean {
        return this.userAuthenticationConfigured;
    }

    /** M5.2: mint a session token via POST /api/widget/session-token.
     *  The token replaces X-Widget-Key on subsequent requests until consumed. */
    async mintSessionToken(sessionId?: string): Promise<{ token: string; expires_at: string }> {
        const res = await this.request('/session-token', {
            method: 'POST',
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
        const res = await this.request(`/setup${query}`, { method: 'GET' });

        return this.parse<Record<string, unknown>>(res);
    }

    async start(snapshot: Snapshot, message: string | null): Promise<TurnResponse> {
        const res = await this.request('/sessions/start', {
            method: 'POST',
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
        const res = await this.request(`/sessions/${encodeURIComponent(sessionId)}/step`, {
            method: 'POST',
            body: JSON.stringify({ snapshot, message, tool_result: toolResult }),
        });

        return this.parse<TurnResponse>(res);
    }

    async cancel(sessionId: string): Promise<void> {
        await this.request(`/sessions/${encodeURIComponent(sessionId)}/cancel`, {
            method: 'POST',
        });
    }

    async listSessions(page = 1): Promise<{
        data: WidgetSessionSummary[];
        meta: { current_page: number; last_page: number; per_page: number; total: number };
    }> {
        const res = await this.request(`/sessions?page=${page}`, {
            method: 'GET',
        });

        return this.parse(res);
    }

    /**
     * Explicit restore contract. A 204 means the authenticated identity has no
     * open session; it is distinct from a network/auth failure.
     */
    async currentSession(): Promise<WidgetSessionSummary | null> {
        const res = await this.request('/sessions/current', { method: 'GET' });
        if (res.status === 204) {
            return null;
        }
        const payload = await this.parse<{ data: WidgetSessionSummary }>(res);

        return payload.data;
    }

    async replay(sessionId: string): Promise<{
        steps: Array<{ step_index: number; kind: string; tool: string | null; args_json: Record<string, unknown> | null }>;
    }> {
        const res = await this.request(`/sessions/${encodeURIComponent(sessionId)}/replay`, { method: 'GET' });

        return this.parse(res);
    }

    /** Full indexed content of a document that was cited in this session. */
    async fetchCitationDocument(
        sessionId: string,
        documentId: number,
        signal?: AbortSignal,
    ): Promise<WidgetDocumentPreview> {
        const res = await this.request(
            `/sessions/${encodeURIComponent(sessionId)}/documents/${encodeURIComponent(String(documentId))}/preview`,
            { method: 'GET', cache: 'no-store', signal },
        );

        return this.parse<WidgetDocumentPreview>(res);
    }

    /** M4: chiama POST /sessions/{id}/exec-tool per i tool BE. */
    async execTool(
        sessionId: string,
        tool: string,
        args: Record<string, unknown>,
    ): Promise<ExecToolResponse> {
        const res = await this.request(`/sessions/${encodeURIComponent(sessionId)}/exec-tool`, {
            method: 'POST',
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
            const res = await this.fetchWithTimeout(hostManifestUrl, {
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
            res = await this.fetchWithTimeout(hostExecUrl, {
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

    /**
     * AskMyDocs request with authenticated-user lifecycle management.
     *
     * - obtains/renews `wu_…` before building headers;
     * - retries exactly once only for the canonical 401
     *   `user_token_invalid`, and only when a refresh URL exists;
     * - never falls back to `X-Widget-Key` when user auth was configured.
     */
    private async request(path: string, init: RequestInit): Promise<Response> {
        await this.ensureUserAuthentication();

        let response = await this.fetchWithTimeout(this.url(path), {
            ...init,
            headers: this.headers(),
        });

        if (
            this.userTokenUrl !== null &&
            this.userToken !== null &&
            await this.isInvalidUserTokenResponse(response)
        ) {
            await this.refreshUserToken();
            response = await this.fetchWithTimeout(this.url(path), {
                ...init,
                headers: this.headers(),
            });
        }

        return response;
    }

    /**
     * Initial acquisition + just-in-time early renewal. The 30-second margin
     * avoids starting a request with a token that expires while in flight.
     */
    private async ensureUserAuthentication(): Promise<void> {
        if (!this.userAuthenticationConfigured) {
            return;
        }

        if (!this.userAuthenticationPrepared) {
            if (this.userTokenUrl !== null) {
                await this.refreshUserToken();
            } else {
                this.assertValidUserToken(this.userToken);
            }
            this.userAuthenticationPrepared = true;

            return;
        }

        if (
            this.userTokenUrl !== null &&
            this.userTokenExpiresAt !== null &&
            this.userTokenExpiresAt <= Date.now() + Transport.USER_TOKEN_REFRESH_MARGIN_MS
        ) {
            await this.refreshUserToken();
        }

        // Defence in depth: authenticated mode must never degrade to pk mode.
        this.assertValidUserToken(this.userToken);
    }

    private async refreshUserToken(): Promise<void> {
        if (this.userTokenUrl === null) {
            throw new WidgetError(
                'Il token utente è scaduto e non è configurato alcun userTokenUrl per rinnovarlo.',
                401,
                'user_token_refresh_unavailable',
            );
        }
        if (this.userTokenRefresh !== null) {
            return this.userTokenRefresh;
        }

        this.userTokenRefresh = this.fetchUserToken();
        try {
            await this.userTokenRefresh;
        } finally {
            this.userTokenRefresh = null;
        }
    }

    private async fetchUserToken(): Promise<void> {
        const tokenUrl = this.resolveSameOriginUserTokenUrl(this.userTokenUrl as string);
        const response = await this.fetchWithTimeout(tokenUrl, {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { Accept: 'application/json' },
        });
        const data = await this.readJsonObject(response);

        if (!response.ok) {
            const message =
                (typeof data.message === 'string' && data.message) ||
                (typeof data.error === 'string' && data.error) ||
                `Impossibile ottenere il token utente (${response.status}).`;
            throw new WidgetError(
                message,
                response.status,
                typeof data.error === 'string' ? data.error : 'user_token_fetch_failed',
            );
        }

        const token = typeof data.token === 'string' ? data.token.trim() : '';
        const expiresAt = typeof data.expires_at === 'string' ? Date.parse(data.expires_at) : Number.NaN;
        if (!token.startsWith('wu_') || !Number.isFinite(expiresAt) || expiresAt <= Date.now()) {
            throw new WidgetError(
                'userTokenUrl ha restituito una risposta non valida: sono richiesti token wu_ ed expires_at futuro.',
                response.status,
                'user_token_response_invalid',
            );
        }

        this.userToken = token;
        this.userTokenExpiresAt = expiresAt;
    }

    private resolveSameOriginUserTokenUrl(configuredUrl: string): string {
        let resolved: URL;
        try {
            resolved = new URL(configuredUrl, window.location.href);
        } catch {
            throw new WidgetError(
                'userTokenUrl non è un URL valido.',
                0,
                'user_token_url_invalid',
            );
        }
        if (resolved.origin !== window.location.origin) {
            throw new WidgetError(
                'userTokenUrl deve appartenere alla stessa origine della pagina ospite.',
                0,
                'user_token_url_cross_origin',
            );
        }

        return resolved.toString();
    }

    private assertValidUserToken(token: string | null): void {
        if (token === null || !token.startsWith('wu_')) {
            throw new WidgetError(
                'Autenticazione utente configurata senza un token wu_ valido.',
                0,
                'user_token_config_invalid',
            );
        }
    }

    private async isInvalidUserTokenResponse(response: Response): Promise<boolean> {
        if (response.status !== 401) {
            return false;
        }
        const data = await this.readJsonObject(response.clone());

        return data.error === 'user_token_invalid';
    }

    private async readJsonObject(response: Response): Promise<Record<string, unknown>> {
        const text = await response.text();
        if (text === '') {
            return {};
        }
        try {
            const parsed = JSON.parse(text) as unknown;

            return typeof parsed === 'object' && parsed !== null && !Array.isArray(parsed)
                ? parsed as Record<string, unknown>
                : {};
        } catch {
            return {};
        }
    }

    /** Timeout di rete per OGNI richiesta widget (ms). */
    private static readonly TIMEOUT_MS = 30_000;
    private static readonly USER_TOKEN_REFRESH_MARGIN_MS = 30_000;

    /**
     * #17 — fetch con AbortController + timeout. Senza, una risposta stallata
     * (proxy che tiene aperta la connessione, retry lato server lungo) non si
     * risolve MAI: la guard del Bridge resta busy=true e il widget diventa
     * permanentemente non responsivo senza errore. Col timeout la fetch viene
     * abortita → WidgetError 'timeout' → la guard mostra l'errore e resetta busy.
     */
    private async fetchWithTimeout(input: string, init: RequestInit = {}): Promise<Response> {
        const controller = new AbortController();
        const externalSignal = init.signal;
        const abortFromCaller = () => controller.abort(externalSignal?.reason);
        if (externalSignal?.aborted) {
            abortFromCaller();
        } else {
            externalSignal?.addEventListener('abort', abortFromCaller, { once: true });
        }
        const timer = setTimeout(() => controller.abort(), Transport.TIMEOUT_MS);
        try {
            return await fetch(input, { ...init, signal: controller.signal });
        } catch (error) {
            if (error instanceof DOMException && error.name === 'AbortError') {
                // A source-viewer navigation/close intentionally aborts stale
                // reads; preserve AbortError so the viewer can discard it
                // silently. Timeout remains a visible transport failure.
                if (externalSignal?.aborted) {
                    throw error;
                }
                throw new WidgetError('La richiesta è scaduta. Riprova.', 0, 'timeout');
            }
            throw error;
        } finally {
            clearTimeout(timer);
            externalSignal?.removeEventListener('abort', abortFromCaller);
        }
    }

    private url(path: string): string {
        return `${this.base}/api/widget${path}`;
    }

    /** A deliberately minted single-use session token (`wt_`) has precedence
     *  for exactly the next request. The reusable authenticated-user token
     *  (`wu_`) resumes afterwards; only an unauthenticated embed falls back
     *  to public-key mode. */
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
        if (this.userToken) {
            return {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                Authorization: `Bearer ${this.userToken}`,
            };
        }
        if (this.userAuthenticationConfigured) {
            throw new WidgetError(
                'Il widget autenticato non dispone di un token utente valido.',
                401,
                'user_token_missing',
            );
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
