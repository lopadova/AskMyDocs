/**
 * Entry IIFE del widget KITT embeddabile. Il sito ospite include:
 *
 *   <script>
 *     window.AskMyDocsWidget = { key: 'pk_live_…', apiBase: 'https://kb…' };
 *   </script>
 *   <script src="https://kb…/widget/askmydocs-widget.js" async></script>
 *
 * Allo start legge la config, crea un host element in light DOM marcato
 * `data-askmydocs-widget` (così SnapshotBuilder lo ignora), vi attacca uno
 * shadow root (open → isolamento CSS ma testabile da Playwright) e monta la
 * UI. Idempotente: un secondo caricamento non duplica il widget.
 *
 * Due modalità (mode):
 *   - `helper` (default) launcher flottante su <body> → pannello a comparsa;
 *   - `inline`           blocco chat che riempie il container ospite indicato
 *                        da `mount` (selettore CSS). Senza container valido il
 *                        widget logga un errore e NON monta (R14: niente
 *                        fallback silenzioso a un launcher flottante).
 */
import { WidgetPanel } from './ui/panel';
import { BASE_WIDGET_CSS } from './ui/styles';
import type { WidgetConfig, WidgetMode } from './types';

declare global {
    interface Window {
        AskMyDocsWidget?: Partial<WidgetConfig>;
    }
}

/** Modalità effettiva: inline solo se richiesta esplicitamente, altrimenti helper. */
function resolveMode(cfg: Partial<WidgetConfig>): WidgetMode {
    return cfg.mode === 'inline' ? 'inline' : 'helper';
}

/**
 * Risolve il container di mount per la modalità inline. Ritorna l'elemento o
 * `null` (con errore in console) se `mount` manca, è un selettore non valido o
 * non matcha nulla. R14: il fallimento è rumoroso, mai silenzioso.
 */
function resolveInlineContainer(cfg: Partial<WidgetConfig>): HTMLElement | null {
    const selector = typeof cfg.mount === 'string' ? cfg.mount.trim() : '';
    if (selector === '') {
        // eslint-disable-next-line no-console
        console.error('[AskMyDocsWidget] mode:"inline" richiede `mount` (selettore CSS del container, es. mount: "#askmydocs-chat").');

        return null;
    }

    let el: Element | null = null;
    try {
        el = document.querySelector(selector);
    } catch {
        // eslint-disable-next-line no-console
        console.error(`[AskMyDocsWidget] Selettore mount non valido: ${selector}`);

        return null;
    }

    if (!(el instanceof HTMLElement)) {
        // eslint-disable-next-line no-console
        console.error(`[AskMyDocsWidget] Container mount non trovato per il selettore: ${selector}`);

        return null;
    }

    return el;
}

function init(): void {
    const cfg = window.AskMyDocsWidget;
    if (!cfg || typeof cfg.key !== 'string' || cfg.key === '') {
        // eslint-disable-next-line no-console
        console.error('[AskMyDocsWidget] Config mancante: imposta window.AskMyDocsWidget = { key: "pk_…" } prima di caricare lo script.');

        return;
    }
    if (document.querySelector('[data-askmydocs-widget]')) {
        return; // già montato
    }

    const mode = resolveMode(cfg);

    // Punto di ancoraggio: in helper il widget vive su <body> (fixed); in inline
    // riempie il container ospite. Container assente ⇒ stop (errore già loggato).
    let parent: HTMLElement = document.body;
    if (mode === 'inline') {
        const container = resolveInlineContainer(cfg);
        if (!container) {
            return;
        }
        parent = container;
    }

    const host = document.createElement('div');
    host.setAttribute('data-askmydocs-widget', '');
    if (mode === 'inline') {
        // Il container ospite controlla width/height; l'host li riempie.
        host.style.width = '100%';
        host.style.height = '100%';
    }
    parent.appendChild(host);

    const shadow = host.attachShadow({ mode: 'open' });
    const style = document.createElement('style');
    style.textContent = BASE_WIDGET_CSS;
    shadow.appendChild(style);

    const root = document.createElement('div');
    root.className = 'amd-root';
    root.setAttribute('data-askmydocs-widget', '');
    shadow.appendChild(root);

    // Il pannello applica il tema (inline da cfg.theme, poi quello server da
    // /setup) iniettando il proprio <style> dentro `root` — vedi WidgetPanel.
    // La modalità è decisa qui (dipende dal mount): la passiamo esplicita.
    // eslint-disable-next-line no-new
    new WidgetPanel(root, cfg as WidgetConfig, mode);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
