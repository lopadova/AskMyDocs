import { micromark } from 'micromark';
import { gfm, gfmHtml } from 'micromark-extension-gfm';
import type {
    Citation,
    CitationChunkEvidence,
    WidgetDocumentPreview,
    WidgetDocumentSection,
} from '../types';

export type SourceCitationChunk = CitationChunkEvidence;
export type SourceCitation = Citation;
export type SourceDocumentSection = WidgetDocumentSection;
export type SourceDocumentPreview = WidgetDocumentPreview;

export type SourceDocumentFetcher = (
    documentId: number,
    signal: AbortSignal,
) => Promise<SourceDocumentPreview>;

interface ViewerCitation extends SourceCitation {
    document_id: number;
    origins: string[];
    chunks: SourceCitationChunk[];
}

/**
 * Structural CSS for the source viewer. Theme values are supplied by the same
 * --amd-* variables used by the chat; host --askmydocs-* overrides are resolved
 * by buildThemeCss before these fallbacks are read.
 */
export const SOURCE_VIEWER_CSS = `
.amd-citations { margin-top: 8px; display: grid; gap: 6px; }
.amd-citations-label { width: max-content; padding: 0; border: 0; background: transparent; color: var(--amd-muted); cursor: pointer; font: inherit; font-size: 10.5px; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; }
.amd-citations-label:hover { color: var(--amd-accent); }
.amd-citations-label:focus-visible, .amd-cite[data-openable="true"]:focus-visible { outline: 2px solid var(--amd-focus-ring, var(--amd-accent)); outline-offset: 2px; }
.amd-citation-chips { display: flex; flex-wrap: wrap; gap: 5px; }
.amd-cite { max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font: inherit; font-size: 11px; background: var(--amd-citation-bg, #e0e7ff); color: var(--amd-citation-fg, #3730a3); padding: 3px 7px; border: 1px solid color-mix(in srgb, var(--amd-citation-fg, #3730a3) 18%, transparent); border-radius: 999px; }
.amd-cite[data-openable="true"] { cursor: pointer; }
.amd-cite[data-openable="true"]:hover { border-color: var(--amd-accent); }
.amd-source-dialog {
    width: min(var(--amd-source-viewer-width, 880px), calc(100vw - 32px));
    height: min(85dvh, 820px); max-width: none; max-height: none;
    padding: 0; border: 1px solid var(--amd-border); overflow: hidden;
    border-radius: var(--amd-source-viewer-radius, 16px);
    background: var(--amd-bg); color: var(--amd-fg);
    font-family: var(--amd-font); font-size: var(--amd-font-size, 14px);
    box-shadow: var(--amd-panel-shadow, 0 20px 60px rgba(0,0,0,.28));
}
.amd-source-dialog::backdrop { background: var(--amd-source-backdrop, #0f172acc); backdrop-filter: blur(2px); }
.amd-source-shell { height: 100%; min-height: 0; display: grid; grid-template-rows: auto minmax(0,1fr); }
.amd-source-header { display: grid; grid-template-columns: minmax(0,1fr) auto; gap: 12px; padding: 16px 18px; border-bottom: 1px solid var(--amd-border); background: var(--amd-bg); }
.amd-source-kicker { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin-bottom: 5px; }
.amd-source-origin { padding: 2px 7px; border: 1px solid var(--amd-border); border-radius: 999px; color: var(--amd-muted); font-size: 10px; line-height: 1.3; text-transform: uppercase; letter-spacing: .06em; }
.amd-source-title { margin: 0; color: var(--amd-fg); font-size: 17px; line-height: 1.25; overflow-wrap: anywhere; }
.amd-source-path { margin-top: 4px; color: var(--amd-muted); font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 11px; overflow-wrap: anywhere; }
.amd-source-close { align-self: start; width: 34px; height: 34px; border: 1px solid transparent; border-radius: var(--amd-button-radius, 9px); background: transparent; color: var(--amd-muted); cursor: pointer; font: inherit; font-size: 20px; line-height: 1; }
.amd-source-close:hover { color: var(--amd-fg); background: var(--amd-assistant-bg); }
.amd-source-close:focus-visible, .amd-source-item:focus-visible, .amd-source-select:focus-visible, .amd-source-retry:focus-visible { outline: 2px solid var(--amd-focus-ring, var(--amd-accent)); outline-offset: 2px; }
.amd-source-body { min-height: 0; display: grid; grid-template-columns: minmax(180px, 240px) minmax(0,1fr); }
.amd-source-sidebar { min-height: 0; overflow-y: auto; padding: 10px; border-right: 1px solid var(--amd-border); background: var(--amd-source-sidebar-bg, #f8fafc); color: var(--amd-source-sidebar-fg, #334155); }
.amd-source-sidebar-label { padding: 4px 7px 8px; color: inherit; opacity: .72; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; }
.amd-source-item { width: 100%; display: grid; grid-template-columns: 24px minmax(0,1fr); gap: 8px; align-items: start; padding: 9px 8px; border: 1px solid transparent; border-radius: 10px; background: transparent; color: inherit; text-align: left; cursor: pointer; font: inherit; }
.amd-source-item:hover { background: var(--amd-assistant-bg); }
.amd-source-item[aria-current="true"] { border-color: var(--amd-accent); background: var(--amd-citation-bg, #e0e7ff); color: var(--amd-citation-fg, #3730a3); }
.amd-source-number { width: 22px; height: 22px; display: inline-grid; place-items: center; border-radius: 999px; background: var(--amd-accent); color: var(--amd-accent-fg, #fff); font-size: 10px; font-weight: 700; }
.amd-source-item-title { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 12px; font-weight: 650; }
.amd-source-item-path { display: block; margin-top: 2px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--amd-muted); font-size: 10.5px; }
.amd-source-mobile-picker { display: none; padding: 10px 12px; border-bottom: 1px solid var(--amd-border); background: var(--amd-source-sidebar-bg, #f8fafc); }
.amd-source-select { width: 100%; min-height: 38px; padding: 7px 9px; border: 1px solid var(--amd-border); border-radius: var(--amd-input-radius, 10px); background: var(--amd-input-bg, var(--amd-bg)); color: var(--amd-input-fg, var(--amd-fg)); font: inherit; }
.amd-source-content { min-width: 0; min-height: 0; overflow-y: auto; padding: clamp(16px, 3vw, 28px); line-height: 1.62; }
.amd-source-state { min-height: 160px; display: grid; place-items: center; color: var(--amd-muted); text-align: center; }
.amd-source-sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; border: 0; }
.amd-source-skeleton { width: min(100%, 560px); display: grid; gap: 11px; }
.amd-source-skeleton span { height: 12px; display: block; border-radius: 999px; background: linear-gradient(90deg, var(--amd-assistant-bg), var(--amd-border), var(--amd-assistant-bg)); background-size: 220% 100%; animation: amd-source-shimmer 1.2s linear infinite; }
.amd-source-skeleton span:nth-child(2) { width: 86%; } .amd-source-skeleton span:nth-child(3) { width: 64%; }
@keyframes amd-source-shimmer { to { background-position: -220% 0; } }
.amd-source-retry { margin-top: 10px; padding: 7px 13px; border: 1px solid var(--amd-border); border-radius: var(--amd-button-radius, 9px); background: var(--amd-accent); color: var(--amd-accent-fg, #fff); cursor: pointer; font: inherit; font-weight: 650; }
.amd-source-evidence { margin-bottom: 22px; padding: 14px; border: 1px solid var(--amd-border); border-radius: var(--amd-bubble-radius, 12px); background: var(--amd-citation-bg, #eef2ff); color: var(--amd-citation-fg, #312e81); }
.amd-source-evidence h3 { margin: 0 0 9px; font-size: 11px; text-transform: uppercase; letter-spacing: .07em; }
.amd-source-evidence blockquote { margin: 8px 0 0; padding: 8px 10px; border-left: 3px solid var(--amd-accent); background: color-mix(in srgb, var(--amd-bg) 70%, transparent); border-radius: 0 7px 7px 0; font-size: 12.5px; }
.amd-source-evidence-heading { display: block; margin-bottom: 3px; color: var(--amd-muted); font-size: 10.5px; font-weight: 650; }
.amd-source-section + .amd-source-section { margin-top: 24px; }
.amd-source-section-heading { margin: 0 0 10px; color: var(--amd-fg); font-size: 15px; line-height: 1.35; }
.amd-source-markdown { overflow-wrap: anywhere; }
.amd-source-markdown > :first-child { margin-top: 0; } .amd-source-markdown > :last-child { margin-bottom: 0; }
.amd-source-markdown h1, .amd-source-markdown h2, .amd-source-markdown h3, .amd-source-markdown h4 { margin: 1.35em 0 .55em; line-height: 1.3; }
.amd-source-markdown p, .amd-source-markdown ul, .amd-source-markdown ol, .amd-source-markdown blockquote, .amd-source-markdown pre, .amd-source-markdown table { margin: .75em 0; }
.amd-source-markdown code { padding: .12em .35em; border-radius: 5px; background: var(--amd-assistant-bg); font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .9em; }
.amd-source-markdown pre { overflow-x: auto; padding: 12px; border: 1px solid var(--amd-border); border-radius: 9px; background: var(--amd-assistant-bg); }
.amd-source-markdown pre code { padding: 0; background: transparent; }
.amd-source-markdown blockquote { padding-left: 12px; border-left: 3px solid var(--amd-border); color: var(--amd-muted); }
.amd-source-markdown table { width: 100%; border-collapse: collapse; font-size: .93em; }
.amd-source-markdown th, .amd-source-markdown td { padding: 7px 9px; border: 1px solid var(--amd-border); text-align: left; }
.amd-source-markdown a { color: var(--amd-accent); text-underline-offset: 2px; }
.amd-source-image-note { color: var(--amd-muted); font-style: italic; }
@media (max-width: 639px) {
    .amd-source-dialog { width: 100vw; height: 100dvh; max-width: none; max-height: none; margin: 0; border: 0; border-radius: 0; }
    .amd-source-header { padding: 13px 14px; }
    .amd-source-body { grid-template-columns: minmax(0,1fr); grid-template-rows: auto minmax(0,1fr); }
    .amd-source-sidebar { display: none; }
    .amd-source-mobile-picker { display: block; }
    .amd-source-content { padding: 16px; }
}
@media (prefers-reduced-motion: reduce) { .amd-source-skeleton span { animation: none; } }
`;

function fallbackTitle(citation: SourceCitation): string {
    if (typeof citation.title === 'string' && citation.title.trim() !== '') return citation.title;
    const path = typeof citation.source_path === 'string'
        ? citation.source_path.replace(/[\\/]+$/, '')
        : null;
    const basename = path?.split(/[\\/]/).pop();

    return basename || `Documento #${citation.document_id ?? '?'}`;
}

function mergeCitations(citations: SourceCitation[]): ViewerCitation[] {
    const merged = new Map<number, ViewerCitation>();
    for (const citation of citations) {
        if (!Number.isInteger(citation.document_id) || (citation.document_id as number) <= 0) continue;
        const id = citation.document_id as number;
        const origin = typeof citation.origin === 'string' && citation.origin.trim() !== ''
            ? citation.origin
            : null;
        const chunks = Array.isArray(citation.chunks)
            ? citation.chunks.filter(
                (chunk): chunk is SourceCitationChunk => typeof chunk === 'object' && chunk !== null,
            )
            : [];
        const current = merged.get(id);
        if (!current) {
            merged.set(id, {
                ...citation,
                title: typeof citation.title === 'string' ? citation.title : '',
                source_path: typeof citation.source_path === 'string' ? citation.source_path : null,
                document_id: id,
                origins: origin ? [origin] : [],
                chunks: [...chunks],
            });
            continue;
        }
        if (origin && !current.origins.includes(origin)) current.origins.push(origin);
        const known = new Set(current.chunks.map((chunk) => chunk.evidence_hash ?? `${chunk.chunk_id ?? ''}:${chunk.snippet ?? ''}`));
        for (const chunk of chunks) {
            const key = chunk.evidence_hash ?? `${chunk.chunk_id ?? ''}:${chunk.snippet ?? ''}`;
            if (!known.has(key)) {
                current.chunks.push(chunk);
                known.add(key);
            }
        }
    }

    return [...merged.values()];
}

/** Compile untrusted Markdown with raw HTML and unsafe protocols disabled. */
export function renderSafeMarkdown(source: string): DocumentFragment {
    const template = document.createElement('template');
    template.innerHTML = micromark(source, {
        allowDangerousHtml: false,
        allowDangerousProtocol: false,
        extensions: [gfm()],
        htmlExtensions: [gfmHtml()],
    });

    for (const image of [...template.content.querySelectorAll('img')]) {
        const note = document.createElement('span');
        note.className = 'amd-source-image-note';
        const alt = image.getAttribute('alt')?.trim();
        note.textContent = alt ? `[Immagine: ${alt}]` : '[Immagine non caricata]';
        image.replaceWith(note);
    }

    for (const anchor of [...template.content.querySelectorAll('a')]) {
        const href = anchor.getAttribute('href') ?? '';
        let safe = false;
        if (href.trim() !== '') {
            try {
                const url = new URL(href, window.location.href);
                safe = ['http:', 'https:', 'mailto:'].includes(url.protocol);
            } catch {
                safe = false;
            }
        }
        if (!safe) {
            anchor.replaceWith(document.createTextNode(anchor.textContent ?? ''));
            continue;
        }
        anchor.setAttribute('target', '_blank');
        anchor.setAttribute('rel', 'noopener noreferrer');
    }

    return template.content;
}

export class SourceViewer {
    private readonly dialog: HTMLDialogElement;
    private readonly title: HTMLElement;
    private readonly path: HTMLElement;
    private readonly kicker: HTMLElement;
    private readonly sidebar: HTMLElement;
    private readonly select: HTMLSelectElement;
    private readonly content: HTMLElement;
    private citations: ViewerCitation[] = [];
    private readonly cache = new Map<string, SourceDocumentPreview>();
    private requestController: AbortController | null = null;
    private requestVersion = 0;
    private returnFocus: HTMLElement | null = null;

    constructor(
        root: HTMLElement,
        private readonly fetchDocument: SourceDocumentFetcher,
        private readonly cacheNamespace: () => string = () => 'default',
    ) {
        this.dialog = document.createElement('dialog');
        this.dialog.className = 'amd-source-dialog';
        this.dialog.dataset.testid = 'askmydocs-widget-source-viewer';
        this.dialog.setAttribute('aria-labelledby', 'amd-source-viewer-title');

        const shell = document.createElement('div');
        shell.className = 'amd-source-shell';
        const header = document.createElement('header');
        header.className = 'amd-source-header';
        const heading = document.createElement('div');
        this.kicker = document.createElement('div');
        this.kicker.className = 'amd-source-kicker';
        this.title = document.createElement('h2');
        this.title.id = 'amd-source-viewer-title';
        this.title.className = 'amd-source-title';
        this.path = document.createElement('div');
        this.path.className = 'amd-source-path';
        heading.append(this.kicker, this.title, this.path);
        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'amd-source-close';
        close.dataset.testid = 'askmydocs-widget-source-close';
        close.setAttribute('aria-label', 'Chiudi le fonti');
        close.textContent = '×';
        close.addEventListener('click', () => this.close());
        header.append(heading, close);

        const body = document.createElement('div');
        body.className = 'amd-source-body';
        this.sidebar = document.createElement('aside');
        this.sidebar.className = 'amd-source-sidebar';
        this.sidebar.setAttribute('aria-label', 'Documenti citati');
        const mobile = document.createElement('div');
        mobile.className = 'amd-source-mobile-picker';
        this.select = document.createElement('select');
        this.select.className = 'amd-source-select';
        this.select.dataset.testid = 'askmydocs-widget-source-select';
        this.select.setAttribute('aria-label', 'Scegli un documento citato');
        this.select.addEventListener('change', () => this.selectCitation(Number(this.select.value)));
        mobile.append(this.select);
        this.content = document.createElement('main');
        this.content.className = 'amd-source-content';
        this.content.dataset.testid = 'askmydocs-widget-source-content';
        body.append(this.sidebar, mobile, this.content);
        shell.append(header, body);
        this.dialog.append(shell);
        root.append(this.dialog);

        this.dialog.addEventListener('cancel', (event) => {
            event.preventDefault();
            this.close();
        });
        this.dialog.addEventListener('click', (event) => {
            if (event.target === this.dialog) this.close();
        });
    }

    open(citations: SourceCitation[], selectedDocumentId: number | null, trigger: HTMLElement): void {
        this.citations = mergeCitations(citations);
        if (this.citations.length === 0) return;
        this.returnFocus = trigger;
        this.renderNavigation();
        if (!this.dialog.open) {
            if (typeof this.dialog.showModal === 'function') this.dialog.showModal();
            else this.dialog.setAttribute('open', '');
        }
        const selected = this.citations.find((citation) => citation.document_id === selectedDocumentId)
            ?? this.citations.find((citation) => citation.origins.includes('primary'))
            ?? this.citations[0];
        this.selectCitation(selected.document_id);
    }

    close(): void {
        this.requestController?.abort();
        this.requestController = null;
        if (this.dialog.open && typeof this.dialog.close === 'function') this.dialog.close();
        else this.dialog.removeAttribute('open');
        const focus = this.returnFocus;
        this.returnFocus = null;
        focus?.focus();
    }

    private renderNavigation(): void {
        this.sidebar.replaceChildren();
        this.select.replaceChildren();
        const label = document.createElement('div');
        label.className = 'amd-source-sidebar-label';
        label.textContent = `Fonti · ${this.citations.length}`;
        this.sidebar.append(label);

        this.citations.forEach((citation, index) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'amd-source-item';
            button.dataset.testid = `askmydocs-widget-source-item-${index}`;
            button.dataset.documentId = String(citation.document_id);
            button.addEventListener('click', () => this.selectCitation(citation.document_id));
            const number = document.createElement('span');
            number.className = 'amd-source-number';
            number.textContent = String(index + 1);
            const copy = document.createElement('span');
            const title = document.createElement('span');
            title.className = 'amd-source-item-title';
            title.textContent = fallbackTitle(citation);
            const path = document.createElement('span');
            path.className = 'amd-source-item-path';
            path.textContent = citation.source_path ?? citation.source_type ?? 'Documento';
            copy.append(title, path);
            button.append(number, copy);
            this.sidebar.append(button);

            const option = document.createElement('option');
            option.value = String(citation.document_id);
            option.textContent = `${index + 1}. ${fallbackTitle(citation)}`;
            this.select.append(option);
        });
    }

    private selectCitation(documentId: number): void {
        const citation = this.citations.find((item) => item.document_id === documentId);
        if (!citation) return;
        // A cached navigation is still a navigation: abort and invalidate any
        // slower request for the previously selected source before rendering.
        this.requestController?.abort();
        this.requestController = null;
        this.requestVersion++;
        this.title.textContent = fallbackTitle(citation);
        this.path.textContent = citation.source_path ?? '';
        this.path.hidden = !citation.source_path;
        this.kicker.replaceChildren();
        for (const origin of citation.origins.length > 0 ? citation.origins : ['fonte']) {
            const badge = document.createElement('span');
            badge.className = 'amd-source-origin';
            badge.textContent = origin;
            this.kicker.append(badge);
        }
        this.select.value = String(documentId);
        for (const button of this.sidebar.querySelectorAll<HTMLButtonElement>('.amd-source-item')) {
            button.setAttribute('aria-current', button.dataset.documentId === String(documentId) ? 'true' : 'false');
        }

        const cached = this.cache.get(this.cacheKey(documentId));
        if (cached) {
            this.renderDocument(cached, citation);
            return;
        }
        void this.loadDocument(citation);
    }

    private async loadDocument(citation: ViewerCitation): Promise<void> {
        this.requestController?.abort();
        const controller = new AbortController();
        this.requestController = controller;
        const version = ++this.requestVersion;
        const cacheKey = this.cacheKey(citation.document_id);
        this.renderLoading();
        try {
            const document = await this.fetchDocument(citation.document_id, controller.signal);
            if (controller.signal.aborted
                || version !== this.requestVersion
                || cacheKey !== this.cacheKey(citation.document_id)) return;
            this.cache.set(cacheKey, document);
            this.renderDocument(document, citation);
        } catch (error) {
            if (controller.signal.aborted || version !== this.requestVersion) return;
            this.renderError(citation, error);
        }
    }

    private cacheKey(documentId: number): string {
        return `${this.cacheNamespace()}\u0000${documentId}`;
    }

    private renderLoading(): void {
        this.content.dataset.state = 'loading';
        this.content.setAttribute('aria-busy', 'true');
        const state = document.createElement('div');
        state.className = 'amd-source-state';
        state.dataset.testid = 'askmydocs-widget-source-loading';
        state.setAttribute('role', 'status');
        state.setAttribute('aria-live', 'polite');
        const announcement = document.createElement('span');
        announcement.className = 'amd-source-sr-only';
        announcement.textContent = 'Caricamento del documento citato…';
        const skeleton = document.createElement('div');
        skeleton.className = 'amd-source-skeleton';
        skeleton.setAttribute('aria-hidden', 'true');
        skeleton.append(document.createElement('span'), document.createElement('span'), document.createElement('span'));
        state.append(announcement, skeleton);
        this.content.replaceChildren(state);
    }

    private renderError(citation: ViewerCitation, error: unknown): void {
        this.content.dataset.state = 'error';
        this.content.setAttribute('aria-busy', 'false');
        const state = document.createElement('div');
        state.className = 'amd-source-state';
        state.dataset.testid = 'askmydocs-widget-source-error';
        state.setAttribute('role', 'alert');
        const box = document.createElement('div');
        const message = document.createElement('div');
        message.textContent = 'Non è stato possibile caricare questo documento.';
        const retry = document.createElement('button');
        retry.type = 'button';
        retry.className = 'amd-source-retry';
        retry.dataset.testid = 'askmydocs-widget-source-retry';
        retry.textContent = 'Riprova';
        retry.title = error instanceof Error ? error.message : '';
        retry.addEventListener('click', () => void this.loadDocument(citation));
        box.append(message, retry);
        state.append(box);
        this.content.replaceChildren(state);
    }

    private renderDocument(documentPreview: SourceDocumentPreview, citation: ViewerCitation): void {
        const hasContent = documentPreview.sections.some((section) => section.content.trim() !== '');
        this.content.dataset.state = hasContent ? 'ready' : 'empty';
        this.content.setAttribute('aria-busy', 'false');
        this.title.textContent = documentPreview.title?.trim() || fallbackTitle(citation);
        this.path.textContent = documentPreview.source_path ?? citation.source_path ?? '';
        this.path.hidden = this.path.textContent === '';
        const nodes: Node[] = [];

        const evidence = citation.chunks.filter((chunk) => typeof chunk.snippet === 'string' && chunk.snippet.trim() !== '');
        if (evidence.length > 0) {
            const box = document.createElement('section');
            box.className = 'amd-source-evidence';
            box.dataset.testid = 'askmydocs-widget-source-evidence';
            const heading = document.createElement('h3');
            heading.textContent = evidence.length === 1 ? 'Passaggio usato nella risposta' : 'Passaggi usati nella risposta';
            box.append(heading);
            for (const chunk of evidence) {
                const quote = document.createElement('blockquote');
                if (chunk.heading) {
                    const chunkHeading = document.createElement('span');
                    chunkHeading.className = 'amd-source-evidence-heading';
                    chunkHeading.textContent = chunk.heading;
                    quote.append(chunkHeading);
                }
                quote.append(document.createTextNode(chunk.snippet as string));
                box.append(quote);
            }
            nodes.push(box);
        }

        if (!hasContent) {
            const empty = document.createElement('div');
            empty.className = 'amd-source-state';
            empty.dataset.testid = 'askmydocs-widget-source-empty';
            empty.textContent = 'Questo documento non contiene ancora testo indicizzato.';
            nodes.push(empty);
            this.content.replaceChildren(...nodes);
            return;
        }

        documentPreview.sections.forEach((section) => {
            if (section.content.trim() === '') return;
            const wrapper = document.createElement('section');
            wrapper.className = 'amd-source-section';
            if (section.heading_path) {
                const heading = document.createElement('h3');
                heading.className = 'amd-source-section-heading';
                heading.textContent = section.heading_path;
                wrapper.append(heading);
            }
            const markdown = document.createElement('div');
            markdown.className = 'amd-source-markdown';
            markdown.append(renderSafeMarkdown(section.content));
            wrapper.append(markdown);
            nodes.push(wrapper);
        });
        this.content.replaceChildren(...nodes);
    }
}
