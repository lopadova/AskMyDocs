/**
 * CSS del widget, iniettato come stringa nello shadow root (open → isolato ma
 * testabile da Playwright). Niente Tailwind: poche regole scritte a mano per
 * tenere il bundle leggero.
 *
 * Tematizzazione (D-grafica): i valori personalizzabili sono CSS custom
 * properties con FALLBACK al default. Il fallback È il default canonico,
 * speculare a {@link DEFAULT_THEME} e a WidgetThemeService::defaults() (PHP) —
 * R9 docs-match-code. {@link buildThemeCss} emette SOLO un blocco di var
 * override; ogni fallback è avvolto nella corrispondente variabile host
 * `--askmydocs-*`, mentre le varianti strutturali (lato/forma launcher) sono
 * classi.
 *
 * Sicurezza (R19): ogni valore tematico passa da {@link sanitizeTheme} prima di
 * finire in CSS — colori solo hex, numeri clampati con unità aggiunta da noi,
 * font da allowlist (mai lo stack grezzo). Gli URL immagine NON entrano mai in
 * CSS: vanno su attributi src del DOM (vedi panel.ts).
 */
import type {
    LauncherIcon,
    LauncherShape,
    LauncherSide,
    WidgetFontKey,
    WidgetMode,
    WidgetShadow,
    WidgetTheme,
} from '../types';

/** Stack font sicuri per chiave. Mirror di WidgetThemeService::FONTS (PHP). */
export const FONT_STACKS: Record<WidgetFontKey, string> = {
    system: "system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif",
    inter: 'Inter, system-ui, -apple-system, sans-serif',
    roboto: 'Roboto, system-ui, -apple-system, sans-serif',
    georgia: "Georgia, 'Times New Roman', serif",
    mono: "'SFMono-Regular', Menlo, Consolas, monospace",
};

/** Tema di default canonico. Mirror di WidgetThemeService::defaults() (PHP). */
export const DEFAULT_THEME: WidgetTheme = {
    mode: 'helper',
    accent: '#2563eb',
    accentForeground: '#ffffff',
    background: '#ffffff',
    foreground: '#1f2937',
    muted: '#6b7280',
    border: '#e5e7eb',
    headerBackground: '#2563eb',
    headerForeground: '#ffffff',
    launcherBackground: '#2563eb',
    launcherForeground: '#ffffff',
    userBubbleBackground: '#2563eb',
    userBubbleForeground: '#ffffff',
    assistantBubbleBackground: '#f3f4f6',
    assistantBubbleForeground: '#1f2937',
    composerBackground: '#ffffff',
    inputBackground: '#ffffff',
    inputForeground: '#1f2937',
    inputPlaceholder: '#9ca3af',
    citationBackground: '#e0e7ff',
    citationForeground: '#3730a3',
    focusRing: '#93c5fd',
    systemBackground: '#fff7ed',
    systemForeground: '#9a3412',
    errorBackground: '#fef2f2',
    errorForeground: '#b91c1c',
    confirmBackground: '#fffbeb',
    confirmForeground: '#1f2937',
    confirmBorder: '#fde68a',
    sourceSidebarBackground: '#f8fafc',
    sourceSidebarForeground: '#334155',
    sourceBackdrop: '#0f172acc',
    fontFamily: 'system',
    fontSize: 14,
    launcherSide: 'right',
    launcherShape: 'pill',
    launcherLabel: '',
    launcherIcon: 'chat',
    launcherIconUrl: '',
    launcherOffsetX: 20,
    launcherOffsetY: 20,
    launcherSize: 56,
    launcherShadow: 'medium',
    panelWidth: 380,
    panelHeight: 560,
    panelRadius: 14,
    panelShadow: 'strong',
    panelTitle: '',
    headerLogoUrl: '',
    headerPaddingX: 14,
    headerPaddingY: 12,
    messagesPadding: 14,
    messageGap: 10,
    bubblePaddingX: 12,
    bubblePaddingY: 9,
    bubbleRadius: 12,
    bubbleMaxWidth: 88,
    composerPadding: 10,
    inputRadius: 10,
    buttonRadius: 10,
    logoHeight: 22,
    sourceViewerWidth: 880,
    sourceViewerRadius: 16,
};

const FONT_KEYS = Object.keys(FONT_STACKS) as WidgetFontKey[];
/** Mirror di WidgetThemeService::MODES (PHP). */
export const WIDGET_MODES: WidgetMode[] = ['helper', 'inline', 'fullscreen'];
const LAUNCHER_SIDES: LauncherSide[] = ['right', 'left'];
const LAUNCHER_SHAPES: LauncherShape[] = ['pill', 'rounded', 'circle'];
const LAUNCHER_ICONS: LauncherIcon[] = ['chat', 'sparkles', 'help', 'none'];
const SHADOW_KEYS: WidgetShadow[] = ['none', 'soft', 'medium', 'strong'];

/** Valori CSS fidati associati ai preset ombra. Mai interpolare input grezzo. */
export const SHADOW_PRESETS: Record<WidgetShadow, string> = {
    none: 'none',
    soft: '0 4px 16px rgba(15,23,42,.12)',
    medium: '0 8px 24px rgba(15,23,42,.18)',
    strong: '0 16px 48px rgba(15,23,42,.24)',
};

const HEX_RE = /^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/;

/**
 * CSS strutturale. I valori tematizzabili usano `var(--x, <fallback-default>)`;
 * lato/forma del launcher sono classi su `.amd-root` / `.amd-launcher`.
 */
export const BASE_WIDGET_CSS = `
:host { all: initial; }
*, *::before, *::after { box-sizing: border-box; }
.amd-root {
    font-family: var(--amd-font, system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif);
    --amd-accent: #2563eb;
    --amd-bg: #ffffff;
    --amd-fg: #1f2937;
    --amd-muted: #6b7280;
    --amd-border: #e5e7eb;
    color: var(--amd-fg);
}
.amd-launcher {
    position: fixed; right: var(--amd-launcher-offset-x, 20px); bottom: var(--amd-launcher-offset-y, 20px); z-index: 2147483000;
    display: inline-flex; align-items: center; gap: 8px;
    min-height: var(--amd-launcher-size, 56px);
    padding: 12px 16px; border: none; border-radius: 999px;
    background: var(--amd-launcher-bg, var(--amd-accent)); color: var(--amd-launcher-fg, #fff);
    font-size: var(--amd-font-size, 14px); font-weight: 600;
    cursor: pointer; box-shadow: var(--amd-launcher-shadow, 0 8px 24px rgba(15,23,42,.18));
}
.amd-launcher:focus-visible { outline: 3px solid var(--amd-focus-ring, #93c5fd); outline-offset: 2px; }
.amd-launcher-icon { display: inline-flex; align-items: center; }
.amd-launcher-icon svg { width: 18px; height: 18px; display: block; }
.amd-launcher-icon img { width: 18px; height: 18px; object-fit: contain; display: block; }
.amd-launcher-label { white-space: nowrap; }
.amd-launcher.amd-shape-rounded { border-radius: 14px; }
.amd-launcher.amd-shape-circle { width: var(--amd-launcher-size, 56px); height: var(--amd-launcher-size, 56px); padding: 0; justify-content: center; border-radius: 50%; }
.amd-launcher.amd-shape-circle .amd-launcher-label { display: none; }
.amd-root.amd-side-left .amd-launcher,
.amd-root.amd-side-left .amd-panel { right: auto; left: var(--amd-launcher-offset-x, 20px); }
.amd-panel {
    position: fixed; right: var(--amd-launcher-offset-x, 20px);
    bottom: calc(var(--amd-launcher-offset-y, 20px) + var(--amd-launcher-size, 56px) + 8px);
    z-index: 2147483000;
    width: var(--amd-panel-width, 380px);
    max-width: calc(100vw - var(--amd-launcher-offset-x, 20px) - 16px);
    height: var(--amd-panel-height, 560px);
    max-height: calc(100dvh - var(--amd-launcher-offset-y, 20px) - var(--amd-launcher-size, 56px) - 36px);
    display: none; flex-direction: column;
    background: var(--amd-bg); border: 1px solid var(--amd-border); border-radius: var(--amd-panel-radius, 14px);
    box-shadow: var(--amd-panel-shadow, 0 16px 48px rgba(15,23,42,.24)); overflow: hidden;
}
.amd-panel[data-open="true"] { display: flex; }
.amd-header {
    display: flex; align-items: center; gap: 8px;
    padding: var(--amd-header-padding-y, 12px) var(--amd-header-padding-x, 14px);
    background: var(--amd-header-bg, var(--amd-accent)); color: var(--amd-header-fg, #fff);
}
.amd-logo { height: var(--amd-logo-height, 22px); width: auto; max-width: 160px; border-radius: 4px; flex: 0 0 auto; }
.amd-title { font-size: 14px; font-weight: 700; flex: 1 1 auto; }
.amd-close { background: transparent; border: none; color: inherit; font-size: 20px; cursor: pointer; line-height: 1; flex: 0 0 auto; }
.amd-messages { flex: 1; overflow-y: auto; padding: var(--amd-messages-padding, 14px); display: flex; flex-direction: column; gap: var(--amd-message-gap, 10px); }
.amd-msg { max-width: var(--amd-bubble-max-width, 88%); padding: var(--amd-bubble-padding-y, 9px) var(--amd-bubble-padding-x, 12px); border-radius: var(--amd-bubble-radius, 12px); font-size: var(--amd-font-size, 14px); line-height: 1.45; white-space: pre-wrap; word-wrap: break-word; }
.amd-msg.user { align-self: flex-end; background: var(--amd-user-bg, var(--amd-accent)); color: var(--amd-user-fg, #fff); border-bottom-right-radius: 4px; }
.amd-msg.assistant { align-self: flex-start; background: var(--amd-assistant-bg, #f3f4f6); color: var(--amd-assistant-fg, var(--amd-fg)); border-bottom-left-radius: 4px; }
.amd-msg.system { align-self: center; background: var(--amd-system-bg, #fff7ed); color: var(--amd-system-fg, #9a3412); font-size: 12.5px; }
.amd-msg.error { align-self: center; background: var(--amd-error-bg, #fef2f2); color: var(--amd-error-fg, #b91c1c); font-size: 12.5px; }
.amd-citations { margin-top: 6px; display: flex; flex-wrap: wrap; gap: 4px; }
.amd-cite { font: inherit; font-size: 11px; background: var(--amd-citation-bg, #e0e7ff); color: var(--amd-citation-fg, #3730a3); padding: 2px 6px; border: 0; border-radius: var(--amd-button-radius, 10px); }
button.amd-cite { cursor: pointer; }
.amd-cite:focus-visible { outline: 2px solid var(--amd-focus-ring, #93c5fd); outline-offset: 2px; }
.amd-status { padding: 0 var(--amd-messages-padding, 14px) 6px; font-size: 12px; color: var(--amd-muted); min-height: 16px; }
.amd-confirm { margin: 6px var(--amd-messages-padding, 14px); padding: 10px; border: 1px solid var(--amd-confirm-border, #fde68a); background: var(--amd-confirm-bg, #fffbeb); color: var(--amd-confirm-fg, var(--amd-fg)); border-radius: var(--amd-bubble-radius, 12px); font-size: 13px; }
.amd-confirm-actions { margin-top: 8px; display: flex; gap: 8px; }
.amd-btn { padding: 7px 12px; border-radius: var(--amd-button-radius, 10px); border: 1px solid var(--amd-border); background: var(--amd-input-bg, #fff); color: var(--amd-input-fg, var(--amd-fg)); font-size: 13px; cursor: pointer; }
.amd-btn.primary { background: var(--amd-accent); color: var(--amd-accent-fg, #fff); border-color: var(--amd-accent); }
.amd-btn:focus-visible, .amd-close:focus-visible, .amd-send:focus-visible { outline: 2px solid var(--amd-focus-ring, #93c5fd); outline-offset: 2px; }
.amd-composer { display: flex; gap: 8px; padding: var(--amd-composer-padding, 10px); border-top: 1px solid var(--amd-border); background: var(--amd-composer-bg, var(--amd-bg)); }
.amd-input { flex: 1; resize: none; border: 1px solid var(--amd-border); border-radius: var(--amd-input-radius, 10px); padding: 9px 10px; background: var(--amd-input-bg, #fff); color: var(--amd-input-fg, var(--amd-fg)); font: inherit; font-size: var(--amd-font-size, 14px); min-height: 40px; max-height: 120px; }
.amd-input::placeholder { color: var(--amd-input-placeholder, #9ca3af); opacity: 1; }
.amd-input:focus-visible { outline: 2px solid var(--amd-focus-ring, var(--amd-accent)); outline-offset: 0; }
.amd-send { padding: 0 14px; border: none; border-radius: var(--amd-button-radius, 10px); background: var(--amd-accent); color: var(--amd-accent-fg, #fff); font-weight: 600; cursor: pointer; }
.amd-send:disabled { opacity: .5; cursor: not-allowed; }
.amd-ask-options { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px; }
.amd-notice { margin-top: 6px; font-size: 12px; color: var(--amd-muted); }

/* ── Modalità inline: blocco chat che riempie il container ospite ──
   Nessun launcher; il pannello è sempre visibile, statico e a piena dimensione
   del mount (il sito ospite controlla width/height del container). La classe
   .amd-mode-inline è applicata su .amd-root da WidgetPanel quando mode='inline'. */
.amd-root.amd-mode-inline { height: 100%; display: flex; }
.amd-root.amd-mode-inline .amd-launcher { display: none; }
.amd-root.amd-mode-inline .amd-panel {
    position: static; display: flex;
    right: auto; left: auto; bottom: auto;
    width: 100%; max-width: 100%;
    height: 100%; max-height: 100%;
    flex: 1 1 auto; box-shadow: none;
}
.amd-root.amd-mode-inline .amd-close { display: none; }

/* ── Modalità fullscreen: chat sempre visibile sull'intera viewport ── */
.amd-root.amd-mode-fullscreen {
    position: fixed; inset: 0; width: 100vw; height: 100dvh;
    display: flex; background: var(--amd-bg); z-index: 2147483000;
}
.amd-root.amd-mode-fullscreen .amd-launcher { display: none; }
.amd-root.amd-mode-fullscreen .amd-panel {
    position: static; display: flex; flex: 1 1 auto;
    right: auto; left: auto; bottom: auto;
    width: 100%; max-width: none; height: 100%; max-height: none;
    border: 0; border-radius: 0; box-shadow: none;
}
.amd-root.amd-mode-fullscreen .amd-close { display: none; }
`;

/** Alias retro-compat: il loader storico iniettava `WIDGET_CSS`. */
export const WIDGET_CSS = BASE_WIDGET_CSS;

/**
 * CSS del feedback visivo agentico (M4.8 — `move_cursor` / `tour_step`).
 *
 * A differenza di {@link BASE_WIDGET_CSS} (iniettato nello shadow root, isolato),
 * questo blocco va nel `<head>` della PAGINA OSPITE: backdrop, spotlight, freccia
 * e tooltip devono coprire l'INTERA viewport, non solo la UI del widget — e lo
 * shadow DOM non può stilare elementi del light DOM. Lo monta {@link OverlaySystem}.
 *
 * Backdrop+spotlight: approccio "box-shadow inset gigante" — `.amd-spotlight` è
 * un div sul rect del target con una box-shadow di spread enorme che oscura tutto
 * FUORI dal suo rettangolo, lasciando il target nitido (il "buco" è il div).
 * Più robusto di una SVG mask: niente quirk di `<mask>`/`clip-path`, scala al
 * resize, e il box porta anche l'anello di evidenziazione.
 *
 * Classi prefissate `amd-` per non collidere col sito ospite. z-index ereditato
 * dal wrapper `.amd-overlay` (montato sotto il launcher del widget).
 */
export const OVERLAY_CSS = `
.amd-overlay, .amd-overlay * { box-sizing: border-box; }
.amd-backdrop {
    position: fixed; inset: 0;
    background: rgba(15, 23, 42, 0.55);
    animation: amd-overlay-fade 160ms ease-out;
}
.amd-spotlight {
    position: fixed; top: 0; left: 0; width: 0; height: 0;
    border-radius: 10px;
    /* "buco" sul target: box-shadow gigante che oscura tutto FUORI dal box. */
    box-shadow: 0 0 0 9999px rgba(15, 23, 42, 0.55), 0 0 0 3px rgba(59, 130, 246, 0.9);
    outline: 2px solid rgba(255, 255, 255, 0.85);
    outline-offset: 2px;
    transition: top 180ms ease, left 180ms ease, width 180ms ease, height 180ms ease;
    pointer-events: none;
}
.amd-cursor {
    position: fixed; top: 0; left: 0;
    pointer-events: none;
    filter: drop-shadow(0 2px 3px rgba(0, 0, 0, 0.35));
    animation: amd-cursor-pulse 1.1s ease-in-out infinite;
}
.amd-cursor svg { display: block; }
.amd-tooltip {
    position: fixed; top: 0; left: 0;
    max-width: 300px;
    background: #0f172a; color: #f8fafc;
    border-radius: 10px; padding: 12px 14px;
    font: 14px/1.45 system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.35);
    pointer-events: auto;
}
.amd-tooltip-step {
    font-size: 12px; font-weight: 700; opacity: 0.7;
    margin-bottom: 4px; letter-spacing: 0.02em;
}
.amd-tooltip-body { font-size: 14px; }
@keyframes amd-overlay-fade { from { opacity: 0; } to { opacity: 1; } }
@keyframes amd-cursor-pulse {
    0%, 100% { transform: translate(-50%, -100%) scale(1); }
    50% { transform: translate(-50%, -100%) scale(1.12); }
}
@media (prefers-reduced-motion: reduce) {
    .amd-backdrop, .amd-spotlight, .amd-cursor { animation: none; transition: none; }
}
`;

/**
 * SVG built-in del launcher (costanti fidate, 24×24, currentColor). Niente
 * markup/emoji arbitrario dall'utente (R19).
 */
export const ICON_SVGS: Record<Exclude<LauncherIcon, 'none'>, string> = {
    chat: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>',
    sparkles: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l1.9 4.6L18.5 9l-4.6 1.9L12 15l-1.9-4.1L5.5 9l4.6-1.4L12 3z"/><path d="M19 14l.8 2 2 .8-2 .8-.8 2-.8-2-2-.8 2-.8L19 14z"/></svg>',
    help: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M9.1 9a3 3 0 0 1 5.8 1c0 2-3 2.5-3 2.5"/><line x1="12" y1="17" x2="12" y2="17"/></svg>',
};

/** SVG built-in per la chiave icona, oppure '' (none / sconosciuta). */
export function launcherIconSvg(icon: LauncherIcon): string {
    return icon === 'none' ? '' : (ICON_SVGS[icon] ?? '');
}

/** Escape per testo inserito in markup HTML (anteprima admin). */
export function escapeHtml(value: string): string {
    return value
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function isHex(value: unknown): value is string {
    return typeof value === 'string' && HEX_RE.test(value);
}

function color(value: unknown, fallback: string): string {
    return isHex(value) ? value.toLowerCase() : fallback;
}

function pick<T extends string>(value: unknown, allowed: readonly T[], fallback: T): T {
    return typeof value === 'string' && (allowed as readonly string[]).includes(value)
        ? (value as T)
        : fallback;
}

function int(value: unknown, min: number, max: number, fallback: number): number {
    const n =
        typeof value === 'number'
            ? value
            : typeof value === 'string'
                && /^[+-]?(?:(?:\d+(?:\.\d*)?)|(?:\.\d+))(?:[eE][+-]?\d+)?$/.test(value.trim())
              ? Number(value)
              : NaN;

    return Number.isFinite(n) ? Math.max(min, Math.min(max, Math.round(n))) : fallback;
}

function text(value: unknown, max: number, fallback: string): string {
    if (typeof value !== 'string') {
        return fallback;
    }

    // Via i caratteri di controllo (finiscono in textContent del DOM).
    // eslint-disable-next-line no-control-regex
    return Array.from(value.replace(/[\u0000-\u001F\u007F]/g, '').trim())
        .slice(0, max)
        .join('');
}

/** URL immagine: solo https, senza meta-caratteri pericolosi. Altrimenti ''. */
function url(value: unknown, fallback: string): string {
    if (typeof value !== 'string') {
        return fallback;
    }
    if (value.trim() === '') {
        return '';
    }
    const trimmed = value.trim();
    if (
        Array.from(trimmed).length > 500 ||
        !/^https:\/\//i.test(trimmed) ||
        /["'()<>\s\\]/.test(trimmed)
    ) {
        return fallback;
    }
    try {
        return new URL(trimmed).protocol === 'https:'
            ? `https://${trimmed.slice('https://'.length)}`
            : fallback;
    } catch {
        return fallback;
    }
}

/**
 * Normalizza un tema (parziale/non fidato) in un {@link WidgetTheme} completo e
 * sicuro. Difesa in profondità per i temi INLINE del sito ospite, che bypassano
 * la validazione del backend: ogni valore non valido degrada al layer valido
 * precedente (o al default canonico se non viene fornito un fallback).
 */
export function sanitizeTheme(raw: unknown, fallback: WidgetTheme = DEFAULT_THEME): WidgetTheme {
    const r = raw && typeof raw === 'object' ? (raw as Record<string, unknown>) : {};
    const d = fallback;

    return {
        mode: pick(r.mode, WIDGET_MODES, d.mode),
        accent: color(r.accent, d.accent),
        accentForeground: color(r.accentForeground, d.accentForeground),
        background: color(r.background, d.background),
        foreground: color(r.foreground, d.foreground),
        muted: color(r.muted, d.muted),
        border: color(r.border, d.border),
        headerBackground: color(r.headerBackground, d.headerBackground),
        headerForeground: color(r.headerForeground, d.headerForeground),
        launcherBackground: color(r.launcherBackground, d.launcherBackground),
        launcherForeground: color(r.launcherForeground, d.launcherForeground),
        userBubbleBackground: color(r.userBubbleBackground, d.userBubbleBackground),
        userBubbleForeground: color(r.userBubbleForeground, d.userBubbleForeground),
        assistantBubbleBackground: color(r.assistantBubbleBackground, d.assistantBubbleBackground),
        assistantBubbleForeground: color(r.assistantBubbleForeground, d.assistantBubbleForeground),
        composerBackground: color(r.composerBackground, d.composerBackground),
        inputBackground: color(r.inputBackground, d.inputBackground),
        inputForeground: color(r.inputForeground, d.inputForeground),
        inputPlaceholder: color(r.inputPlaceholder, d.inputPlaceholder),
        citationBackground: color(r.citationBackground, d.citationBackground),
        citationForeground: color(r.citationForeground, d.citationForeground),
        focusRing: color(r.focusRing, d.focusRing),
        systemBackground: color(r.systemBackground, d.systemBackground),
        systemForeground: color(r.systemForeground, d.systemForeground),
        errorBackground: color(r.errorBackground, d.errorBackground),
        errorForeground: color(r.errorForeground, d.errorForeground),
        confirmBackground: color(r.confirmBackground, d.confirmBackground),
        confirmForeground: color(r.confirmForeground, d.confirmForeground),
        confirmBorder: color(r.confirmBorder, d.confirmBorder),
        sourceSidebarBackground: color(r.sourceSidebarBackground, d.sourceSidebarBackground),
        sourceSidebarForeground: color(r.sourceSidebarForeground, d.sourceSidebarForeground),
        sourceBackdrop: color(r.sourceBackdrop, d.sourceBackdrop),
        fontFamily: pick(r.fontFamily, FONT_KEYS, d.fontFamily),
        fontSize: int(r.fontSize, 12, 18, d.fontSize),
        launcherSide: pick(r.launcherSide, LAUNCHER_SIDES, d.launcherSide),
        launcherShape: pick(r.launcherShape, LAUNCHER_SHAPES, d.launcherShape),
        launcherLabel: text(r.launcherLabel, 60, d.launcherLabel),
        launcherIcon: pick(r.launcherIcon, LAUNCHER_ICONS, d.launcherIcon),
        launcherIconUrl: url(r.launcherIconUrl, d.launcherIconUrl),
        launcherOffsetX: int(r.launcherOffsetX, 0, 96, d.launcherOffsetX),
        launcherOffsetY: int(r.launcherOffsetY, 0, 96, d.launcherOffsetY),
        launcherSize: int(r.launcherSize, 40, 80, d.launcherSize),
        launcherShadow: pick(r.launcherShadow, SHADOW_KEYS, d.launcherShadow),
        panelWidth: int(r.panelWidth, 320, 720, d.panelWidth),
        panelHeight: int(r.panelHeight, 420, 900, d.panelHeight),
        panelRadius: int(r.panelRadius, 0, 24, d.panelRadius),
        panelShadow: pick(r.panelShadow, SHADOW_KEYS, d.panelShadow),
        panelTitle: text(r.panelTitle, 60, d.panelTitle),
        headerLogoUrl: url(r.headerLogoUrl, d.headerLogoUrl),
        headerPaddingX: int(r.headerPaddingX, 0, 40, d.headerPaddingX),
        headerPaddingY: int(r.headerPaddingY, 0, 40, d.headerPaddingY),
        messagesPadding: int(r.messagesPadding, 0, 40, d.messagesPadding),
        messageGap: int(r.messageGap, 0, 32, d.messageGap),
        bubblePaddingX: int(r.bubblePaddingX, 0, 32, d.bubblePaddingX),
        bubblePaddingY: int(r.bubblePaddingY, 0, 32, d.bubblePaddingY),
        bubbleRadius: int(r.bubbleRadius, 0, 32, d.bubbleRadius),
        bubbleMaxWidth: int(r.bubbleMaxWidth, 50, 100, d.bubbleMaxWidth),
        composerPadding: int(r.composerPadding, 0, 32, d.composerPadding),
        inputRadius: int(r.inputRadius, 0, 32, d.inputRadius),
        buttonRadius: int(r.buttonRadius, 0, 32, d.buttonRadius),
        logoHeight: int(r.logoHeight, 16, 64, d.logoHeight),
        sourceViewerWidth: int(r.sourceViewerWidth, 560, 1200, d.sourceViewerWidth),
        sourceViewerRadius: int(r.sourceViewerRadius, 0, 32, d.sourceViewerRadius),
    };
}

/**
 * Applica i layer dal meno al più prioritario. Ogni layer viene sanificato
 * usando il risultato precedente come fallback: un override non valido non può
 * quindi cancellare un valore valido proveniente dal server.
 */
export function mergeThemeLayers(...layers: unknown[]): WidgetTheme {
    return layers.reduce<WidgetTheme>(
        (theme, layer) => sanitizeTheme(layer, theme),
        DEFAULT_THEME,
    );
}

/**
 * Blocco CSS di override delle var, da iniettare DOPO {@link BASE_WIDGET_CSS}.
 * Solo custom properties (colori hex, preset ombra, font allowlist, numeri con
 * unità) → nessuna possibilità di evasione dal blocco. Le `--askmydocs-*`
 * ereditate dal sito sono il valore primario di ogni `var()`, quindi prevalgono
 * senza copiare CSS host nella stringa generata. Lato/forma del launcher sono
 * gestiti via classi (vedi panel.ts), non qui.
 */
export function buildThemeCss(theme: WidgetTheme): string {
    const t = sanitizeTheme(theme);
    const stack = FONT_STACKS[t.fontFamily] ?? FONT_STACKS.system;

    return `.amd-root{
--amd-accent:var(--askmydocs-accent,${t.accent});
--amd-accent-fg:var(--askmydocs-accent-foreground,${t.accentForeground});
--amd-bg:var(--askmydocs-background,${t.background});
--amd-fg:var(--askmydocs-foreground,${t.foreground});
--amd-muted:var(--askmydocs-muted,${t.muted});
--amd-border:var(--askmydocs-border,${t.border});
--amd-font:var(--askmydocs-font-family,${stack});
--amd-font-size:var(--askmydocs-font-size,${t.fontSize}px);
--amd-header-bg:var(--askmydocs-header-background,${t.headerBackground});
--amd-header-fg:var(--askmydocs-header-foreground,${t.headerForeground});
--amd-launcher-bg:var(--askmydocs-launcher-background,${t.launcherBackground});
--amd-launcher-fg:var(--askmydocs-launcher-foreground,${t.launcherForeground});
--amd-user-bg:var(--askmydocs-user-bubble-background,${t.userBubbleBackground});
--amd-user-fg:var(--askmydocs-user-bubble-foreground,${t.userBubbleForeground});
--amd-assistant-bg:var(--askmydocs-assistant-bubble-background,${t.assistantBubbleBackground});
--amd-assistant-fg:var(--askmydocs-assistant-bubble-foreground,${t.assistantBubbleForeground});
--amd-composer-bg:var(--askmydocs-composer-background,${t.composerBackground});
--amd-input-bg:var(--askmydocs-input-background,${t.inputBackground});
--amd-input-fg:var(--askmydocs-input-foreground,${t.inputForeground});
--amd-input-placeholder:var(--askmydocs-input-placeholder,${t.inputPlaceholder});
--amd-citation-bg:var(--askmydocs-citation-background,${t.citationBackground});
--amd-citation-fg:var(--askmydocs-citation-foreground,${t.citationForeground});
--amd-focus-ring:var(--askmydocs-focus-ring,${t.focusRing});
--amd-system-bg:var(--askmydocs-system-background,${t.systemBackground});
--amd-system-fg:var(--askmydocs-system-foreground,${t.systemForeground});
--amd-error-bg:var(--askmydocs-error-background,${t.errorBackground});
--amd-error-fg:var(--askmydocs-error-foreground,${t.errorForeground});
--amd-confirm-bg:var(--askmydocs-confirm-background,${t.confirmBackground});
--amd-confirm-fg:var(--askmydocs-confirm-foreground,${t.confirmForeground});
--amd-confirm-border:var(--askmydocs-confirm-border,${t.confirmBorder});
--amd-source-sidebar-bg:var(--askmydocs-source-sidebar-background,${t.sourceSidebarBackground});
--amd-source-sidebar-fg:var(--askmydocs-source-sidebar-foreground,${t.sourceSidebarForeground});
--amd-source-backdrop:var(--askmydocs-source-backdrop,${t.sourceBackdrop});
--amd-launcher-offset-x:var(--askmydocs-launcher-offset-x,${t.launcherOffsetX}px);
--amd-launcher-offset-y:var(--askmydocs-launcher-offset-y,${t.launcherOffsetY}px);
--amd-launcher-size:var(--askmydocs-launcher-size,${t.launcherSize}px);
--amd-launcher-shadow:var(--askmydocs-launcher-shadow,${SHADOW_PRESETS[t.launcherShadow]});
--amd-panel-width:var(--askmydocs-panel-width,${t.panelWidth}px);
--amd-panel-height:var(--askmydocs-panel-height,${t.panelHeight}px);
--amd-panel-radius:var(--askmydocs-panel-radius,${t.panelRadius}px);
--amd-panel-shadow:var(--askmydocs-panel-shadow,${SHADOW_PRESETS[t.panelShadow]});
--amd-header-padding-x:var(--askmydocs-header-padding-x,${t.headerPaddingX}px);
--amd-header-padding-y:var(--askmydocs-header-padding-y,${t.headerPaddingY}px);
--amd-messages-padding:var(--askmydocs-messages-padding,${t.messagesPadding}px);
--amd-message-gap:var(--askmydocs-message-gap,${t.messageGap}px);
--amd-bubble-padding-x:var(--askmydocs-bubble-padding-x,${t.bubblePaddingX}px);
--amd-bubble-padding-y:var(--askmydocs-bubble-padding-y,${t.bubblePaddingY}px);
--amd-bubble-radius:var(--askmydocs-bubble-radius,${t.bubbleRadius}px);
--amd-bubble-max-width:var(--askmydocs-bubble-max-width,${t.bubbleMaxWidth}%);
--amd-composer-padding:var(--askmydocs-composer-padding,${t.composerPadding}px);
--amd-input-radius:var(--askmydocs-input-radius,${t.inputRadius}px);
--amd-button-radius:var(--askmydocs-button-radius,${t.buttonRadius}px);
--amd-logo-height:var(--askmydocs-logo-height,${t.logoHeight}px);
--amd-source-viewer-width:var(--askmydocs-source-viewer-width,${t.sourceViewerWidth}px);
--amd-source-viewer-radius:var(--askmydocs-source-viewer-radius,${t.sourceViewerRadius}px);
}`;
}
