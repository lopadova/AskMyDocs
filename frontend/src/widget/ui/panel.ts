/**
 * WidgetPanel — la UI chat in TS vanilla dentro lo shadow root (port del
 * ChatUI di KITT). Costruisce launcher + pannello, istanzia il Bridge e ne
 * implementa gli eventi rendendo messaggi, citazioni, conferme e stati.
 *
 * Testid (R11/R29) su ogni elemento azionabile per i test Playwright; ARIA
 * su input/bottoni; stati osservabili via data-state (R14/R15).
 */
import { Bridge, type BridgeEvents } from '../core/bridge';
import type { Artifact } from '../core/bridge';
import type { Citation, ToolCall, WidgetConfig, WidgetMode, WidgetTheme } from '../types';
import { OverlaySystem } from './overlay';
import { DEFAULT_THEME, buildThemeCss, launcherIconSvg, sanitizeTheme } from './styles';
import { UiArtifactRenderer } from './UiArtifactRenderer';

const DEFAULT_TITLE = 'Assistente';
const DEFAULT_LAUNCHER_LABEL = 'Chiedi all’assistente';

export class WidgetPanel {
    private readonly root: HTMLElement;
    private readonly cfg: WidgetConfig;
    /** Modalità di layout decisa dal loader (dipende dal mount). Autoritativa
     *  per il layout: il `theme.mode` server è solo informativo. */
    private readonly mode: WidgetMode;
    private readonly bridge: Bridge;
    private readonly launcher: HTMLButtonElement;
    private readonly launcherIconSlot: HTMLElement;
    private readonly launcherLabelEl: HTMLElement;
    private readonly panel: HTMLElement;
    private readonly header: HTMLElement;
    private readonly titleEl: HTMLElement;
    private readonly messages: HTMLElement;
    private readonly status: HTMLElement;
    private readonly input: HTMLTextAreaElement;
    private readonly send: HTMLButtonElement;
    /** <style> del tema, dentro `root` (scope shadow) — aggiornabile. */
    private readonly themeStyle: HTMLStyleElement;
    /** Logo opzionale nell'header (creato on-demand). */
    private logo: HTMLImageElement | null = null;
    /** Tema server da /setup (null finché non risolto). */
    private serverTheme: Partial<WidgetTheme> | null = null;
    /** Tema effettivo applicato (default < server < inline). */
    private theme: WidgetTheme = DEFAULT_THEME;
    private confirmBar: HTMLElement | null = null;
    /**
     * M4.8 — feedback visivo agentico (freccia/tour). Monta su `<body>` della
     * pagina ospite (non nello shadow root) per coprire l'intera viewport.
     */
    private readonly overlay = new OverlaySystem();

    constructor(root: HTMLElement, cfg: WidgetConfig, mode: WidgetMode = 'helper') {
        this.root = root;
        this.cfg = cfg;
        this.mode = mode;

        // <style> del tema dentro root (sibling delle classi, scope shadow).
        this.themeStyle = this.el('style', '', { 'data-testid': 'askmydocs-widget-theme' });

        // Launcher = icona (slot) + etichetta. Icona/etichetta li popola
        // applyTheme; aria-label garantisce il nome accessibile (R15).
        this.launcher = this.el('button', 'amd-launcher', { 'data-testid': 'askmydocs-widget-launcher', type: 'button' });
        this.launcherIconSlot = this.el('span', 'amd-launcher-icon', { 'aria-hidden': 'true' });
        this.launcherLabelEl = this.el('span', 'amd-launcher-label');
        this.launcher.append(this.launcherIconSlot, this.launcherLabelEl);

        this.panel = this.el('section', 'amd-panel', {
            'data-testid': 'askmydocs-widget-panel',
            'data-open': 'false',
            'data-state': 'idle',
            role: 'dialog',
        });

        this.header = this.el('header', 'amd-header');
        this.titleEl = this.el('span', 'amd-title');
        const close = this.el('button', 'amd-close', { 'data-testid': 'askmydocs-widget-close', type: 'button', 'aria-label': 'Chiudi' });
        close.textContent = '×';
        this.header.append(this.titleEl, close);

        this.messages = this.el('div', 'amd-messages', { 'data-testid': 'askmydocs-widget-messages', role: 'log', 'aria-live': 'polite' });
        this.status = this.el('div', 'amd-status', { 'data-testid': 'askmydocs-widget-status', 'aria-live': 'polite' });

        const composer = this.el('form', 'amd-composer');
        this.input = this.el('textarea', 'amd-input', {
            'data-testid': 'askmydocs-widget-input',
            'aria-label': 'Messaggio',
            placeholder: 'Scrivi una domanda o un comando…',
            rows: '1',
        });
        this.send = this.el('button', 'amd-send', { 'data-testid': 'askmydocs-widget-send', type: 'submit' });
        this.send.textContent = 'Invia';
        composer.append(this.input, this.send);

        this.panel.append(this.header, this.messages, this.status, composer);
        root.append(this.themeStyle, this.launcher, this.panel);

        this.launcher.addEventListener('click', () => this.toggle());
        close.addEventListener('click', () => this.setOpen(false));
        composer.addEventListener('submit', (e) => {
            e.preventDefault();
            this.submitInput();
        });
        this.input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                this.submitInput();
            }
        });

        this.bridge = new Bridge(cfg, this.events());

        // Fase 1: tema inline + default subito. Fase 2: ri-applica col server.
        this.applyTheme();
        void this.init();

        if (this.mode === 'inline') {
            // Blocco chat a pagina: sempre aperto, statico, senza launcher/close
            // (nascosti via CSS .amd-mode-inline). role=region anziché dialog: è
            // una sezione inline, non un overlay modale (R15). Niente focus forzato
            // al boot → non scrolla la pagina verso il widget.
            this.root.classList.add('amd-mode-inline');
            this.panel.setAttribute('role', 'region');
            this.panel.dataset.open = 'true';
        } else if (cfg.autoOpen) {
            this.setOpen(true);
        }
    }

    /** Fase 2: carica /setup e ri-applica il tema fondendo quello server. */
    private async init(): Promise<void> {
        const setup = await this.bridge.loadSetup();
        const serverTheme = setup && typeof setup.theme === 'object' ? setup.theme : null;
        if (serverTheme) {
            this.serverTheme = serverTheme as Partial<WidgetTheme>;
            this.applyTheme();
        }
    }

    /**
     * Calcola il tema effettivo (default < server < inline) e lo applica: var
     * CSS via {@link buildThemeCss}, classi lato/forma, icona/etichetta del
     * launcher, logo/titolo dell'header. Precedenza ai top-level cfg.title /
     * cfg.launcherLabel (back-compat).
     */
    private applyTheme(): void {
        this.theme = sanitizeTheme({
            ...DEFAULT_THEME,
            ...(this.serverTheme ?? {}),
            ...(this.cfg.theme ?? {}),
        });
        const t = this.theme;

        this.themeStyle.textContent = buildThemeCss(t);

        // Lato (classe su root) + forma (classe su launcher).
        this.root.classList.toggle('amd-side-left', t.launcherSide === 'left');
        this.launcher.classList.remove('amd-shape-pill', 'amd-shape-rounded', 'amd-shape-circle');
        this.launcher.classList.add(`amd-shape-${t.launcherShape}`);

        // Icona del launcher: URL custom > SVG built-in > nessuna.
        this.launcherIconSlot.replaceChildren();
        if (t.launcherIconUrl !== '') {
            const img = this.el('img', '', { src: t.launcherIconUrl, alt: '' });
            this.launcherIconSlot.append(img);
            this.launcherIconSlot.style.display = '';
        } else {
            const svg = launcherIconSvg(t.launcherIcon);
            if (svg !== '') {
                // SVG = costante fidata (mai input utente) — vedi styles.ts.
                this.launcherIconSlot.innerHTML = svg;
                this.launcherIconSlot.style.display = '';
            } else {
                this.launcherIconSlot.style.display = 'none';
            }
        }

        // Etichetta launcher + titolo pannello (top-level config vince).
        const launcherLabel = this.cfg.launcherLabel || t.launcherLabel || DEFAULT_LAUNCHER_LABEL;
        this.launcherLabelEl.textContent = launcherLabel;
        this.launcher.setAttribute('aria-label', launcherLabel);

        const title = this.cfg.title || t.panelTitle || DEFAULT_TITLE;
        this.titleEl.textContent = title;
        this.panel.setAttribute('aria-label', title);

        // Logo header (https, sanificato): crea/aggiorna o rimuovi.
        if (t.headerLogoUrl !== '') {
            if (!this.logo) {
                this.logo = this.el('img', 'amd-logo', { alt: '' });
                this.header.insertBefore(this.logo, this.header.firstChild);
            }
            this.logo.src = t.headerLogoUrl;
        } else if (this.logo) {
            this.logo.remove();
            this.logo = null;
        }
    }

    private events(): BridgeEvents {
        return {
            onBusy: (busy) => {
                this.panel.dataset.state = busy ? 'busy' : 'idle';
                this.send.disabled = busy;
                this.status.textContent = busy ? 'L’assistente sta lavorando…' : '';
            },
            onAnswer: (text, citations) => this.appendAssistant(text, citations),
            onBotText: (text) => this.appendAssistant(text, []),
            onAction: (tool) => this.appendSystem(`Azione: ${tool}`, 'system'),
            onAsk: (question, options) => this.appendAsk(question, options),
            onDone: (summary) => this.appendSystem(`✓ ${summary}`, 'system'),
            onBlocked: (reason) => this.appendSystem(`⚠ ${reason}`, 'system'),
            onError: (message) => this.appendSystem(message, 'error'),
            onConfirm: (toolCall, accept, reject) => this.showConfirm(toolCall, accept, reject),
            onArtifact: (artifact, hasResults, interactionMode) => this.renderArtifact(artifact, hasResults, interactionMode),
            onPointAt: (target) => this.overlay.pointAt(target),
            onTourStep: (target, message, index, total) => this.overlay.tourStep(target, message, index, total),
            onClearOverlay: () => this.overlay.clear(),
        };
    }

    private submitInput(): void {
        const text = this.input.value.trim();
        if (text === '' || this.bridge.isBusy()) {
            return;
        }
        this.input.value = '';
        this.appendUser(text);
        void this.bridge.sendUserMessage(text);
    }

    private toggle(): void {
        this.setOpen(this.panel.dataset.open !== 'true');
    }

    private setOpen(open: boolean): void {
        this.panel.dataset.open = open ? 'true' : 'false';
        if (open) {
            this.input.focus();
        }
    }

    private appendUser(text: string): void {
        this.appendMessage(text, 'user');
    }

    private appendAssistant(text: string, citations: Citation[]): void {
        const msg = this.appendMessage(text, 'assistant');
        if (citations.length > 0) {
            const wrap = this.el('div', 'amd-citations');
            for (const c of citations.slice(0, 8)) {
                const chip = this.el('span', 'amd-cite', { 'data-testid': 'askmydocs-widget-citation' });
                chip.textContent = c.title || c.source_path || 'fonte';
                wrap.append(chip);
            }
            msg.append(wrap);
        }
    }

    private appendSystem(text: string, kind: 'system' | 'error'): void {
        const testid = kind === 'error' ? 'askmydocs-widget-error' : 'askmydocs-widget-system';
        this.appendMessage(text, kind, testid);
    }

    private appendAsk(question: string, options: string[]): void {
        const msg = this.appendMessage(question, 'assistant');
        if (options.length > 0) {
            const wrap = this.el('div', 'amd-ask-options');
            for (const opt of options) {
                const btn = this.el('button', 'amd-btn', { type: 'button', 'data-testid': 'askmydocs-widget-ask-option' });
                btn.textContent = opt;
                btn.addEventListener('click', () => {
                    this.appendUser(opt);
                    void this.bridge.sendUserMessage(opt);
                });
                wrap.append(btn);
            }
            msg.append(wrap);
        }
    }

    private showConfirm(toolCall: ToolCall, accept: () => void, reject: () => void): void {
        this.dismissConfirm();
        const bar = this.el('div', 'amd-confirm', { 'data-testid': 'askmydocs-widget-confirm' });
        const label = this.el('div');
        label.textContent = `Confermi l’azione "${toolCall.tool}"?`;
        const actions = this.el('div', 'amd-confirm-actions');
        const yes = this.el('button', 'amd-btn primary', { type: 'button', 'data-testid': 'askmydocs-widget-confirm-accept' });
        yes.textContent = 'Conferma';
        const no = this.el('button', 'amd-btn', { type: 'button', 'data-testid': 'askmydocs-widget-confirm-reject' });
        no.textContent = 'Annulla';
        yes.addEventListener('click', () => {
            this.dismissConfirm();
            accept();
        });
        no.addEventListener('click', () => {
            this.dismissConfirm();
            reject();
        });
        actions.append(yes, no);
        bar.append(label, actions);
        this.status.insertAdjacentElement('afterend', bar);
        this.confirmBar = bar;
    }

    private dismissConfirm(): void {
        this.confirmBar?.remove();
        this.confirmBar = null;
    }

    /**
     * M4: renderizza un artifact nella chat.
     * Delega al UiArtifactRenderer per la creazione del DOM,
     * poi lo appende al messaggio corrente.
     */
    private renderArtifact(artifact: Artifact, hasResults: boolean, interactionMode: string): void {
        const msg = this.appendMessage('', 'assistant', 'askmydocs-widget-artifact');
        const renderer = new UiArtifactRenderer();
        const container = renderer.render(artifact, hasResults, interactionMode);
        msg.append(container);

        if (!hasResults) {
            const notice = this.el('div', 'amd-notice');
            notice.textContent = 'Nessun risultato trovato.';
            msg.append(notice);
        }
    }

    private appendMessage(text: string, kind: string, testid = 'askmydocs-widget-message'): HTMLElement {
        const msg = this.el('div', `amd-msg ${kind}`, { 'data-testid': testid });
        const body = this.el('div');
        body.textContent = text;
        msg.append(body);
        this.messages.append(msg);
        this.messages.scrollTop = this.messages.scrollHeight;

        return msg;
    }

    private el<K extends keyof HTMLElementTagNameMap>(
        tag: K,
        className = '',
        attrs: Record<string, string> = {},
    ): HTMLElementTagNameMap[K] {
        const node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        for (const [k, v] of Object.entries(attrs)) {
            node.setAttribute(k, v);
        }

        return node;
    }
}
