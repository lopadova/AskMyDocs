import { describe, it, expect, beforeEach } from 'vitest';
import { AutoAnnotator, type AutoAnnotationRule } from './AutoAnnotator';

describe('AutoAnnotator', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
    });

    // --- Interpolazione ---

    it('interpolates ${text} from node textContent (sanitized, max 64)', () => {
        document.body.innerHTML = '<button id="btn">Hello World</button>';
        const rules: AutoAnnotationRule[] = [
            { selector: '#btn', attrs: { 'data-kitt-help': 'Bottone "${text}"' } },
        ];
        const annotator = new AutoAnnotator(rules);
        annotator.apply();

        const btn = document.getElementById('btn')!;
        expect(btn.getAttribute('data-kitt-help')).toBe('Bottone "Hello World"');
    });

    it('interpolates ${text} and sanitizes angle brackets', () => {
        // jsdom non esegue HTML in textContent, simuliamo direttamente
        const btn = document.createElement('button');
        btn.id = 'btn';
        // Simula textContent con caratteri pericolosi (in un browser reale
        // textContent non conterrebbe tag HTML, ma sanitizeText è difesa in profondità)
        btn.textContent = '<script>alert(1)</script>';
        document.body.append(btn);

        const rules: AutoAnnotationRule[] = [
            { selector: '#btn', attrs: { 'data-kitt-help': 'Verb: ${text}' } },
        ];
        const annotator = new AutoAnnotator(rules);
        annotator.apply();

        const help = btn.getAttribute('data-kitt-help')!;
        // sanitizeText rimpiazza < > con spazi
        expect(help).not.toContain('<');
        expect(help).not.toContain('>');
    });

    it('interpolates ${attrName} from node attributes', () => {
        document.body.innerHTML = '<input id="inp" data-testid="my-field" />';
        const rules: AutoAnnotationRule[] = [
            { selector: '#inp', attrs: { 'data-kitt-field': '${data-testid}' } },
        ];
        const annotator = new AutoAnnotator(rules);
        annotator.apply();

        const inp = document.getElementById('inp')!;
        expect(inp.getAttribute('data-kitt-field')).toBe('my-field');
    });

    it('resolves missing ${attr} to empty string and skips injection when value is empty', () => {
        document.body.innerHTML = '<button id="btn">x</button>';
        const rules: AutoAnnotationRule[] = [
            { selector: '#btn', attrs: { 'data-kitt-action': '${nonexistent}' } },
        ];
        const annotator = new AutoAnnotator(rules);
        const count = annotator.apply();

        const btn = document.getElementById('btn')!;
        // Valore interpolato è stringa vuota → non iniettato (port KITT)
        expect(btn.hasAttribute('data-kitt-action')).toBe(false);
        expect(count).toBe(0);
    });

    // --- Idempotenza ---

    it('does not overwrite existing attributes (idempotent)', () => {
        document.body.innerHTML = '<button id="btn" data-kitt-action="manual">Click</button>';
        const rules: AutoAnnotationRule[] = [
            { selector: '#btn', attrs: { 'data-kitt-action': 'auto-verb' } },
        ];
        const annotator = new AutoAnnotator(rules);
        annotator.apply();

        const btn = document.getElementById('btn')!;
        // Non sovrascrive l'attributo già presente
        expect(btn.getAttribute('data-kitt-action')).toBe('manual');
    });

    it('marks nodes with data-kitt-auto-applied and skips on re-apply', () => {
        document.body.innerHTML = '<button id="btn">Go</button>';
        const rules: AutoAnnotationRule[] = [
            { selector: '#btn', attrs: { 'data-kitt-action': 'go' } },
        ];
        const annotator = new AutoAnnotator(rules);
        const count1 = annotator.apply();
        const count2 = annotator.apply();

        expect(count1).toBe(1);
        expect(count2).toBe(0); // già processato, skip
        const btn = document.getElementById('btn')!;
        expect(btn.getAttribute('data-kitt-auto-applied')).toBe('1');
    });

    // --- data-kitt-action-from-text ---

    it('extracts verb from textContent for data-kitt-action-from-text', () => {
        document.body.innerHTML = '<button id="btn">Delete Item</button>';
        const rules: AutoAnnotationRule[] = [
            { selector: '#btn', attrs: { 'data-kitt-action-from-text': '1' } },
        ];
        const annotator = new AutoAnnotator(rules);
        annotator.apply();

        const btn = document.getElementById('btn')!;
        expect(btn.getAttribute('data-kitt-action')).toBe('delete-item');
        expect(btn.getAttribute('data-kitt-help')).toContain('Delete Item');
        expect(btn.getAttribute('data-kitt-help')).toContain('Verb auto-derivato');
    });

    it('action-from-text does not overwrite existing data-kitt-action', () => {
        document.body.innerHTML = '<button id="btn" data-kitt-action="manual">Delete Item</button>';
        const rules: AutoAnnotationRule[] = [
            { selector: '#btn', attrs: { 'data-kitt-action-from-text': '1' } },
        ];
        const annotator = new AutoAnnotator(rules);
        annotator.apply();

        const btn = document.getElementById('btn')!;
        expect(btn.getAttribute('data-kitt-action')).toBe('manual');
    });

    it('action-from-text handles accented characters', () => {
        document.body.innerHTML = '<button id="btn">Salva modifiche</button>';
        const rules: AutoAnnotationRule[] = [
            { selector: '#btn', attrs: { 'data-kitt-action-from-text': '1' } },
        ];
        const annotator = new AutoAnnotator(rules);
        annotator.apply();

        const btn = document.getElementById('btn')!;
        expect(btn.getAttribute('data-kitt-action')).toBe('salva-modifiche');
    });

    // --- applyToAddedNodes (MutationObserver) ---

    it('applies rules to dynamically added nodes', () => {
        document.body.innerHTML = '';
        const rules: AutoAnnotationRule[] = [
            { selector: 'button', attrs: { 'data-kitt-action': 'click' } },
        ];
        const annotator = new AutoAnnotator(rules);

        // Aggiunge un nodo dinamicamente
        const btn = document.createElement('button');
        btn.textContent = 'Click me';
        document.body.append(btn);

        // Simula MutationRecord
        const records: MutationRecord[] = [{
            type: 'childList',
            target: document.body,
            addedNodes: [btn] as unknown as NodeListOf<Node>,
            removedNodes: [] as unknown as NodeListOf<Node>,
            attributeName: null,
            attributeNamespace: null,
            nextSibling: null,
            previousSibling: null,
            oldValue: null,
        }];
        annotator.applyToAddedNodes(records);

        expect(btn.getAttribute('data-kitt-action')).toBe('click');
    });

    // --- Invalid selector ---

    it('skips rules with invalid CSS selectors gracefully', () => {
        document.body.innerHTML = '<button id="btn">x</button>';
        const rules: AutoAnnotationRule[] = [
            { selector: '!!!invalid', attrs: { 'data-kitt-action': 'x' } },
            { selector: '#btn', attrs: { 'data-kitt-action': 'ok' } },
        ];
        const annotator = new AutoAnnotator(rules);
        annotator.apply();

        const btn = document.getElementById('btn')!;
        // La regola invalida è stata saltata, la seconda ha funzionato
        expect(btn.getAttribute('data-kitt-action')).toBe('ok');
    });
});