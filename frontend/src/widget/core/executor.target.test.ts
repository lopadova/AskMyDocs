/**
 * Risoluzione del target per tour_step / move_cursor (Executor.resolveTarget →
 * findActionTarget). Verifica che i match ESATTI restino prioritari (nessuna
 * regressione) e che i tre nuovi fallback tolleranti aggancino l'elemento
 * quando il modello passa la label visibile o l'id di una region invece del
 * verb esatto di `data-kitt-action`.
 *
 * Contesto: dal vivo l'overlay (backdrop+tooltip) si disegnava ma la freccia/
 * spotlight non si ancorava, perché `highlight_target` arrivava come id di
 * region (es. "actions") invece del verb azione ("analyze") o della label
 * ("Analizza"), e findActionTarget — match SOLO esatti — ritornava null.
 *
 * jsdom non calcola il layout: un elemento montato è considerato visibile
 * salvo display:none / visibility:hidden espliciti (vedi Executor.isVisible).
 */
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { Executor } from './executor';

afterEach(() => {
    document.body.innerHTML = '';
});

describe('Executor.resolveTarget — match esatti (prioritari, no regressione)', () => {
    let executor: Executor;

    beforeEach(() => {
        executor = new Executor();
    });

    it('match esatto su data-kitt-action ha la precedenza sui fallback', () => {
        document.body.innerHTML = `
            <main>
                <button data-kitt-action="analyze" id="exact">Analizza ora</button>
                <button data-kitt-action="ANALYZE" id="fuzzy">Altro</button>
            </main>
        `;
        // "analyze" matcha esattamente #exact; il case-insensitive (#fuzzy) NON deve vincere.
        const el = executor.resolveTarget('analyze');
        expect(el?.id).toBe('exact');
    });

    it('match esatto per id', () => {
        document.body.innerHTML = `<div id="save-region">x</div>`;
        expect(executor.resolveTarget('save-region')?.id).toBe('save-region');
    });

    it('match esatto per data-testid', () => {
        document.body.innerHTML = `<button data-testid="submit-btn">Invia</button>`;
        expect(executor.resolveTarget('submit-btn')?.getAttribute('data-testid')).toBe('submit-btn');
    });

    it('match esatto per testo ESATTO del bottone (no substring)', () => {
        document.body.innerHTML = `
            <button id="b1">Analizza il documento</button>
            <button id="b2">Analizza</button>
        `;
        // Testo esatto "Analizza" → #b2, NON #b1 (che lo contiene).
        expect(executor.resolveTarget('Analizza')?.id).toBe('b2');
    });
});

describe('Executor.resolveTarget — fallback tolleranti', () => {
    let executor: Executor;

    beforeEach(() => {
        executor = new Executor();
    });

    // Fallback a — data-kitt-action case-insensitive.
    it('a. aggancia data-kitt-action con confronto case-insensitive', () => {
        document.body.innerHTML = `<button data-kitt-action="analyze" id="target">Analizza</button>`;
        expect(executor.resolveTarget('Analyze')?.id).toBe('target');
        expect(executor.resolveTarget('ANALYZE')?.id).toBe('target');
    });

    // Fallback b — testo del bottone che CONTIENE il target (substring, case-insensitive).
    it('b. aggancia un bottone il cui testo CONTIENE il target (label parziale)', () => {
        document.body.innerHTML = `
            <main>
                <button id="run">Analizza il documento</button>
            </main>
        `;
        // Nessun data-kitt-action / id / testo esatto; "Analizza" è substring del testo.
        expect(executor.resolveTarget('Analizza')?.id).toBe('run');
        // Case-insensitive.
        expect(executor.resolveTarget('analizza')?.id).toBe('run');
    });

    it('b. aggancia anche a.btn / [role=button] per substring', () => {
        document.body.innerHTML = `
            <a class="ui-btn" id="link-btn" href="#">Scarica il report PDF</a>
            <div role="button" id="role-btn">Esporta tutto</div>
        `;
        expect(executor.resolveTarget('report')?.id).toBe('link-btn');
        expect(executor.resolveTarget('Esporta')?.id).toBe('role-btn');
    });

    // Fallback c — region → azione primaria.
    it('c. risolve una region all\'azione primaria (primo data-kitt-action visibile dentro)', () => {
        document.body.innerHTML = `
            <section data-kitt-region="actions">
                <span>intestazione</span>
                <button data-kitt-action="analyze" id="primary">Analizza</button>
                <button data-kitt-action="reset" id="secondary">Reset</button>
            </section>
        `;
        // "actions" è una region: deve risolvere all'azione primaria, NON null.
        expect(executor.resolveTarget('actions')?.id).toBe('primary');
    });

    it('c. region senza data-kitt-action risolve al primo bottone visibile', () => {
        document.body.innerHTML = `
            <section data-kitt-region="toolbar">
                <button id="first">Apri</button>
                <button id="second">Chiudi</button>
            </section>
        `;
        expect(executor.resolveTarget('toolbar')?.id).toBe('first');
    });

    it('ritorna null quando nessun match (esatto o fuzzy) esiste', () => {
        document.body.innerHTML = `<button id="x">Qualcosa</button>`;
        expect(executor.resolveTarget('inesistente-xyz')).toBeNull();
    });

    it('ignora gli elementi nascosti via display:none nei fallback', () => {
        document.body.innerHTML = `
            <button data-kitt-action="ANALYZE" id="hidden" style="display:none">nascosto</button>
            <button data-kitt-action="ANALYZE" id="visible">Analizza</button>
        `;
        // Due match case-insensitive su data-kitt-action: il primo è display:none
        // → deve essere saltato e va agganciato il secondo, visibile.
        expect(executor.resolveTarget('analyze')?.id).toBe('visible');
    });
});
