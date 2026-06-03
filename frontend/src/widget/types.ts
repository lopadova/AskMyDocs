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
