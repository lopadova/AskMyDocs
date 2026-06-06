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
    /** Apre il pannello al caricamento (solo modalità `helper`). */
    autoOpen?: boolean;
    /**
     * Modalità di resa del widget (precedenza sul `theme.mode` server):
     *   - `helper` (default) launcher flottante → pannello a comparsa;
     *   - `inline`           blocco chat che riempie {@link WidgetConfig.mount}.
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
     * Tema grafico INLINE opzionale (precedenza massima: inline > server > default).
     * Parziale: ogni campo assente cade sul tema server (/setup) o sul default.
     */
    theme?: Partial<WidgetTheme>;
}

/**
 * Modalità di layout del widget. `helper` = launcher flottante + pannello a
 * comparsa (kitt). `inline` = blocco chat che riempie il container ospite (chat
 * legata a una pagina). Mirror di WidgetThemeService::MODES (PHP).
 */
export type WidgetMode = 'helper' | 'inline';

/** Chiave di font ammessa (mappa su uno stack sicuro — vedi FONT_STACKS). */
export type WidgetFontKey = 'system' | 'inter' | 'roboto' | 'georgia' | 'mono';
export type LauncherSide = 'right' | 'left';
export type LauncherShape = 'pill' | 'rounded' | 'circle';
export type LauncherIcon = 'chat' | 'sparkles' | 'help' | 'none';

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
    // Tipografia
    fontFamily: WidgetFontKey;
    fontSize: number;
    // Launcher
    launcherSide: LauncherSide;
    launcherShape: LauncherShape;
    launcherLabel: string;
    launcherIcon: LauncherIcon;
    launcherIconUrl: string;
    // Pannello
    panelWidth: number;
    panelHeight: number;
    panelRadius: number;
    panelTitle: string;
    headerLogoUrl: string;
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
}

export interface ToolCall {
    tool: string;
    args: Record<string, unknown>;
    confirmation_required: boolean;
    is_be_tool: boolean;
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
