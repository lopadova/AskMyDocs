/**
 * CSS del widget, iniettato come stringa nello shadow root (open → isolato ma
 * testabile da Playwright). Niente Tailwind: poche regole scritte a mano per
 * tenere il bundle leggero.
 *
 * Tematizzazione (D-grafica): i valori personalizzabili sono CSS custom
 * properties con FALLBACK al default. Il fallback È il default canonico,
 * speculare a {@link DEFAULT_THEME} e a WidgetThemeService::defaults() (PHP) —
 * R9 docs-match-code. {@link buildThemeCss} emette SOLO un blocco di var
 * override; le varianti strutturali (lato/forma launcher) sono classi.
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
    fontFamily: 'system',
    fontSize: 14,
    launcherSide: 'right',
    launcherShape: 'pill',
    launcherLabel: '',
    launcherIcon: 'chat',
    launcherIconUrl: '',
    panelWidth: 380,
    panelHeight: 560,
    panelRadius: 14,
    panelTitle: '',
    headerLogoUrl: '',
};

const FONT_KEYS = Object.keys(FONT_STACKS) as WidgetFontKey[];
/** Mirror di WidgetThemeService::MODES (PHP). */
export const WIDGET_MODES: WidgetMode[] = ['helper', 'inline'];
const LAUNCHER_SIDES: LauncherSide[] = ['right', 'left'];
const LAUNCHER_SHAPES: LauncherShape[] = ['pill', 'rounded', 'circle'];
const LAUNCHER_ICONS: LauncherIcon[] = ['chat', 'sparkles', 'help', 'none'];

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
    position: fixed; right: 20px; bottom: 20px; z-index: 2147483000;
    display: inline-flex; align-items: center; gap: 8px;
    padding: 12px 16px; border: none; border-radius: 999px;
    background: var(--amd-launcher-bg, var(--amd-accent)); color: var(--amd-launcher-fg, #fff);
    font-size: var(--amd-font-size, 14px); font-weight: 600;
    cursor: pointer; box-shadow: 0 6px 20px rgba(0,0,0,.18);
}
.amd-launcher:focus-visible { outline: 3px solid #93c5fd; outline-offset: 2px; }
.amd-launcher-icon { display: inline-flex; align-items: center; }
.amd-launcher-icon svg { width: 18px; height: 18px; display: block; }
.amd-launcher-icon img { width: 18px; height: 18px; object-fit: contain; display: block; }
.amd-launcher-label { white-space: nowrap; }
.amd-launcher.amd-shape-rounded { border-radius: 14px; }
.amd-launcher.amd-shape-circle { width: 56px; height: 56px; padding: 0; justify-content: center; border-radius: 50%; }
.amd-launcher.amd-shape-circle .amd-launcher-label { display: none; }
.amd-root.amd-side-left .amd-launcher,
.amd-root.amd-side-left .amd-panel { right: auto; left: 20px; }
.amd-panel {
    position: fixed; right: 20px; bottom: 84px; z-index: 2147483000;
    width: var(--amd-panel-width, 380px); max-width: calc(100vw - 40px);
    height: var(--amd-panel-height, 560px); max-height: calc(100vh - 120px);
    display: none; flex-direction: column;
    background: var(--amd-bg); border: 1px solid var(--amd-border); border-radius: var(--amd-panel-radius, 14px);
    box-shadow: 0 12px 40px rgba(0,0,0,.22); overflow: hidden;
}
.amd-panel[data-open="true"] { display: flex; }
.amd-header {
    display: flex; align-items: center; gap: 8px;
    padding: 12px 14px; background: var(--amd-header-bg, var(--amd-accent)); color: var(--amd-header-fg, #fff);
}
.amd-logo { height: 22px; width: auto; max-width: 120px; border-radius: 4px; flex: 0 0 auto; }
.amd-title { font-size: 14px; font-weight: 700; flex: 1 1 auto; }
.amd-close { background: transparent; border: none; color: inherit; font-size: 20px; cursor: pointer; line-height: 1; flex: 0 0 auto; }
.amd-messages { flex: 1; overflow-y: auto; padding: 14px; display: flex; flex-direction: column; gap: 10px; }
.amd-msg { max-width: 88%; padding: 9px 12px; border-radius: 12px; font-size: var(--amd-font-size, 14px); line-height: 1.45; white-space: pre-wrap; word-wrap: break-word; }
.amd-msg.user { align-self: flex-end; background: var(--amd-user-bg, var(--amd-accent)); color: var(--amd-user-fg, #fff); border-bottom-right-radius: 4px; }
.amd-msg.assistant { align-self: flex-start; background: var(--amd-assistant-bg, #f3f4f6); color: var(--amd-assistant-fg, var(--amd-fg)); border-bottom-left-radius: 4px; }
.amd-msg.system { align-self: center; background: #fff7ed; color: #9a3412; font-size: 12.5px; }
.amd-msg.error { align-self: center; background: #fef2f2; color: #b91c1c; font-size: 12.5px; }
.amd-citations { margin-top: 6px; display: flex; flex-wrap: wrap; gap: 4px; }
.amd-cite { font-size: 11px; background: #e0e7ff; color: #3730a3; padding: 2px 6px; border-radius: 6px; }
.amd-status { padding: 0 14px 6px; font-size: 12px; color: var(--amd-muted); min-height: 16px; }
.amd-confirm { margin: 6px 14px; padding: 10px; border: 1px solid #fde68a; background: #fffbeb; border-radius: 10px; font-size: 13px; }
.amd-confirm-actions { margin-top: 8px; display: flex; gap: 8px; }
.amd-btn { padding: 7px 12px; border-radius: 8px; border: 1px solid var(--amd-border); background: #fff; font-size: 13px; cursor: pointer; }
.amd-btn.primary { background: var(--amd-accent); color: #fff; border-color: var(--amd-accent); }
.amd-composer { display: flex; gap: 8px; padding: 10px; border-top: 1px solid var(--amd-border); }
.amd-input { flex: 1; resize: none; border: 1px solid var(--amd-border); border-radius: 10px; padding: 9px 10px; font: inherit; font-size: var(--amd-font-size, 14px); min-height: 40px; max-height: 120px; }
.amd-input:focus-visible { outline: 2px solid var(--amd-accent); outline-offset: 0; }
.amd-send { padding: 0 14px; border: none; border-radius: 10px; background: var(--amd-accent); color: #fff; font-weight: 600; cursor: pointer; }
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
`;

/** Alias retro-compat: il loader storico iniettava `WIDGET_CSS`. */
export const WIDGET_CSS = BASE_WIDGET_CSS;

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
            : typeof value === 'string' && value.trim() !== ''
              ? Number(value)
              : NaN;

    return Number.isFinite(n) ? Math.max(min, Math.min(max, Math.round(n))) : fallback;
}

function text(value: unknown, max: number): string {
    if (typeof value !== 'string') {
        return '';
    }

    // Via i caratteri di controllo (finiscono in textContent del DOM).
    // eslint-disable-next-line no-control-regex
    return value.replace(/[\u0000-\u001F\u007F]/g, '').trim().slice(0, max);
}

/** URL immagine: solo https, senza meta-caratteri pericolosi. Altrimenti ''. */
function url(value: unknown): string {
    if (typeof value !== 'string' || value.trim() === '') {
        return '';
    }
    const trimmed = value.trim();
    if (/["'()<>\s\\]/.test(trimmed)) {
        return '';
    }
    try {
        return new URL(trimmed).protocol === 'https:' ? trimmed : '';
    } catch {
        return '';
    }
}

/**
 * Normalizza un tema (parziale/non fidato) in un {@link WidgetTheme} completo e
 * sicuro. Difesa in profondità per i temi INLINE del sito ospite, che bypassano
 * la validazione del backend: ogni valore non valido degrada al default.
 */
export function sanitizeTheme(raw: unknown): WidgetTheme {
    const r = raw && typeof raw === 'object' ? (raw as Record<string, unknown>) : {};
    const d = DEFAULT_THEME;

    return {
        mode: pick(r.mode, WIDGET_MODES, d.mode),
        accent: color(r.accent, d.accent),
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
        fontFamily: pick(r.fontFamily, FONT_KEYS, d.fontFamily),
        fontSize: int(r.fontSize, 12, 18, d.fontSize),
        launcherSide: pick(r.launcherSide, LAUNCHER_SIDES, d.launcherSide),
        launcherShape: pick(r.launcherShape, LAUNCHER_SHAPES, d.launcherShape),
        launcherLabel: text(r.launcherLabel, 60),
        launcherIcon: pick(r.launcherIcon, LAUNCHER_ICONS, d.launcherIcon),
        launcherIconUrl: url(r.launcherIconUrl),
        panelWidth: int(r.panelWidth, 320, 480, d.panelWidth),
        panelHeight: int(r.panelHeight, 420, 680, d.panelHeight),
        panelRadius: int(r.panelRadius, 0, 24, d.panelRadius),
        panelTitle: text(r.panelTitle, 60),
        headerLogoUrl: url(r.headerLogoUrl),
    };
}

/**
 * Blocco CSS di override delle var, da iniettare DOPO {@link BASE_WIDGET_CSS}.
 * Solo custom properties (colori hex, font allowlist, numeri+px) → nessuna
 * possibilità di evasione dal blocco. Lato/forma del launcher sono gestiti via
 * classi (vedi panel.ts), non qui.
 */
export function buildThemeCss(theme: WidgetTheme): string {
    const t = sanitizeTheme(theme);
    const stack = FONT_STACKS[t.fontFamily] ?? FONT_STACKS.system;

    return `.amd-root{
--amd-accent:${t.accent};
--amd-bg:${t.background};
--amd-fg:${t.foreground};
--amd-muted:${t.muted};
--amd-border:${t.border};
--amd-font:${stack};
--amd-font-size:${t.fontSize}px;
--amd-header-bg:${t.headerBackground};
--amd-header-fg:${t.headerForeground};
--amd-launcher-bg:${t.launcherBackground};
--amd-launcher-fg:${t.launcherForeground};
--amd-user-bg:${t.userBubbleBackground};
--amd-user-fg:${t.userBubbleForeground};
--amd-assistant-bg:${t.assistantBubbleBackground};
--amd-assistant-fg:${t.assistantBubbleForeground};
--amd-panel-width:${t.panelWidth}px;
--amd-panel-height:${t.panelHeight}px;
--amd-panel-radius:${t.panelRadius}px;
}`;
}
