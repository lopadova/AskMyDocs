import { useEffect, useRef } from 'react';

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

/** Pannello chat condiviso da entrambe le modalità (launcher a parte). */
function panelMarkup(theme: WidgetTheme): string {
    const title = escapeHtml(theme.panelTitle || 'Assistente');
    const logo =
        theme.headerLogoUrl !== ''
            ? `<img class="amd-logo" src="${escapeHtml(theme.headerLogoUrl)}" alt="">`
            : '';
    const role = theme.mode === 'inline' ? 'region' : 'dialog';

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
      <div class="amd-msg assistant"><div>Certo — ecco le risorse principali.</div></div>
    </div>
    <div class="amd-status"></div>
    <form class="amd-composer">
      <textarea class="amd-input" placeholder="Scrivi una domanda…" rows="1"></textarea>
      <button class="amd-send" type="button">Invia</button>
    </form>
  </section>`;
}

function previewMarkup(theme: WidgetTheme): string {
    // Inline: solo il blocco chat, nessun launcher.
    if (theme.mode === 'inline') {
        return `<div class="amd-root amd-mode-inline">${panelMarkup(theme)}</div>`;
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
        const override = t.mode === 'inline' ? INLINE_PREVIEW_OVERRIDE : HELPER_PREVIEW_OVERRIDE;
        shadowRef.current.innerHTML = `<style>${BASE_WIDGET_CSS}${buildThemeCss(t)}${override}</style>${previewMarkup(t)}`;
    }, [theme]);

    return (
        <div
            data-testid="admin-widget-appearance-preview"
            className="overflow-hidden rounded-lg border border-border bg-[var(--bg-2)]"
        >
            <div ref={hostRef} />
        </div>
    );
}
