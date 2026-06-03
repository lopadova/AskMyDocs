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
 */
import { WidgetPanel } from './ui/panel';
import { WIDGET_CSS } from './ui/styles';
import type { WidgetConfig } from './types';

declare global {
    interface Window {
        AskMyDocsWidget?: Partial<WidgetConfig>;
    }
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

    const host = document.createElement('div');
    host.setAttribute('data-askmydocs-widget', '');
    document.body.appendChild(host);

    const shadow = host.attachShadow({ mode: 'open' });
    const style = document.createElement('style');
    style.textContent = WIDGET_CSS;
    shadow.appendChild(style);

    const root = document.createElement('div');
    root.className = 'amd-root';
    root.setAttribute('data-askmydocs-widget', '');
    shadow.appendChild(root);

    // eslint-disable-next-line no-new
    new WidgetPanel(root, cfg as WidgetConfig);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
