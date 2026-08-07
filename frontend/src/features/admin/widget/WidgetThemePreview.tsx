import { useEffect, useRef, useState } from 'react';

import {
    BASE_WIDGET_CSS,
    buildThemeCss,
    escapeHtml,
    launcherIconSvg,
    sanitizeTheme,
} from '../../../widget/ui/styles';
import type { WidgetTheme } from '../../../widget/types';

/**
 * Anteprima live e isolata del widget: monta uno Shadow DOM e vi inietta lo
 * STESSO CSS del widget reale ({@link BASE_WIDGET_CSS} + {@link buildThemeCss})
 * più una chrome statica (launcher + pannello aperto). Così l'operatore vede
 * esattamente l'effetto del tema senza rete né bridge.
 *
 * Le uniche stringhe utente iniettate (label, titolo) passano da
 * {@link escapeHtml}; gli URL immagine sono già sanificati (https) da
 * {@link sanitizeTheme}. Il CSS è costante/derivato → niente injection (R19).
 */

/**
 * Override SOLO per l'anteprima: il widget reale usa position:fixed (ancorato
 * al viewport). Qui neutralizziamo il fixed e impaginiamo launcher + pannello
 * in colonna dentro il box di preview (modalità helper).
 */
const HELPER_PREVIEW_OVERRIDE = `
.amd-root { position: relative; display: flex; flex-direction: column; gap: 16px; padding: 16px; min-height: 0; }
.amd-launcher { position: static; align-self: flex-end; }
.amd-root.amd-side-left .amd-launcher { align-self: flex-start; }
.amd-panel { position: static; display: flex; right: auto; left: auto; bottom: auto; max-width: 100%; height: 300px; max-height: 300px; align-self: center; }
`;

/**
 * Override per la modalità inline: il blocco riempie il container. Le regole
 * strutturali (pannello statico, niente launcher) vivono già in
 * BASE_WIDGET_CSS (.amd-mode-inline); qui diamo solo un'altezza concreta al
 * box di anteprima (nel widget reale è il container ospite a fornirla).
 */
const INLINE_PREVIEW_OVERRIDE = `
.amd-root.amd-mode-inline { position: relative; height: 360px; padding: 12px; }
`;
const FULLSCREEN_PREVIEW_OVERRIDE = `
.amd-root.amd-mode-fullscreen { position: relative; width: 100%; height: 360px; inset: auto; }
`;

/** Chrome statica della vista Sources: usa gli stessi token del viewer reale. */
const SOURCE_PREVIEW_OVERRIDE = `
.amd-root { min-height: 360px; padding: 12px; background: var(--amd-source-backdrop); }
.amd-preview-source-dialog {
    width: min(100%, var(--amd-source-viewer-width)); height: 336px; margin: 0 auto; overflow: hidden;
    display: grid; grid-template-columns: minmax(112px, 30%) minmax(0, 1fr);
    color: var(--amd-fg); background: var(--amd-bg); border: 1px solid var(--amd-border);
    border-radius: var(--amd-source-viewer-radius, 16px); box-shadow: var(--amd-panel-shadow);
}
.amd-preview-source-sidebar { padding: 12px 8px; color: var(--amd-source-sidebar-fg); background: var(--amd-source-sidebar-bg); border-right: 1px solid var(--amd-border); }
.amd-preview-source-kicker { margin: 0 6px 10px; font-size: 10px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; }
.amd-preview-source-item { padding: 8px; overflow: hidden; border-radius: var(--amd-button-radius); font-size: 11px; text-overflow: ellipsis; white-space: nowrap; }
.amd-preview-source-item.active { color: var(--amd-citation-fg); background: var(--amd-citation-bg); }
.amd-preview-source-main { min-width: 0; display: flex; flex-direction: column; }
.amd-preview-source-header { display: flex; align-items: center; padding: var(--amd-header-padding-y) var(--amd-header-padding-x); border-bottom: 1px solid var(--amd-border); }
.amd-preview-source-title { flex: 1; margin: 0; overflow: hidden; font-size: calc(var(--amd-font-size) + 2px); text-overflow: ellipsis; white-space: nowrap; }
.amd-preview-source-close { color: inherit; background: transparent; border: 0; font-size: 18px; }
.amd-preview-source-body { overflow: hidden; padding: var(--amd-messages-padding); font-size: var(--amd-font-size); line-height: 1.55; }
.amd-preview-source-heading { margin: 0 0 10px; font-size: 1.12em; }
.amd-preview-source-body p { margin: 0 0 10px; }
.amd-preview-source-cite { display: inline-flex; padding: 3px 7px; color: var(--amd-citation-fg); background: var(--amd-citation-bg); border-radius: var(--amd-button-radius); font-size: 10px; }
`;

/** Pannello chat condiviso da entrambe le modalità (launcher a parte). */
function panelMarkup(theme: WidgetTheme): string {
    const title = escapeHtml(theme.panelTitle || 'Assistente');
    const logo =
        theme.headerLogoUrl !== ''
            ? `<img class="amd-logo" src="${escapeHtml(theme.headerLogoUrl)}" alt="">`
            : '';
    const role = theme.mode === 'helper' ? 'dialog' : 'region';

    return `
  <section class="amd-panel" data-open="true" role="${role}" aria-label="${title}">
    <header class="amd-header">
      ${logo}
      <span class="amd-title">${title}</span>
      <button class="amd-close" type="button" aria-label="Chiudi">×</button>
    </header>
    <div class="amd-messages">
      <div class="amd-msg assistant"><div>Ciao! Come posso aiutarti oggi?</div></div>
      <div class="amd-msg user"><div>Mostrami la documentazione del prodotto.</div></div>
      <div class="amd-msg assistant">
        <div>Certo — ecco le risorse principali.</div>
        <div class="amd-citations"><button class="amd-cite" type="button">Guida prodotto</button></div>
      </div>
    </div>
    <div class="amd-status"></div>
    <form class="amd-composer">
      <textarea class="amd-input" placeholder="Scrivi una domanda…" rows="1"></textarea>
      <button class="amd-send" type="button">Invia</button>
    </form>
  </section>`;
}

function sourcesMarkup(): string {
    return `
<div class="amd-root">
  <section class="amd-preview-source-dialog" role="dialog" aria-label="Fonti">
    <aside class="amd-preview-source-sidebar">
      <p class="amd-preview-source-kicker">Fonti · 2</p>
      <div class="amd-preview-source-item active">Guida prodotto</div>
      <div class="amd-preview-source-item">Domande frequenti</div>
    </aside>
    <main class="amd-preview-source-main">
      <header class="amd-preview-source-header">
        <h2 class="amd-preview-source-title">Guida prodotto</h2>
        <button class="amd-preview-source-close" type="button" aria-label="Chiudi">×</button>
      </header>
      <article class="amd-preview-source-body">
        <span class="amd-preview-source-cite">Documento citato</span>
        <h3 class="amd-preview-source-heading">Introduzione</h3>
        <p>Consulta il contenuto completo della fonte senza lasciare la conversazione.</p>
        <p>Le sezioni mantengono gerarchia, spaziatura e colori del sito ospite.</p>
      </article>
    </main>
  </section>
</div>`;
}

function previewMarkup(theme: WidgetTheme, view: 'chat' | 'sources'): string {
    if (view === 'sources') {
        return sourcesMarkup();
    }

    // Inline: solo il blocco chat, nessun launcher.
    if (theme.mode === 'inline') {
        return `<div class="amd-root amd-mode-inline">${panelMarkup(theme)}</div>`;
    }
    if (theme.mode === 'fullscreen') {
        return `<div class="amd-root amd-mode-fullscreen">${panelMarkup(theme)}</div>`;
    }

    const label = escapeHtml(theme.launcherLabel || 'Chiedi all’assistente');
    const iconInner =
        theme.launcherIconUrl !== ''
            ? `<img src="${escapeHtml(theme.launcherIconUrl)}" alt="">`
            : launcherIconSvg(theme.launcherIcon);
    const iconStyle = iconInner === '' ? ' style="display:none"' : '';
    const sideClass = theme.launcherSide === 'left' ? ' amd-side-left' : '';

    return `
<div class="amd-root${sideClass}">
  <button class="amd-launcher amd-shape-${theme.launcherShape}" type="button" aria-label="${label}">
    <span class="amd-launcher-icon" aria-hidden="true"${iconStyle}>${iconInner}</span>
    <span class="amd-launcher-label">${label}</span>
  </button>
  ${panelMarkup(theme)}
</div>`;
}

export function WidgetThemePreview({ theme }: { theme: WidgetTheme }) {
    const hostRef = useRef<HTMLDivElement | null>(null);
    const shadowRef = useRef<ShadowRoot | null>(null);
    const [view, setView] = useState<'chat' | 'sources'>('chat');

    useEffect(() => {
        const host = hostRef.current;
        if (!host) {
            return;
        }
        // Attacca lo shadow root una sola volta (StrictMode ri-invoca l'effect).
        if (!shadowRef.current) {
            shadowRef.current = host.shadowRoot ?? host.attachShadow({ mode: 'open' });
        }
        const t = sanitizeTheme(theme);
        const override =
            view === 'sources'
                ? SOURCE_PREVIEW_OVERRIDE
                : t.mode === 'inline'
                ? INLINE_PREVIEW_OVERRIDE
                : t.mode === 'fullscreen'
                  ? FULLSCREEN_PREVIEW_OVERRIDE
                  : HELPER_PREVIEW_OVERRIDE;
        shadowRef.current.innerHTML = `<style>${BASE_WIDGET_CSS}${buildThemeCss(t)}${override}</style>${previewMarkup(t, view)}`;
    }, [theme, view]);

    return (
        <div
            data-testid="admin-widget-appearance-preview"
            className="overflow-hidden rounded-lg border border-border bg-[var(--bg-2)]"
        >
            <div ref={hostRef} />
            <div className="flex justify-center gap-1 border-t border-border p-2" role="group" aria-label="Preview surface">
                <button
                    type="button"
                    data-testid="admin-widget-appearance-preview-chat"
                    aria-pressed={view === 'chat'}
                    onClick={() => setView('chat')}
                    className="rounded-md border border-border px-3 py-1 text-xs aria-pressed:bg-[var(--accent-a)] aria-pressed:text-white"
                >
                    Chat
                </button>
                <button
                    type="button"
                    data-testid="admin-widget-appearance-preview-sources"
                    aria-pressed={view === 'sources'}
                    onClick={() => setView('sources')}
                    className="rounded-md border border-border px-3 py-1 text-xs aria-pressed:bg-[var(--accent-a)] aria-pressed:text-white"
                >
                    Sources
                </button>
            </div>
        </div>
    );
}
