/**
 * F1.7 — Host tools (modo FE-proxied) end-to-end lato widget.
 *
 * Copre i quattro gate richiesti dalla spec:
 *   (1) fetch manifest → host_tools nello snapshot di start;
 *   (2) esecuzione host tool → POST exec → tool_result reiniettato in /step;
 *   (3) fallback artifact per componentType gescat non nativo;
 *   (4) errore host (ok:false) gestito senza lasciare la sessione appesa.
 *
 * Stub di `fetch` instradato per URL così da ispezionare gli esatti payload
 * inviati a `tools-exec` e a `/step` senza rete reale. Il DOM è quello di jsdom
 * (buildSnapshot legge `document`).
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { Bridge, type BridgeEvents } from './bridge';
import { Transport, WidgetError } from './transport';
import type { WidgetConfig } from '../types';

const API_BASE = 'https://kb.example.com';
const HOST_EXEC_URL = 'https://gescat.example.com/admin/ai/tools-exec';
const HOST_MANIFEST_URL = 'https://gescat.example.com/admin/ai/tools-manifest';

const HOST_TOOL = {
    name: 'articoli__searchArticoli',
    description: 'Cerca articoli per nome/codice/EAN.',
    parameters: { type: 'object', properties: { query: { type: 'string' } }, required: ['query'] },
    execution: 'host' as const,
    returns: 'ui-data-table',
};

interface FetchRecord {
    url: string;
    init: RequestInit;
}

/** Router di fetch per i test: registra ogni chiamata e risponde per URL. */
function installFetchRouter(handlers: Array<{ match: (url: string, init: RequestInit) => boolean; respond: () => Response }>): {
    calls: FetchRecord[];
} {
    const calls: FetchRecord[] = [];
    const mock = vi.fn(async (input: RequestInfo | URL, init: RequestInit = {}) => {
        const url = String(input);
        calls.push({ url, init });
        const handler = handlers.find((h) => h.match(url, init));
        if (!handler) {
            return new Response('{}', { status: 404, headers: { 'Content-Type': 'application/json' } });
        }

        return handler.respond();
    });
    globalThis.fetch = mock as unknown as typeof fetch;

    return { calls };
}

function json(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

/** Eventi del Bridge come spie, con array per i tipi che ci servono per le assert. */
function spyEvents(): { events: BridgeEvents; artifacts: Array<{ componentType: string }>; errors: string[]; answers: string[] } {
    const artifacts: Array<{ componentType: string }> = [];
    const errors: string[] = [];
    const answers: string[] = [];
    const events: BridgeEvents = {
        onBusy: vi.fn(),
        onAnswer: (text) => answers.push(text),
        onBotText: vi.fn(),
        onAction: vi.fn(),
        onAsk: vi.fn(),
        onDone: vi.fn(),
        onBlocked: vi.fn(),
        onError: (m) => errors.push(m),
        onConfirm: vi.fn(),
        onArtifact: (a) => artifacts.push({ componentType: a.componentType }),
        onPointAt: vi.fn(),
        onTourStep: vi.fn(),
        onClearOverlay: vi.fn(),
    };

    return { events, artifacts, errors, answers };
}

const baseCfg: WidgetConfig = {
    key: 'pk_test',
    apiBase: API_BASE,
    hostManifestUrl: HOST_MANIFEST_URL,
    hostExecUrl: HOST_EXEC_URL,
    csrfToken: 'csrf_abc',
};

const originalFetch = globalThis.fetch;

afterEach(() => {
    globalThis.fetch = originalFetch;
    vi.restoreAllMocks();
});

// =========================================================================
// Transport — fetchHostManifest
// =========================================================================

describe('Transport.fetchHostManifest', () => {
    it('fetches the manifest with same-origin credentials and returns valid host tools', async () => {
        const { calls } = installFetchRouter([
            { match: (u) => u === HOST_MANIFEST_URL, respond: () => json({ schema_version: '1.0', tools: [HOST_TOOL] }) },
        ]);

        const tools = await new Transport(baseCfg).fetchHostManifest(HOST_MANIFEST_URL);

        expect(tools).toHaveLength(1);
        expect(tools[0].name).toBe('articoli__searchArticoli');
        expect(calls[0].init.credentials).toBe('same-origin');
        expect(calls[0].init.method).toBe('GET');
    });

    it('filters out entries with invalid host-tool shape', async () => {
        installFetchRouter([
            {
                match: (u) => u === HOST_MANIFEST_URL,
                respond: () =>
                    json({
                        tools: [
                            HOST_TOOL,
                            { name: 'bad__noExecution', description: 'x', parameters: {} }, // execution mancante
                            { name: '', description: 'x', parameters: {}, execution: 'host' }, // name vuoto
                        ],
                    }),
            },
        ]);

        const tools = await new Transport(baseCfg).fetchHostManifest(HOST_MANIFEST_URL);
        expect(tools).toHaveLength(1);
    });

    it('returns [] without throwing on a non-OK manifest response (non-blocking → solo-RAG)', async () => {
        installFetchRouter([{ match: (u) => u === HOST_MANIFEST_URL, respond: () => json({ error: 'forbidden' }, 403) }]);
        const tools = await new Transport(baseCfg).fetchHostManifest(HOST_MANIFEST_URL);
        expect(tools).toEqual([]);
    });

    it('returns [] when the fetch itself rejects (network error)', async () => {
        globalThis.fetch = vi.fn(async () => {
            throw new Error('network down');
        }) as unknown as typeof fetch;
        const tools = await new Transport(baseCfg).fetchHostManifest(HOST_MANIFEST_URL);
        expect(tools).toEqual([]);
    });
});

// =========================================================================
// Transport — execHostTool
// =========================================================================

describe('Transport.execHostTool', () => {
    it('POSTs to the host exec URL with CSRF header, same-origin credentials and {tool,args,session_ref}', async () => {
        const { calls } = installFetchRouter([
            { match: (u) => u === HOST_EXEC_URL, respond: () => json({ ok: true, artifact: { componentType: 'ui-data-table', componentProps: {} } }) },
        ]);

        const res = await new Transport(baseCfg).execHostTool(HOST_EXEC_URL, 'articoli__searchArticoli', { query: 'pera' }, 'ses_public_1', 'csrf_abc');

        expect(res.ok).toBe(true);
        const call = calls[0];
        expect(call.url).toBe(HOST_EXEC_URL);
        expect(call.init.method).toBe('POST');
        expect(call.init.credentials).toBe('same-origin');
        const headers = call.init.headers as Record<string, string>;
        expect(headers['X-CSRF-TOKEN']).toBe('csrf_abc');
        const body = JSON.parse(String(call.init.body));
        expect(body).toEqual({ tool: 'articoli__searchArticoli', args: { query: 'pera' }, session_ref: 'ses_public_1' });
        // Niente X-Widget-Key: questa chiamata va all'app ospite, non ad AskMyDocs.
        expect(headers['X-Widget-Key']).toBeUndefined();
    });

    it('returns the body (does NOT throw) when the host answers ok:false with 422', async () => {
        installFetchRouter([
            { match: (u) => u === HOST_EXEC_URL, respond: () => json({ ok: false, error: 'tool_not_enabled', message: 'Disabled.' }, 422) },
        ]);

        const res = await new Transport(baseCfg).execHostTool(HOST_EXEC_URL, 'x', {}, 'ses_1', 'csrf_abc');
        expect(res.ok).toBe(false);
        expect(res.error).toBe('tool_not_enabled');
    });

    it('throws WidgetError on a network failure', async () => {
        globalThis.fetch = vi.fn(async () => {
            throw new Error('connection refused');
        }) as unknown as typeof fetch;

        await expect(new Transport(baseCfg).execHostTool(HOST_EXEC_URL, 'x', {}, 'ses_1', 'csrf_abc')).rejects.toThrow(WidgetError);
    });
});

// =========================================================================
// Bridge — flusso host tool end-to-end
// =========================================================================

describe('Bridge host-tool flow', () => {
    beforeEach(() => {
        document.body.innerHTML = '<main><h1>Articoli</h1></main>';
    });

    it('attaches manifest tools as snapshot.host_tools on the start call', async () => {
        const { calls } = installFetchRouter([
            { match: (u) => u === HOST_MANIFEST_URL, respond: () => json({ tools: [HOST_TOOL] }) },
            {
                match: (u) => u.endsWith('/sessions/start'),
                respond: () => json({ session: { id: 'ses_1', status: 'active' }, type: 'message', answer: 'ciao' }),
            },
        ]);
        const { events } = spyEvents();

        await new Bridge(baseCfg, events).sendUserMessage('ciao');

        const startCall = calls.find((c) => c.url.endsWith('/sessions/start'))!;
        const startBody = JSON.parse(String(startCall.init.body));
        expect(startBody.snapshot.host_tools).toHaveLength(1);
        expect(startBody.snapshot.host_tools[0].name).toBe('articoli__searchArticoli');
    });

    it('executes a host tool (execution:"host") and reinjects tool_result{execution:"host",ok,artifact} into /step', async () => {
        let stepCount = 0;
        const { calls } = installFetchRouter([
            { match: (u) => u === HOST_MANIFEST_URL, respond: () => json({ tools: [HOST_TOOL] }) },
            {
                match: (u) => u.endsWith('/sessions/start'),
                respond: () =>
                    json({
                        session: { id: 'ses_1', status: 'active' },
                        type: 'tool_call',
                        tool_call: { tool: 'articoli__searchArticoli', args: { query: 'pera' }, confirmation_required: false, is_be_tool: false, execution: 'host' },
                    }),
            },
            {
                match: (u) => u === HOST_EXEC_URL,
                respond: () => json({ ok: true, artifact: { componentType: 'ui-data-table', componentProps: { rows: [{ id: 1 }] } } }),
            },
            {
                match: (u) => u.endsWith('/step'),
                respond: () => {
                    stepCount += 1;

                    return json({ session: { id: 'ses_1', status: 'active' }, type: 'message', answer: 'ecco i risultati' });
                },
            },
        ]);
        const { events, artifacts, answers } = spyEvents();

        await new Bridge(baseCfg, events).sendUserMessage('cerca pera');

        // L'host tool è stato eseguito una volta verso l'app ospite.
        const execCall = calls.find((c) => c.url === HOST_EXEC_URL);
        expect(execCall).toBeDefined();

        // Il tool_result reiniettato in /step ha lo shape host atteso.
        const stepCall = calls.find((c) => c.url.endsWith('/step'))!;
        const stepBody = JSON.parse(String(stepCall.init.body));
        expect(stepBody.tool_result).toMatchObject({
            tool: 'articoli__searchArticoli',
            execution: 'host',
            ok: true,
            artifact: { componentType: 'ui-data-table' },
        });

        // L'artifact è stato reso e il loop è proseguito fino alla risposta finale.
        expect(artifacts).toContainEqual({ componentType: 'ui-data-table' });
        expect(answers).toContain('ecco i risultati');
        expect(stepCount).toBe(1);
    });

    it('does NOT run host tools through the DOM executor (no DOM mutation attempt)', async () => {
        // Un host tool con un nome che NON è un tool DOM noto: se finisse nell'executor
        // DOM otterremmo un fail "not executable"; invece deve andare su /tools-exec.
        installFetchRouter([
            { match: (u) => u === HOST_MANIFEST_URL, respond: () => json({ tools: [HOST_TOOL] }) },
            {
                match: (u) => u.endsWith('/sessions/start'),
                respond: () =>
                    json({
                        session: { id: 'ses_1', status: 'active' },
                        type: 'tool_call',
                        // Marcato solo con is_host_tool (l'altro flag reale): deve bastare.
                        tool_call: { tool: 'nodi__searchNodi', args: {}, confirmation_required: false, is_be_tool: false, is_host_tool: true },
                    }),
            },
            { match: (u) => u === HOST_EXEC_URL, respond: () => json({ ok: true, artifact: { componentType: 'ui-card', componentProps: { title: 'X' } } }) },
            { match: (u) => u.endsWith('/step'), respond: () => json({ session: { id: 'ses_1', status: 'active' }, type: 'message', answer: 'ok' }) },
        ]);
        const { events, errors } = spyEvents();

        await new Bridge(baseCfg, events).sendUserMessage('cerca nodo');

        // Nessun errore "not executable by the widget" → non è passato dall'executor DOM.
        expect(errors.join(' ')).not.toContain('not executable');
    });

    it('handles host ok:false: surfaces an error AND still reinjects a tool_result ok:false (session not hung)', async () => {
        const { calls } = installFetchRouter([
            { match: (u) => u === HOST_MANIFEST_URL, respond: () => json({ tools: [HOST_TOOL] }) },
            {
                match: (u) => u.endsWith('/sessions/start'),
                respond: () =>
                    json({
                        session: { id: 'ses_1', status: 'active' },
                        type: 'tool_call',
                        tool_call: { tool: 'articoli__searchArticoli', args: { query: 'x' }, confirmation_required: false, is_be_tool: false, execution: 'host' },
                    }),
            },
            { match: (u) => u === HOST_EXEC_URL, respond: () => json({ ok: false, error: 'tool_not_enabled', message: 'Disabled here.' }, 422) },
            { match: (u) => u.endsWith('/step'), respond: () => json({ session: { id: 'ses_1', status: 'active' }, type: 'message', answer: 'capito, riprovo' }) },
        ]);
        const { events, errors } = spyEvents();

        await new Bridge(baseCfg, events).sendUserMessage('cerca x');

        // Errore mostrato nel thread.
        expect(errors.some((e) => e.includes('Disabled here.'))).toBe(true);

        // tool_result ok:false reiniettato comunque, così l'LLM può reagire.
        const stepCall = calls.find((c) => c.url.endsWith('/step'))!;
        const stepBody = JSON.parse(String(stepCall.init.body));
        expect(stepBody.tool_result).toMatchObject({ tool: 'articoli__searchArticoli', execution: 'host', ok: false });
    });

    it('reinjects a tool_result ok:false when host-exec URL is not configured', async () => {
        const { calls } = installFetchRouter([
            { match: (u) => u === HOST_MANIFEST_URL, respond: () => json({ tools: [HOST_TOOL] }) },
            {
                match: (u) => u.endsWith('/sessions/start'),
                respond: () =>
                    json({
                        session: { id: 'ses_1', status: 'active' },
                        type: 'tool_call',
                        tool_call: { tool: 'articoli__searchArticoli', args: {}, confirmation_required: false, is_be_tool: false, execution: 'host' },
                    }),
            },
            { match: (u) => u.endsWith('/step'), respond: () => json({ session: { id: 'ses_1', status: 'active' }, type: 'message', answer: 'ok' }) },
        ]);
        const { events, errors } = spyEvents();
        const cfgNoExec: WidgetConfig = { ...baseCfg, hostExecUrl: undefined };

        await new Bridge(cfgNoExec, events).sendUserMessage('cerca');

        expect(errors.some((e) => e.includes('data-host-exec-url'))).toBe(true);
        // Nessuna chiamata all'app ospite (URL mancante) ma /step comunque inviato.
        expect(calls.some((c) => c.url.endsWith('/step'))).toBe(true);
    });
});
