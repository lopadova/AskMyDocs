/**
 * Observer — MutationObserver + event listener per invalidazione snapshot
 * (port KITT Observer.js, spec §13).
 *
 * Due responsabilità:
 *
 * 1. **MutationObserver**: ascolta aggiunte/rimozioni nel DOM e delega
 *    all'AutoAnnotator per iniettare data-kitt-* sugli elementi nuovi.
 *    Debounce di 200ms per evitare storm di mutazioni (spec §3: AutoAnnotator
 *    è reattivo con debounce 200ms; Observer snapshot-invalidation è 150ms).
 *
 * 2. **Snapshot invalidation** (spec §13): certi eventi DOM invalidano la
 *    cache snapshot. Il Bridge, prima di chiamare /step, chiama buildSnapshot()
 *    che ritorna sempre un fresh snapshot — ma l'Observer notifica la UI che
 *    lo snapshot è stale per mostrare un indicatore "aggiornamento".
 *    MutationObserver + listener (focusin/input/change/scroll) debounce 150ms.
 *
 * Uso:
 *   const observer = new Observer(autoAnnotator, { onStale: () => { ... } });
 *   observer.start();   // avvia il MutationObserver + listener
 *   observer.stop();    // disconnette tutto
 */
import { AutoAnnotator } from './AutoAnnotator';

export interface ObserverCallbacks {
    /** Chiamato quando lo snapshot è diventato stale a causa di un evento DOM. */
    onStale?: () => void;
}

/**
 * Attributi osservati dal MutationObserver per snapshot invalidation (spec §13).
 * Ogni cambio su questi attributi indica che la "vista" del modello è cambiata.
 */
const OBSERVED_ATTRIBUTES: string[] = [
    'data-kitt-active',
    'data-kitt-completed',
    'data-kitt-locale',
    'disabled',
    'aria-disabled',
    'hidden',
    'value',
];

/** Debounce per snapshot invalidation (spec §13: 150ms). */
const STALE_DEBOUNCE_MS = 150;

/** Debounce per re-applicazione auto-annotazione (spec §3: 200ms). */
const ANNOTATE_DEBOUNCE_MS = 200;

export class Observer {
    private readonly autoAnnotator: AutoAnnotator;
    private readonly callbacks: ObserverCallbacks;
    private mutationObserver: MutationObserver | null = null;
    private stale = false;
    private annotateTimer: ReturnType<typeof setTimeout> | null = null;
    private staleTimer: ReturnType<typeof setTimeout> | null = null;
    private boundHandlers: Map<string, EventListenerOrEventListenerObject> = new Map();

    constructor(autoAnnotator: AutoAnnotator, callbacks: ObserverCallbacks = {}) {
        this.autoAnnotator = autoAnnotator;
        this.callbacks = callbacks;
    }

    /**
     * Avvia il MutationObserver su document.body e registra i listener
     * per gli eventi che invalidano lo snapshot (spec §13).
     */
    start(): void {
        // Applica le regole all'init
        this.autoAnnotator.apply();

        // MutationObserver: traccia childList + gli attributi della spec §13
        this.mutationObserver = new MutationObserver((records) => {
            this.onMutations(records);
        });

        this.mutationObserver.observe(document.body, {
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: OBSERVED_ATTRIBUTES,
            characterData: true,
        });

        // Listener per snapshot invalidation (spec §13: focusin, input, change su document; scroll su window)
        const staleEvents: Array<{ type: string; target: EventTarget; passive?: boolean }> = [
            { type: 'focusin', target: document },
            { type: 'input', target: document },
            { type: 'change', target: document },
            { type: 'scroll', target: window, passive: true },
        ];

        for (const { type, target, passive } of staleEvents) {
            const handler = (): void => { this.markStale(); };
            this.boundHandlers.set(`${type}@${target === window ? 'window' : 'document'}`, handler);
            target.addEventListener(type, handler, { capture: true, passive });
        }
    }

    /**
     * Disconnette il MutationObserver e rimuove tutti i listener.
     */
    stop(): void {
        if (this.mutationObserver) {
            this.mutationObserver.disconnect();
            this.mutationObserver = null;
        }

        for (const [key, handler] of this.boundHandlers) {
            const [type, scope] = key.split('@');
            const target = scope === 'window' ? window : document;
            target.removeEventListener(type, handler, true);
        }
        this.boundHandlers.clear();

        if (this.annotateTimer !== null) {
            clearTimeout(this.annotateTimer);
            this.annotateTimer = null;
        }

        if (this.staleTimer !== null) {
            clearTimeout(this.staleTimer);
            this.staleTimer = null;
        }
    }

    /**
     * True se lo snapshot è stato invalidato dopo l'ultimo invio.
     */
    isStale(): boolean {
        return this.stale;
    }

    /**
     * Resetta il flag stale (chiamato dopo aver costruito uno snapshot fresco).
     */
    resetStale(): void {
        this.stale = false;
    }

    // --- Internal ---

    private onMutations(records: MutationRecord[]): void {
        // 1) AutoAnnotator: re-applica le regole sui nodi aggiunti (debounce 200ms, spec §3)
        if (this.annotateTimer !== null) {
            clearTimeout(this.annotateTimer);
        }
        const capturedRecords = records;
        this.annotateTimer = setTimeout(() => {
            this.autoAnnotator.applyToAddedNodes(capturedRecords);
            this.annotateTimer = null;
        }, ANNOTATE_DEBOUNCE_MS);

        // 2) Snapshot stale: qualsiasi mutazione invalida (debounce 150ms, spec §13)
        this.debouncedMarkStale();
    }

    private debouncedMarkStale(): void {
        if (this.staleTimer !== null) {
            clearTimeout(this.staleTimer);
        }
        this.staleTimer = setTimeout(() => {
            this.markStale();
            this.staleTimer = null;
        }, STALE_DEBOUNCE_MS);
    }

    private markStale(): void {
        this.stale = true;
        this.callbacks.onStale?.();
    }
}