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
import type { Citation, ToolCall, WidgetConfig } from '../types';
import { UiArtifactRenderer } from './UiArtifactRenderer';

export class WidgetPanel {
    private readonly bridge: Bridge;
    private readonly launcher: HTMLButtonElement;
    private readonly panel: HTMLElement;
    private readonly messages: HTMLElement;
    private readonly status: HTMLElement;
    private readonly input: HTMLTextAreaElement;
    private readonly send: HTMLButtonElement;
    private confirmBar: HTMLElement | null = null;

    constructor(root: HTMLElement, cfg: WidgetConfig) {
        const title = cfg.title ?? 'Assistente';
        const launcherLabel = cfg.launcherLabel ?? 'Chiedi all’assistente';

        this.launcher = this.el('button', 'amd-launcher', { 'data-testid': 'askmydocs-widget-launcher', type: 'button' });
        this.launcher.textContent = `💬 ${launcherLabel}`;

        this.panel = this.el('section', 'amd-panel', {
            'data-testid': 'askmydocs-widget-panel',
            'data-open': 'false',
            'data-state': 'idle',
            role: 'dialog',
            'aria-label': title,
        });

        const header = this.el('header', 'amd-header');
        const titleEl = this.el('span', 'amd-title');
        titleEl.textContent = title;
        const close = this.el('button', 'amd-close', { 'data-testid': 'askmydocs-widget-close', type: 'button', 'aria-label': 'Chiudi' });
        close.textContent = '×';
        header.append(titleEl, close);

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

        this.panel.append(header, this.messages, this.status, composer);
        root.append(this.launcher, this.panel);

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

        if (cfg.autoOpen) {
            this.setOpen(true);
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
