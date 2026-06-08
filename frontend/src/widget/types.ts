/**
 * Tipi condivisi del widget KITT embeddabile (TS vanilla, niente React →
 * bundle leggero per siti terzi). Lo shape dello snapshot rispecchia ciò che
 * il backend (WidgetSnapshotValidator / WidgetOrchestratorService) si aspetta.
 */

/** Config che il sito ospite mette su `window.AskMyDocsWidget`. */
export interface WidgetConfig {
    /** Chiave pubblica (pk_...). Obbligatoria. */
    key: string;
    /** Base URL dell'istanza AskMyDocs. Default: stessa origine ('' ). In
     *  modalità proxy (B) punta al backend del sito ospite. */
    apiBase?: string;
    /** Skill da richiedere a /setup (default: quella della key). */
    skill?: string;
    /** Titolo del pannello. */
    title?: string;
    /** Etichetta del bottone lanciatore. */
    launcherLabel?: string;
    /** Apre il pannello al caricamento. */
    autoOpen?: boolean;
    /**
     * F1.7 — URL del manifest host tools dell'app ospite. Se presente, all'avvio
     * sessione il widget fa `fetch(hostManifestUrl, { credentials: 'same-origin' })`
     * e include i `tools` ritornati come `snapshot.host_tools` (contratto HTP §3.4).
     */
    hostManifestUrl?: string;
    /**
     * F1.7 — Endpoint exec host tools dell'app ospite. Quando l'orchestrator ritorna
     * una tool_call con `execution === "host"`, il widget vi invia un POST con
     * `{ tool, args, session_ref }` (cookie same-origin + X-CSRF-TOKEN).
     */
    hostExecUrl?: string;
    /**
     * F1.7 — CSRF token per le chiamate all'app ospite. Letto di norma da
     * `<meta name="csrf-token">`; in alternativa da `data-csrf-token` sullo script
     * di embed. Inviato come header `X-CSRF-TOKEN` verso `hostExecUrl`.
     */
    csrfToken?: string;
}

/**
 * F1.7 — Definizione di un host tool nel manifest dell'app ospite (contratto HTP).
 * Lo shape combacia con ciò che l'orchestrator si aspetta in `snapshot.host_tools`.
 */
export interface HostTool {
    name: string;
    description: string;
    parameters: Record<string, unknown>;
    /** Sempre "host" per i tool FE-proxied. */
    execution: 'host';
    /** Opzionale: componentType tipico restituito (es. "ui-data-table"). */
    returns?: string;
}

/** F1.7 — Payload del manifest host tools: `{ schema_version, tools: [...] }`. */
export interface HostManifest {
    schema_version?: string;
    tools: HostTool[];
}

/**
 * F1.7 — Risposta dell'endpoint exec dell'app ospite:
 *   `{ ok: true, artifact: {...} }` oppure `{ ok: false, error, message }`.
 */
export interface HostExecResponse {
    ok: boolean;
    artifact?: { componentType: string; componentProps: Record<string, unknown>; [k: string]: unknown };
    error?: string;
    message?: string;
}

export interface SnapshotField {
    name: string;
    label: string;
    type: string;
    required: boolean;
    visible: boolean;
    value: string | string[] | boolean | null;
    filled: boolean;
    sensitive: boolean;
    options: Array<{ value: string; label: string }> | null;
    help: string | null;
    region: string | null;
}

export interface SnapshotAction {
    verb: string;
    label: string;
    enabled: boolean;
    reason_disabled: string | null;
    help: string | null;
}

export interface SnapshotRegion {
    id: string;
    visible: boolean;
    help: string | null;
    active: boolean;
}

export interface SnapshotMessage {
    level: string;
    text: string;
}

export interface PageOutlineButton {
    text: string;
    id: string | null;
    testid: string | null;
    disabled: boolean;
}

export interface PageOutlineInput {
    type: string;
    name: string | null;
    testid: string | null;
    label: string | null;
    visible: boolean;
}

export interface PageOutline {
    url: string;
    title: string;
    headings: Array<{ level: number; text: string }>;
    breadcrumbs: string[];
    buttons_unannotated: PageOutlineButton[];
    inputs_unannotated: PageOutlineInput[];
}

export interface Snapshot {
    snapshot_id: string;
    captured_at: string;
    page: { url: string; title: string };
    viewport: { width: number; height: number; scrollY: number; maxScrollY: number };
    active_context: {
        region: string | null;
        locale: string | null;
        focus_field: string | null;
        modal: string | null;
    };
    regions: SnapshotRegion[];
    fields: SnapshotField[];
    actions: SnapshotAction[];
    messages: SnapshotMessage[];
    locales_available: string[];
    page_outline: PageOutline;
    /**
     * F1.7 — Host tools forniti dall'app ospite (modo manifest-via-fetch). Presente
     * solo se `data-host-manifest-url` è configurato e il fetch ha avuto successo;
     * l'orchestrator li unisce alla tool list dell'LLM. Opzionale e additivo: lo
     * snapshot resta valido anche senza questo ramo (degrado solo-RAG).
     */
    host_tools?: HostTool[];
}

export interface ToolCall {
    tool: string;
    args: Record<string, unknown>;
    confirmation_required: boolean;
    is_be_tool: boolean;
    /**
     * F1.7 — Modo di esecuzione marcato dall'orchestrator. Per gli host tool vale
     * "host": il widget non li esegue via executor DOM né via /exec-tool, ma li
     * instrada all'app ospite (FE-proxied).
     */
    execution?: string;
    /** F1.7 — Flag esplicito host tool (ridondante con `execution === "host"`). */
    is_host_tool?: boolean;
}

export interface Citation {
    document_id: number | null;
    title: string;
    source_path: string | null;
    origin?: string;
}

/** Risposta del backend a start/step. */
export interface TurnResponse {
    session: { id: string; status: string };
    type: 'message' | 'tool_call' | 'blocked';
    answer?: string;
    citations?: Citation[];
    confidence?: number;
    tool_call?: ToolCall;
    bot_message?: string | null;
    reason?: string;
    meta?: Record<string, unknown>;
}

export interface ToolResult {
    ok: boolean;
    tool: string;
    diagnostic?: Record<string, unknown>;
    error_message?: string | null;
}

/**
 * F1.7 — `tool_result` reiniettato nello `/step` dopo l'esecuzione di un host tool,
 * allineato a ciò che l'orchestrator si aspetta:
 *   `{ tool, execution:"host", ok, artifact }` (su ok:false l'artifact può mancare,
 *   ma si passa `error`/`message` così l'LLM può reagire).
 */
export interface HostToolResult {
    tool: string;
    execution: 'host';
    ok: boolean;
    artifact?: { componentType: string; componentProps: Record<string, unknown>; [k: string]: unknown };
    error?: string | null;
    message?: string | null;
}
