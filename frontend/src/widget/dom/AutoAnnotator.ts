/**
 * AutoAnnotator — applica le regole di auto-annotazione definite nel
 * manifest della skill (spec §3). Per ogni regola:
 *   - cerca i nodi che matchano il selettore CSS
 *   - inietta gli attributi mancanti (non sovrascrive quelli esistenti)
 *   - supporta interpolazione `${attrName}` e `${text}`
 *   - regola speciale `data-kitt-action-from-text: "1"` estrae il verb dal textContent
 *     e inietta anche `data-kitt-help` con descrittivo (port da KITT AutoAnnotator.js)
 *
 * Idempotente: ogni nodo marcato con `data-kitt-auto-applied="1"` non viene
 * rielaborato. Reattivo: usato dal MutationObserver (dom/Observer.ts).
 * Tutti i testi passano per sanitizeText (spec §15).
 */
import { sanitizeText } from './sanitize';

export interface AutoAnnotationRule {
    /** Selettore CSS per trovare i nodi da annotare. */
    selector: string;
    /** Attributi da iniettare (solo se mancanti sul nodo). I valori supportano interpolazione. */
    attrs: Record<string, string>;
}

/** Max caratteri per ${text} (spec §3: troncato a 64). */
const MAX_TEXT_FROM_NODE = 64;

export class AutoAnnotator {
    private readonly rules: AutoAnnotationRule[];
    private applied = new WeakSet<Element>();

    constructor(rules: AutoAnnotationRule[]) {
        this.rules = rules;
    }

    /**
     * Applica tutte le regole al root specificato (default: document.body).
     * Idempotente: i nodi già processati vengono saltati.
     */
    apply(root: ParentNode = document.body): number {
        let totalApplied = 0;

        for (const rule of this.rules) {
            try {
                const nodes = root.querySelectorAll(rule.selector);
                for (const node of Array.from(nodes)) {
                    if (this.applied.has(node) || node.hasAttribute('data-kitt-auto-applied')) {
                        continue;
                    }

                    totalApplied += this.applyRule(node, rule);
                    node.setAttribute('data-kitt-auto-applied', '1');
                    this.applied.add(node);
                }
            } catch {
                // Selettore invalido — skip silenzioso
            }
        }

        return totalApplied;
    }

    /**
     * Applica le regole solo ai nodi aggiunti (per il MutationObserver).
     * @param records - Array di MutationRecord dal MutationObserver
     */
    applyToAddedNodes(records: MutationRecord[]): number {
        let totalApplied = 0;

        for (const record of records) {
            for (const node of Array.from(record.addedNodes)) {
                if (!(node instanceof Element)) continue;

                // Il nodo stesso
                if (!this.applied.has(node) && !node.hasAttribute('data-kitt-auto-applied')) {
                    totalApplied += this.applyRulesToElement(node);
                }

                // Discendenti
                for (const child of Array.from(node.querySelectorAll('*'))) {
                    if (!this.applied.has(child) && !child.hasAttribute('data-kitt-auto-applied')) {
                        totalApplied += this.applyRulesToElement(child);
                    }
                }
            }
        }

        return totalApplied;
    }

    /**
     * Applica tutte le regole a un singolo elemento.
     */
    private applyRulesToElement(el: Element): number {
        let count = 0;

        for (const rule of this.rules) {
            try {
                if (el.matches(rule.selector)) {
                    count += this.applyRule(el, rule);
                    el.setAttribute('data-kitt-auto-applied', '1');
                    this.applied.add(el);
                }
            } catch {
                // Selettore invalido — skip
            }
        }

        return count;
    }

    /**
     * Applica una singola regola a un nodo. Ritorna il numero di attributi iniettati.
     * Non sovrascrive attributi già presenti (idempotente).
     * Regola speciale `data-kitt-action-from-text: "1"`: estrae verb dal
     * textContent + inietta data-kitt-help con descrittivo (port KITT).
     */
    private applyRule(node: Element, rule: AutoAnnotationRule): number {
        let injected = 0;

        // Regola speciale: data-kitt-action-from-text (processata per prima)
        if (rule.attrs['data-kitt-action-from-text'] === '1') {
            if (!node.hasAttribute('data-kitt-action')) {
                const rawText = sanitizeText(node.textContent ?? '').slice(0, 48);
                if (rawText) {
                    const verb = this.extractVerb(rawText);
                    if (verb) {
                        node.setAttribute('data-kitt-action', verb);
                        // Auto-deriva anche data-kitt-help se mancante (come KITT originale)
                        if (!node.hasAttribute('data-kitt-help')) {
                            node.setAttribute('data-kitt-help', `Bottone "${rawText}". Verb auto-derivato dal testo.`);
                            injected++;
                        }
                        injected++;
                    }
                }
            }
            node.setAttribute('data-kitt-auto-applied', '1');
            this.applied.add(node);

            return injected;
        }

        for (const [attr, template] of Object.entries(rule.attrs)) {
            // Non sovrascrivere attributi già presenti
            if (node.hasAttribute(attr)) {
                continue;
            }

            const value = this.interpolate(template, node);
            // Non iniettare attributi con valore vuoto (port KITT: skip se empty)
            if (value === '') {
                continue;
            }

            node.setAttribute(attr, value);
            injected++;
        }

        return injected;
    }

    /**
     * Risolve le interpolazioni in un template:
     *   ${text} → sanitizeText(node.textContent) troncato a 64 char (spec §3)
     *   ${attrName} → node.getAttribute(attrName) o stringa vuota
     * Anche i valori interpolati passano per sanitizeText (spec §15).
     */
    private interpolate(template: string, node: Element): string {
        return template.replace(/\$\{([^}]+)\}/g, (_match, key: string) => {
            if (key === 'text') {
                return sanitizeText(node.textContent ?? '').substring(0, MAX_TEXT_FROM_NODE).trim();
            }

            const attrVal = node.getAttribute(key);
            return attrVal !== null ? sanitizeText(attrVal) : '';
        });
    }

    /**
     * Estrae un verb (slug [a-z0-9-]+) dal textContent di un nodo.
     * Es. "Delete Item" → "delete-item", "Salva modifiche" → "salva-modifiche"
     * Supporta caratteri accentati (NFD normalization strip).
     */
    private extractVerb(text: string): string {
        const slug = text
            .toLowerCase()
            .normalize('NFD').replace(/[\u0300-\u036f]/g, '') // strip accents
            .replace(/[^a-z0-9\s-]/g, '')
            .trim()
            .replace(/[\s]+/g, '-')
            .replace(/^-|-$/g, '') // no leading/trailing dash (port KITT)
            .substring(0, 32);

        return slug;
    }
}