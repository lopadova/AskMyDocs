/**
 * Tipi condivisi del widget KITT embeddabile (TS vanilla, niente React →
 * bundle leggero per siti terzi). Lo shape dello snapshot rispecchia ciò che
 * il backend (WidgetSnapshotValidator / WidgetOrchestratorService) si aspetta.
 */

/** Config che il sito ospite mette su `window.AskMyDocsWidget`. */
export interface WidgetConfig {
    /** Chiave pubblica (pk_...). Obbligatoria. */
    key: string;
    /**
     * Short-lived authenticated user token (`wu_…`) minted server-to-server by
     * the host application. Never place the host subject/email/internal id here.
     * Backward-compatible static mode: the token stays in memory until it
     * expires, but cannot be renewed automatically without `userTokenUrl`.
     */
    userToken?: string;
    /**
     * Same-origin host endpoint that returns `{ token: "wu_…", expires_at }`.
     * The widget calls it with the host session cookie (`credentials:
     * "same-origin"`) at boot and whenever the short-lived token needs renewal.
     * The endpoint must authenticate the current host user server-side; never
     * expose the identity secret or the host subject/email to the browser.
     */
    userTokenUrl?: string;
    /** Base URL dell'istanza AskMyDocs. Default: stessa origine ('' ). In
     *  modalità proxy (B) punta al backend del sito ospite. */
    apiBase?: string;
    /** Skill da richiedere a /setup (default: quella della key). */
    skill?: string;
    /** Titolo del pannello. */
    title?: string;
    /** Etichetta del bottone lanciatore. */
    launcherLabel?: string;
    /** Apre il pannello al caricamento (solo modalità `helper`). */
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
    /**
     * Modalità di resa del widget (precedenza sul `theme.mode` server):
     *   - `helper` (default) launcher flottante → pannello a comparsa;
     *   - `inline`           blocco chat che riempie {@link WidgetConfig.mount};
     *   - `fullscreen`       esperienza chat che occupa l'intera viewport.
     * L'embed snippet la "congela" inline perché il mount è specifico del sito.
     */
    mode?: WidgetMode;
    /**
     * Selettore CSS del container in cui montare la chat in modalità `inline`
     * (es. `'#askmydocs-chat'`). Obbligatorio per `inline`; ignorato in `helper`.
     * Se il container non esiste il widget logga un errore e non monta (R14).
     */
    mount?: string;
    /**
     * Tema grafico INLINE opzionale. Precedenza effettiva:
     * CSS host `--askmydocs-*` > inline > server > default.
     * Parziale: ogni campo assente cade sul tema server (/setup) o sul default.
     */
    theme?: Partial<WidgetTheme>;
    /**
     * Structured pre-conversation content. The host may override the per-key
     * server default field-by-field, or pass `false` to disable it for a page.
     * Arbitrary HTML is deliberately unsupported.
     */
    intro?: Partial<WidgetIntro> | false;
}

export type WidgetIntroVariant = 'compact' | 'card' | 'hero';
export type WidgetIntroIcon = 'sparkles' | 'chat' | 'search' | 'help' | 'none';

export interface WidgetIntroSuggestion {
    label: string;
    prompt: string;
}

export interface WidgetIntro {
    enabled: boolean;
    variant: WidgetIntroVariant;
    eyebrow: string;
    title: string;
    subtitle: string;
    body: string;
    imageUrl: string;
    imageAlt: string;
    icon: WidgetIntroIcon;
    bullets: string[];
    suggestions: WidgetIntroSuggestion[];
    dismissible: boolean;
    hideAfterFirstMessage: boolean;
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

/**
 * Modalità di layout del widget. `helper` = launcher flottante + pannello a
 * comparsa (kitt). `inline` = blocco chat che riempie il container ospite (chat
 * legata a una pagina). Mirror di WidgetThemeService::MODES (PHP).
 */
export type WidgetMode = 'helper' | 'inline' | 'fullscreen';

/** Chiave di font ammessa (mappa su uno stack sicuro — vedi FONT_STACKS). */
export type WidgetFontKey = 'system' | 'inter' | 'roboto' | 'georgia' | 'mono';
export type LauncherSide = 'right' | 'left';
export type LauncherShape = 'pill' | 'rounded' | 'circle';
export type LauncherIcon = 'chat' | 'sparkles' | 'help' | 'none';
export type WidgetShadow = 'none' | 'soft' | 'medium' | 'strong';

/**
 * Identità grafica del widget. Forma piatta e tipizzata, speculare a
 * WidgetThemeService::defaults() (PHP) — R9 docs-match-code. Tutti i valori
 * sono validati/sanificati prima di finire in CSS (sanitizeTheme).
 */
export interface WidgetTheme {
    // Modalità di layout (helper = launcher flottante, inline = blocco a pagina)
    mode: WidgetMode;
    // Colori (solo hex #rgb/#rrggbb/#rrggbbaa)
    accent: string;
    accentForeground: string;
    background: string;
    foreground: string;
    muted: string;
    border: string;
    headerBackground: string;
    headerForeground: string;
    launcherBackground: string;
    launcherForeground: string;
    userBubbleBackground: string;
    userBubbleForeground: string;
    assistantBubbleBackground: string;
    assistantBubbleForeground: string;
    composerBackground: string;
    inputBackground: string;
    inputForeground: string;
    inputPlaceholder: string;
    citationBackground: string;
    citationForeground: string;
    focusRing: string;
    systemBackground: string;
    systemForeground: string;
    errorBackground: string;
    errorForeground: string;
    confirmBackground: string;
    confirmForeground: string;
    confirmBorder: string;
    sourceSidebarBackground: string;
    sourceSidebarForeground: string;
    sourceBackdrop: string;
    // Tipografia
    fontFamily: WidgetFontKey;
    fontSize: number;
    // Launcher
    launcherSide: LauncherSide;
    launcherShape: LauncherShape;
    launcherLabel: string;
    launcherIcon: LauncherIcon;
    launcherIconUrl: string;
    launcherOffsetX: number;
    launcherOffsetY: number;
    launcherSize: number;
    launcherShadow: WidgetShadow;
    // Pannello
    panelWidth: number;
    panelHeight: number;
    panelRadius: number;
    panelShadow: WidgetShadow;
    panelTitle: string;
    headerLogoUrl: string;
    // Spaziatura e geometria
    headerPaddingX: number;
    headerPaddingY: number;
    messagesPadding: number;
    messageGap: number;
    bubblePaddingX: number;
    bubblePaddingY: number;
    bubbleRadius: number;
    bubbleMaxWidth: number;
    composerPadding: number;
    inputRadius: number;
    buttonRadius: number;
    logoHeight: number;
    // Viewer fonti
    sourceViewerWidth: number;
    sourceViewerRadius: number;
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

export interface CitationChunkEvidence {
    chunk_id?: string | number | null;
    heading?: string | null;
    score?: number;
    snippet?: string;
    evidence_hash?: string | null;
}

export interface Citation {
    document_id: number | null;
    title: string;
    source_path: string | null;
    slug?: string | null;
    project_key?: string | null;
    source_type?: string | null;
    generation_source?: 'auto' | 'human' | null;
    headings?: string[];
    chunks_used?: number;
    origin?: 'primary' | 'related' | 'rejected' | string | null;
    chunks?: CitationChunkEvidence[];
}

export interface WidgetDocumentSection {
    heading_path: string | null;
    content: string;
}

/** Session-scoped indexed document returned by the cited-source endpoint. */
export interface WidgetDocumentPreview {
    document_id: number;
    title: string | null;
    source_path: string | null;
    source_type: string | null;
    language: string | null;
    source_updated_at: string | null;
    sections: WidgetDocumentSection[];
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

export interface WidgetAgentRun {
    id: string;
    status: string;
    locale: string;
    events_url: string;
    cancel_url: string;
    continue_url: string;
    budget?: Record<string, unknown>;
}

export interface WidgetAgentTurnResponse {
    session: { id: string; status: string; locale: string };
    type: 'agent_run';
    run: WidgetAgentRun;
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
