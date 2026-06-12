/**
 * OverlaySystem — il feedback visivo agentico del widget (M4.8). Disegna sopra
 * la PAGINA OSPITE (non lo shadow root della UI) tre primitive:
 *
 *   - `pointAt(target)`   freccia/cursore agentico (SVG) che indica un elemento,
 *                          senza oscurare la pagina. Usato da `move_cursor`.
 *   - `tourStep(...)`     backdrop scuro + spotlight (ritaglio luminoso) sul
 *                          target + freccia + tooltip con messaggio e "i/N".
 *                          Usato da `tour_step`.
 *   - `clear()`           rimuove tutto e stacca i listener.
 *
 * Montaggio: gli elementi vivono in light DOM, appesi a `<body>`, marcati con
 * `data-askmydocs-widget` così lo SnapshotBuilder li ignora (non finiscono nel
 * page_outline) e l'executor DOM non li scambia per target cliccabili.
 * `position: fixed` rispetto alla viewport; z-index alto ma SOTTO il
 * launcher/pannello del widget (2147483000) — usiamo 2147482000 così il
 * pannello chat resta sopra e cliccabile.
 *
 * Backdrop+spotlight: approccio "box-shadow inset gigante". Un singolo div
 * (`.amd-spotlight`) posizionato sul rect del target porta una box-shadow con
 * spread enorme (`0 0 0 9999px rgba(...)`) che oscura TUTTO ciò che sta fuori
 * dal suo rettangolo, lasciando il target nitido — il "buco" è il div stesso.
 * Più robusto di una SVG mask: niente quirk di `<mask>`/`clip-path` tra browser,
 * scala in modo lineare al resize, e il box dello spotlight porta anche l'anello
 * di evidenziazione. Un backdrop trasparente separato (`.amd-backdrop`)
 * intercetta i click sulla pagina dimmata durante il tour, MA non sposta il
 * focus e non lo intrappola: il pannello del widget (z-index superiore) resta
 * pienamente interattivo (a11y).
 */
import type { Executor } from '../core/executor';
import { OVERLAY_CSS } from './styles';

/** Margine attorno al target per lo spotlight (px). */
const SPOTLIGHT_PADDING = 8;

/** Cursore/freccia agentica: SVG pointer 28×28 (currentColor + fill). */
const CURSOR_SVG = `<svg viewBox="0 0 24 24" width="28" height="28" aria-hidden="true" focusable="false"><path d="M5 2.5l13.5 8.2-5.7 1.2 3.3 6.4-2.7 1.4-3.3-6.4-4.4 3.9z" fill="#ffffff" stroke="#1e293b" stroke-width="1.3" stroke-linejoin="round"/></svg>`;

export class OverlaySystem {
    private host: HTMLElement | null = null;
    private backdrop: HTMLElement | null = null;
    private spotlight: HTMLElement | null = null;
    private cursor: HTMLElement | null = null;
    private tooltip: HTMLElement | null = null;

    /** Target corrente da ri-misurare su scroll/resize. */
    private currentTarget: HTMLElement | null = null;
    /** Modo corrente: 'tour' (con backdrop) o 'point' (solo freccia). */
    private mode: 'tour' | 'point' | null = null;

    private readonly onReflow = (): void => this.reposition();
    private listenersAttached = false;

    /**
     * @param doc   document su cui montare (default `document`). Iniettabile per i test.
     * @param zBase z-index base degli elementi overlay (sotto il widget).
     */
    constructor(
        private readonly doc: Document = document,
        private readonly zBase: number = 2147482000,
    ) {}

    /**
     * move_cursor — mostra la freccia agentica sul target, senza backdrop.
     * Rimuove un eventuale tour attivo (passaggio point ⇄ tour).
     */
    pointAt(target: HTMLElement | null): void {
        if (!target) {
            this.clear();

            return;
        }
        this.mode = 'point';
        this.currentTarget = target;
        this.ensureHost();
        this.removeBackdrop();
        this.removeSpotlight();
        this.removeTooltip();
        this.ensureCursor();
        this.attachListeners();
        this.scrollIntoView(target);
        this.reposition();
    }

    /**
     * tour_step — backdrop scuro + spotlight sul target + freccia + tooltip con
     * messaggio e "index+1/total". Si aggiorna in-place se chiamato di nuovo.
     */
    tourStep(target: HTMLElement | null, message: string, index: number, total: number): void {
        this.mode = 'tour';
        this.currentTarget = target;
        this.ensureHost();
        this.ensureBackdrop();
        this.ensureSpotlight();
        this.ensureCursor();
        this.ensureTooltip();
        this.renderTooltip(message, index, total);
        this.attachListeners();
        if (target) {
            this.scrollIntoView(target);
        }
        this.reposition();
    }

    /** Rimuove ogni elemento overlay e stacca i listener. Idempotente. */
    clear(): void {
        this.detachListeners();
        this.removeBackdrop();
        this.removeSpotlight();
        this.removeCursor();
        this.removeTooltip();
        if (this.host) {
            this.host.remove();
            this.host = null;
        }
        this.currentTarget = null;
        this.mode = null;
    }

    // ── host & elementi ──────────────────────────────────────────────────────

    private ensureHost(): void {
        if (this.host) {
            return;
        }
        const host = this.doc.createElement('div');
        host.className = 'amd-overlay';
        // data-askmydocs-widget: lo SnapshotBuilder e l'executor DOM lo ignorano,
        // così l'overlay non finisce nel page_outline né viene risolto come target.
        host.setAttribute('data-askmydocs-widget', '');
        host.setAttribute('aria-hidden', 'true');
        host.style.position = 'fixed';
        host.style.inset = '0';
        // pointer-events:none sul wrapper: i figli che DEVONO catturare i click
        // (backdrop) lo riabilitano singolarmente. Così la freccia/tooltip non
        // rubano mai i click alla pagina.
        host.style.pointerEvents = 'none';
        host.style.zIndex = String(this.zBase);
        this.appendStyles();
        this.doc.body.appendChild(host);
        this.host = host;
    }

    /** Inietta una volta gli stili dell'overlay nel <head> della pagina ospite. */
    private appendStyles(): void {
        if (this.doc.getElementById('amd-overlay-styles')) {
            return;
        }
        const style = this.doc.createElement('style');
        style.id = 'amd-overlay-styles';
        style.textContent = OVERLAY_CSS;
        (this.doc.head ?? this.doc.body).appendChild(style);
    }

    private ensureBackdrop(): void {
        if (this.backdrop || !this.host) {
            return;
        }
        const el = this.doc.createElement('div');
        el.className = 'amd-backdrop';
        // Intercetta i click sulla pagina dimmata durante il tour. NON sposta il
        // focus: nessun tabindex, nessun focus() forzato → non intrappola il
        // widget (a11y). Il pannello del widget sta sopra (z-index maggiore).
        el.style.pointerEvents = 'auto';
        this.host.appendChild(el);
        this.backdrop = el;
    }

    private ensureSpotlight(): void {
        if (this.spotlight || !this.host) {
            return;
        }
        const el = this.doc.createElement('div');
        el.className = 'amd-spotlight';
        this.host.appendChild(el);
        this.spotlight = el;
    }

    private ensureCursor(): void {
        if (this.cursor || !this.host) {
            return;
        }
        const el = this.doc.createElement('div');
        el.className = 'amd-cursor';
        // SVG = costante fidata (mai input utente), come gli SVG del launcher.
        el.innerHTML = CURSOR_SVG;
        this.host.appendChild(el);
        this.cursor = el;
    }

    private ensureTooltip(): void {
        if (this.tooltip || !this.host) {
            return;
        }
        const el = this.doc.createElement('div');
        el.className = 'amd-tooltip';
        el.setAttribute('role', 'status');
        // aria-live: il messaggio del tour è annunciato dagli screen reader.
        el.setAttribute('aria-live', 'polite');
        el.setAttribute('data-testid', 'askmydocs-overlay-tooltip');
        this.host.appendChild(el);
        this.tooltip = el;
    }

    private renderTooltip(message: string, index: number, total: number): void {
        if (!this.tooltip) {
            return;
        }
        this.tooltip.replaceChildren();

        const step = this.doc.createElement('div');
        step.className = 'amd-tooltip-step';
        step.setAttribute('data-testid', 'askmydocs-overlay-step');
        // index è 0-based dal contratto del tool → mostra index+1 / total.
        step.textContent = `${index + 1}/${Math.max(total, index + 1)}`;

        const body = this.doc.createElement('div');
        body.className = 'amd-tooltip-body';
        body.textContent = message;

        this.tooltip.append(step, body);
    }

    // ── posizionamento ───────────────────────────────────────────────────────

    /**
     * Riposiziona spotlight, cursore e tooltip in base al rect del target. Su
     * scroll/resize è ricalcolato (i rect fixed seguono la viewport). Se il
     * target è assente (tour senza highlight risolto), il backdrop resta scuro
     * pieno e cursore/spotlight si nascondono.
     */
    private reposition(): void {
        const target = this.currentTarget;
        const rect = target ? target.getBoundingClientRect() : null;

        if (!rect || (rect.width === 0 && rect.height === 0)) {
            // Nessun target misurabile: spotlight/cursore nascosti. In tour il
            // backdrop resta a schermo intero; il tooltip va al centro.
            this.hideSpotlight();
            this.hideCursor();
            if (this.mode === 'tour') {
                this.centerTooltip();
            }

            return;
        }

        const top = rect.top - SPOTLIGHT_PADDING;
        const left = rect.left - SPOTLIGHT_PADDING;
        const width = rect.width + SPOTLIGHT_PADDING * 2;
        const height = rect.height + SPOTLIGHT_PADDING * 2;

        if (this.spotlight) {
            this.spotlight.style.display = 'block';
            this.spotlight.style.top = `${top}px`;
            this.spotlight.style.left = `${left}px`;
            this.spotlight.style.width = `${width}px`;
            this.spotlight.style.height = `${height}px`;
        }

        this.positionCursor(rect);
        if (this.mode === 'tour') {
            this.positionTooltip(rect, top, height);
        }
    }

    /**
     * Posiziona la freccia agentica all'angolo del target che punta verso di
     * esso. Default: in alto a sinistra del target, ruotata per "indicare"
     * l'elemento. Se non c'è spazio sopra, la mette sotto.
     */
    private positionCursor(rect: DOMRect): void {
        if (!this.cursor) {
            return;
        }
        this.cursor.style.display = 'block';
        const viewportH = this.viewportHeight();
        const above = rect.top > 48;
        // Punta verso il centro-bordo del target. La freccia di base punta in
        // alto-sinistra (la punta è l'angolo top-left dell'SVG).
        const x = rect.left + rect.width / 2;
        const y = above ? rect.top : rect.bottom;
        this.cursor.style.left = `${x}px`;
        this.cursor.style.top = `${Math.min(Math.max(y, 0), viewportH)}px`;
        // Sopra: la freccia scende verso il target (default). Sotto: la capovolge.
        this.cursor.style.transform = above
            ? 'translate(-50%, -100%) rotate(0deg)'
            : 'translate(-50%, 0) rotate(180deg)';
    }

    /**
     * Posiziona il tooltip vicino al target: preferenzialmente sotto, altrimenti
     * sopra se sotto non c'è spazio. Clampato nella viewport.
     */
    private positionTooltip(rect: DOMRect, spotTop: number, spotH: number): void {
        if (!this.tooltip) {
            return;
        }
        this.tooltip.style.display = 'block';
        const viewportH = this.viewportHeight();
        const viewportW = this.viewportWidth();
        const ttRect = this.tooltip.getBoundingClientRect();
        const ttW = ttRect.width || 280;
        const ttH = ttRect.height || 80;
        const gap = 14;

        const belowTop = spotTop + spotH + gap;
        const fitsBelow = belowTop + ttH <= viewportH;
        const top = fitsBelow ? belowTop : Math.max(spotTop - ttH - gap, 8);

        let left = rect.left + rect.width / 2 - ttW / 2;
        left = Math.min(Math.max(left, 8), Math.max(viewportW - ttW - 8, 8));

        this.tooltip.style.top = `${top}px`;
        this.tooltip.style.left = `${left}px`;
    }

    /** Tooltip al centro della viewport (fallback senza target). */
    private centerTooltip(): void {
        if (!this.tooltip) {
            return;
        }
        this.tooltip.style.display = 'block';
        this.tooltip.style.top = '50%';
        this.tooltip.style.left = '50%';
        this.tooltip.style.transform = 'translate(-50%, -50%)';
    }

    private scrollIntoView(target: HTMLElement): void {
        if (typeof target.scrollIntoView === 'function') {
            target.scrollIntoView({ block: 'center', behavior: 'smooth' });
        }
    }

    private viewportHeight(): number {
        return this.doc.defaultView?.innerHeight ?? this.doc.documentElement.clientHeight ?? 768;
    }

    private viewportWidth(): number {
        return this.doc.defaultView?.innerWidth ?? this.doc.documentElement.clientWidth ?? 1024;
    }

    // ── listener (scroll/resize) ───────────────────────────────────────────────

    private attachListeners(): void {
        if (this.listenersAttached) {
            return;
        }
        const view = this.doc.defaultView;
        if (!view) {
            return;
        }
        // capture:true intercetta lo scroll su qualunque contenitore (non solo window).
        view.addEventListener('scroll', this.onReflow, true);
        view.addEventListener('resize', this.onReflow);
        this.listenersAttached = true;
    }

    private detachListeners(): void {
        if (!this.listenersAttached) {
            return;
        }
        const view = this.doc.defaultView;
        if (view) {
            view.removeEventListener('scroll', this.onReflow, true);
            view.removeEventListener('resize', this.onReflow);
        }
        this.listenersAttached = false;
    }

    // ── rimozione singoli elementi ─────────────────────────────────────────────

    private hideSpotlight(): void {
        if (this.spotlight) {
            this.spotlight.style.display = 'none';
        }
    }

    private hideCursor(): void {
        if (this.cursor) {
            this.cursor.style.display = 'none';
        }
    }

    private removeBackdrop(): void {
        this.backdrop?.remove();
        this.backdrop = null;
    }

    private removeSpotlight(): void {
        this.spotlight?.remove();
        this.spotlight = null;
    }

    private removeCursor(): void {
        this.cursor?.remove();
        this.cursor = null;
    }

    private removeTooltip(): void {
        this.tooltip?.remove();
        this.tooltip = null;
    }
}

/**
 * Risolve il target di un tool visivo riusando la logica DOM dell'executor.
 * L'overlay non conosce i selettori `data-kitt-*`: delega all'executor (che ha
 * `resolveTarget` pubblico) così la risoluzione resta in un solo posto.
 */
export function resolveOverlayTarget(executor: Executor, name: string): HTMLElement | null {
    return executor.resolveTarget(name);
}
