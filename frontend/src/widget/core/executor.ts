/**
 * Executor — esegue le tool_call DOM sulla pagina ospite (port di KITT
 * core/Executor.js + tool runners, spec §5.1). Agisce solo sul documento
 * ospite: lo shadow DOM (closed) del widget è invisibile a querySelector,
 * quindi non c'è rischio di auto-interazione.
 *
 * I tool conversazionali (ask_user/report_done/report_blocked) e i tool BE
 * NON passano di qui: li gestisce il Bridge.
 */
import type { ToolResult } from '../types';

function ok(tool: string, diagnostic: Record<string, unknown>): ToolResult {
    return { ok: true, tool, diagnostic };
}

function fail(tool: string, message: string): ToolResult {
    return { ok: false, tool, diagnostic: { error: message }, error_message: message };
}

export class Executor {
    async run(tool: string, args: Record<string, unknown>): Promise<ToolResult> {
        try {
            switch (tool) {
                case 'click':
                    return this.click(String(args.target ?? ''));
                case 'type':
                    return this.type(String(args.field ?? ''), String(args.value ?? ''), Boolean(args.append));
                case 'select':
                    return this.select(String(args.field ?? ''), args.value);
                case 'scroll_to':
                    return this.scrollTo(String(args.target ?? ''));
                case 'navigate_to':
                    return this.navigate(String(args.url ?? ''));
                case 'submit_form':
                    return this.submit();
                case 'read_page':
                    return ok('read_page', { actual: 'snapshot refreshed' });

                // --- M4: tool DOM aggiuntivi (spec §5.1) ---

                case 'combobox_search':
                    return this.comboboxSearch(String(args.field ?? ''), String(args.query ?? ''));
                case 'combobox_set':
                    return this.comboboxSet(String(args.field ?? ''), String(args.value ?? ''), args.query != null ? String(args.query) : undefined);
                case 'toggle':
                    return this.toggle(String(args.field ?? ''), args.on as boolean | undefined);
                case 'radio':
                    return this.radio(String(args.field ?? ''), String(args.value ?? ''));
                case 'set_locale':
                    return this.setLocale(String(args.locale ?? ''));
                case 'goto_step':
                    return this.gotoStep(String(args.step ?? ''), String(args.mode ?? 'jump'));
                case 'wait_for':
                    return this.waitFor(String(args.condition ?? ''), Number(args.timeout_ms ?? 5000));
                case 'tour_step':
                    return this.tourStep(args);
                case 'move_cursor':
                    return this.moveCursor(String(args.target ?? ''));
                case 'show_recap':
                    return this.showRecap(args);

                default:
                    return fail(tool, `Tool ${tool} is not executable by the widget.`);
            }
        } catch (error) {
            return fail(tool, error instanceof Error ? error.message : String(error));
        }
    }

    /**
     * Risolve l'elemento DOM ospite per nome (verb di action / id / testid /
     * testo), riusando la stessa logica dei runner. Pubblico perché l'overlay
     * agentico (tour_step / move_cursor) deve trovare lo stesso target che i
     * tool risolvono, senza duplicare i selettori `data-kitt-*`.
     */
    resolveTarget(target: string): HTMLElement | null {
        return this.findActionTarget(target);
    }

    private findActionTarget(target: string): HTMLElement | null {
        // --- Match esatti (prioritari, invariati): nessuna regressione. ---
        const annotated = document.querySelector(`[data-kitt-action="${CSS.escape(target)}"]`);
        if (annotated instanceof HTMLElement) {
            return annotated;
        }
        const byId = document.getElementById(target);
        if (byId) {
            return byId;
        }
        const byTestid = document.querySelector(`[data-testid="${CSS.escape(target)}"]`);
        if (byTestid instanceof HTMLElement) {
            return byTestid;
        }
        const candidates = document.querySelectorAll('button, [role="button"], a.btn, a.ui-btn');
        for (const el of Array.from(candidates)) {
            if (el instanceof HTMLElement && (el.textContent ?? '').trim() === target) {
                return el;
            }
        }

        // --- Fallback tolleranti (DOPO i match esatti). Servono quando il
        //     modello passa la label visibile o l'id di una region invece del
        //     verb esatto di data-kitt-action: senza questi, freccia/spotlight
        //     di tour_step/move_cursor non si ancorano (ritorno null). ---
        return this.findActionTargetFuzzy(target);
    }

    /**
     * Risoluzione tollerante del target, provata solo dopo i match esatti:
     *   a. data-kitt-action confrontato case-insensitive;
     *   b. bottone/[role=button]/a.btn/a.ui-btn il cui testo CONTIENE target
     *      (case-insensitive, trim) — utile per label parziali;
     *   c. target == region → azione primaria dentro la region (primo
     *      data-kitt-action o bottone visibile).
     * Ritorna il primo match VISIBILE.
     */
    private findActionTargetFuzzy(target: string): HTMLElement | null {
        const needle = target.trim().toLowerCase();
        if (needle === '') {
            return null;
        }

        // a. data-kitt-action case-insensitive.
        for (const el of Array.from(document.querySelectorAll('[data-kitt-action]'))) {
            if (!(el instanceof HTMLElement)) continue;
            if ((el.getAttribute('data-kitt-action') ?? '').trim().toLowerCase() === needle && this.isVisible(el)) {
                return el;
            }
        }

        // b. bottone il cui testo CONTIENE il target (substring case-insensitive).
        const buttons = document.querySelectorAll('button, [role="button"], a.btn, a.ui-btn');
        for (const el of Array.from(buttons)) {
            if (!(el instanceof HTMLElement)) continue;
            const text = (el.textContent ?? '').trim().toLowerCase();
            if (text !== '' && text.includes(needle) && this.isVisible(el)) {
                return el;
            }
        }

        // c. target è una region → azione primaria visibile dentro la region.
        const region = document.querySelector(`[data-kitt-region="${CSS.escape(target)}"]`);
        if (region instanceof HTMLElement) {
            const primary = region.querySelector('[data-kitt-action], button, [role="button"], a.btn, a.ui-btn');
            if (primary instanceof HTMLElement && this.isVisible(primary)) {
                return primary;
            }
        }

        return null;
    }

    /**
     * Visibile = nel layout (offsetParent o rettangolo non nullo) e non
     * nascosto via display/visibility. jsdom non calcola il layout, quindi
     * lì un elemento montato è considerato visibile salvo display:none /
     * visibility:hidden esplicito — coerente con i test in jsdom.
     */
    private isVisible(el: HTMLElement): boolean {
        const style = el.ownerDocument.defaultView?.getComputedStyle(el);
        if (style && (style.display === 'none' || style.visibility === 'hidden')) {
            return false;
        }
        // #37 — niente più "|| style != null" (sempre vero: getComputedStyle
        // ritorna SEMPRE), che rendeva morti i controlli di layout sotto e faceva
        // risultare visibile anche un elemento a dimensione zero. In jsdom il
        // layout non è calcolato (getClientRects sempre vuoto): lo rileviamo a
        // livello documento e consideriamo visibile (salvo display:none /
        // visibility:hidden già gestiti). In un browser vero usiamo il layout reale.
        const layoutAvailable = (el.ownerDocument.body?.getClientRects().length ?? 0) > 0;
        if (!layoutAvailable) {
            return true;
        }

        return (
            el.getClientRects().length > 0 ||
            el.offsetWidth > 0 ||
            el.offsetHeight > 0 ||
            el.offsetParent !== null
        );
    }

    private findField(name: string): HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement | null {
        const wrapper = document.querySelector(`[data-kitt-field="${CSS.escape(name)}"]`);
        if (wrapper) {
            const marked = wrapper.querySelector('[data-kitt-input]') ?? wrapper.querySelector('input, select, textarea');
            if (marked instanceof HTMLInputElement || marked instanceof HTMLSelectElement || marked instanceof HTMLTextAreaElement) {
                return marked;
            }
            if (wrapper instanceof HTMLInputElement || wrapper instanceof HTMLSelectElement || wrapper instanceof HTMLTextAreaElement) {
                return wrapper;
            }
        }
        const byName = document.querySelector(`[name="${CSS.escape(name)}"]`);
        if (byName instanceof HTMLInputElement || byName instanceof HTMLSelectElement || byName instanceof HTMLTextAreaElement) {
            return byName;
        }
        const byTestid = document.querySelector(`[data-testid="${CSS.escape(name)}"]`);
        if (byTestid instanceof HTMLInputElement || byTestid instanceof HTMLSelectElement || byTestid instanceof HTMLTextAreaElement) {
            return byTestid;
        }

        return null;
    }

    private async click(target: string): Promise<ToolResult> {
        const el = this.findActionTarget(target);
        if (!el) {
            return fail('click', `Target "${target}" not found.`);
        }
        el.scrollIntoView({ block: 'center', behavior: 'smooth' });
        el.click();

        return ok('click', { actual: 'clicked', target });
    }

    private async type(field: string, value: string, append: boolean): Promise<ToolResult> {
        const input = this.findField(field);
        if (!input || input instanceof HTMLSelectElement) {
            return fail('type', `Field "${field}" not found or not typeable.`);
        }
        // #2 — non scrivere MAI in campi credenziali/nascosti, anche se il server
        // lo chiede: previene il pre-fill programmatico di password/hidden da
        // output LLM prompt-injected.
        if (input instanceof HTMLInputElement && (input.type === 'password' || input.type === 'hidden')) {
            return fail('type', `Field "${field}" is a sensitive input and cannot be typed into.`);
        }
        input.focus();
        input.value = append ? input.value + value : value;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));

        return ok('type', { actual: input.value, field });
    }

    private async select(field: string, value: unknown): Promise<ToolResult> {
        const input = this.findField(field);
        if (!(input instanceof HTMLSelectElement)) {
            return fail('select', `Field "${field}" is not a <select>.`);
        }
        const wanted = Array.isArray(value) ? value.map(String) : [String(value)];
        const matches = (optValue: string, optLabel: string): boolean =>
            wanted.some((w) => w.toLowerCase() === optValue.toLowerCase() || w.toLowerCase() === optLabel.toLowerCase());

        let any = false;
        for (const option of Array.from(input.options)) {
            const hit = matches(option.value, option.label);
            if (input.multiple) {
                option.selected = hit;
            } else if (hit) {
                input.value = option.value;
            }
            any = any || hit;
        }
        if (!any) {
            return fail('select', `No option matched "${wanted.join(', ')}".`);
        }
        input.dispatchEvent(new Event('change', { bubbles: true }));

        return ok('select', { actual: input.value, field });
    }

    private async scrollTo(target: string): Promise<ToolResult> {
        if (target === 'top') {
            window.scrollTo({ top: 0, behavior: 'smooth' });

            return ok('scroll_to', { actual: 'top' });
        }
        if (target === 'bottom') {
            window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' });

            return ok('scroll_to', { actual: 'bottom' });
        }
        const el = this.findActionTarget(target);
        if (!el) {
            return fail('scroll_to', `Target "${target}" not found.`);
        }
        el.scrollIntoView({ block: 'center', behavior: 'smooth' });

        return ok('scroll_to', { actual: 'scrolled', target });
    }

    private async navigate(url: string): Promise<ToolResult> {
        if (url === '') {
            return fail('navigate_to', 'Empty URL.');
        }
        // #9 — difesa in profondità client-side (il backend valida l'allowlist
        // d'origine, ma non ci fidiamo del solo backend). Rifiuta gli URL
        // protocol-relative, incluso '/\evil.com' che i browser normalizzano in
        // '//evil.com' (open redirect cross-origin), e gli schemi non-http(s).
        const probe = url.replace(/\\/g, '/').replace(/%5c/gi, '/');
        if (probe.startsWith('//')) {
            return fail('navigate_to', 'Protocol-relative navigation is not allowed.');
        }
        let parsed: URL;
        try {
            parsed = new URL(url, location.href);
        } catch {
            return fail('navigate_to', `Invalid URL "${url}".`);
        }
        if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') {
            return fail('navigate_to', 'Only http(s) navigation is allowed.');
        }
        location.href = parsed.toString();

        return ok('navigate_to', { actual: 'navigating', url: parsed.toString() });
    }

    private async submit(): Promise<ToolResult> {
        const focusForm = (document.activeElement as HTMLElement | null)?.closest('form');
        // #2 — niente fallback a document.forms[0]: il server NON deve poter
        // submittare una form ARBITRARIA che l'utente non ha toccato. Solo la
        // form focalizzata o una form dentro una region annotata data-kitt.
        const form = focusForm ?? document.querySelector('[data-kitt-region] form') ?? null;
        if (!(form instanceof HTMLFormElement)) {
            return fail('submit_form', 'No focused or annotated form found to submit.');
        }
        form.requestSubmit();

        return ok('submit_form', { actual: 'submitted' });
    }

    // --- M4: nuovi runner DOM ---

    private findCombobox(field: string): { input: HTMLInputElement; dropdown: HTMLElement | null } | null {
        const wrapper = document.querySelector(`[data-kitt-field="${CSS.escape(field)}"]`);
        if (!wrapper) return null;

        const input = wrapper.querySelector('[data-kitt-input]') ?? wrapper.querySelector('input[type="text"], input[type="search"], input:not([type])');
        if (!(input instanceof HTMLInputElement)) return null;

        // Il dropdown associato al combobox, tipicamente data-kitt-dropdown o un sibling
        const dropdown = (wrapper.querySelector('[data-kitt-dropdown]') ?? wrapper.querySelector('[role="listbox"]')) as HTMLElement | null;

        return { input, dropdown };
    }

    // --- Select2 (jQuery) support ---------------------------------------
    //
    // I campi gestiti da Select2 NON sono un `<input>` dentro il wrapper:
    // sono un `<select class="select2-hidden-accessible">` (spesso con
    // `data-kitt-input`) affiancato da un `<span class="select2-container">`.
    // La casella di ricerca (`.select2-search__field`) e la lista risultati
    // (`.select2-results__options`) vengono montate a livello `document.body`
    // (in `.select2-container--open`) SOLO quando il dropdown è aperto.
    // Questo branch replica l'handler dedicato di KITT2 (DomLookup.js +
    // ComboboxSearchTool/ComboboxSetTool) adattato a TypeScript.

    /** Vero se il `<select>` risolto per `field` è potenziato da Select2. */
    private findSelect2(field: string): HTMLSelectElement | null {
        const wrapper = document.querySelector(`[data-kitt-field="${CSS.escape(field)}"]`);
        if (!wrapper) return null;

        const candidate = (wrapper.matches('select') ? wrapper : null)
            ?? wrapper.querySelector('select[data-kitt-input]')
            ?? wrapper.querySelector('select');
        if (!(candidate instanceof HTMLSelectElement)) return null;

        const isHidden = candidate.classList.contains('select2-hidden-accessible')
            || (candidate.nextElementSibling?.classList.contains('select2-container') ?? false);

        return isHidden ? candidate : null;
    }

    /** Trova il `.select2-container` visibile associato al `<select>` nativo. */
    private findSelect2Container(select: HTMLSelectElement): HTMLElement | null {
        const id = select.getAttribute('id');
        if (id) {
            const byId = document.querySelector(`.select2-container[id*="-${CSS.escape(id)}-container"], .select2-container[id*="${CSS.escape(id)}"]`);
            if (byId instanceof HTMLElement) return byId;
        }
        let sib = select.nextElementSibling;
        while (sib) {
            if (sib.classList.contains('select2-container')) return sib as HTMLElement;
            sib = sib.nextElementSibling;
        }
        const inWrap = select.parentElement?.querySelector('.select2-container');

        return inWrap instanceof HTMLElement ? inWrap : null;
    }

    /**
     * Apre il dropdown Select2 (via jQuery se disponibile, altrimenti click
     * sulla `.select2-selection`) e ritorna l'input di ricerca, montato a
     * livello body in `.select2-container--open .select2-search__field`.
     */
    private openSelect2(select: HTMLSelectElement): HTMLInputElement | null {
        const jq = (window as unknown as { jQuery?: (el: unknown) => { select2: (cmd: string) => void } }).jQuery;
        if (typeof jq === 'function') {
            try {
                jq(select).select2('open');
            } catch {
                /* fallback DOM sotto */
            }
        }
        const existing = document.querySelector('.select2-container--open .select2-search__field');
        if (existing instanceof HTMLInputElement) return existing;

        const container = this.findSelect2Container(select);
        const selection = container?.querySelector('.select2-selection');
        if (selection instanceof HTMLElement) {
            selection.click();
        }
        const opened = document.querySelector('.select2-container--open .select2-search__field');

        return opened instanceof HTMLInputElement ? opened : null;
    }

    /**
     * Esclude i nodi placeholder di Select2 ("No results", "Searching…",
     * "Loading more…") che sono `.select2-results__option` ma con
     * role="alert"/"presentation"/"group" o classe message/loading — non
     * vanno contati come opzioni valide.
     */
    private isSelect2PlaceholderNode(node: Element): boolean {
        if (node.classList.contains('loading-results') || node.classList.contains('select2-results__message')) return true;
        const role = node.getAttribute('role');

        return role === 'alert' || role === 'presentation' || role === 'group';
    }

    /**
     * Poll sulle opzioni del dropdown Select2 aperto (container globale).
     * Legge label dal textContent e value da `data-select2-id`/`jQuery data`,
     * con fallback alla label, escludendo i placeholder.
     */
    private async pollSelect2Options(timeoutMs: number): Promise<Array<{ value: string; label: string; el: HTMLElement }>> {
        const start = Date.now();
        const jq = (window as unknown as { jQuery?: (el: unknown) => { data: (k: string) => { id?: unknown; text?: unknown } | undefined } }).jQuery;

        const extract = (): Array<{ value: string; label: string; el: HTMLElement }> => {
            const list = document.querySelector('.select2-container--open .select2-results__options');
            if (!list) return [];
            const out: Array<{ value: string; label: string; el: HTMLElement }> = [];
            for (const node of Array.from(list.querySelectorAll('.select2-results__option, [role="option"]'))) {
                if (!(node instanceof HTMLElement)) continue;
                if (this.isSelect2PlaceholderNode(node)) continue;
                const label = (node.textContent ?? '').trim();
                if (!label) continue;
                let value = node.getAttribute('data-select2-id') ?? '';
                if (typeof jq === 'function') {
                    try {
                        const d = jq(node).data('data');
                        if (d && d.id !== undefined) value = String(d.id);
                        else if (d && d.text) value = String(d.text);
                    } catch {
                        /* ignore */
                    }
                }
                out.push({ value: value || label, label, el: node });
                if (out.length >= 20) break;
            }

            return out;
        };

        while (Date.now() - start < timeoutMs) {
            const list = document.querySelector('.select2-container--open .select2-results__options');
            const loading = list?.querySelector('.loading-results, .select2-results__option.loading-results');
            if (list && !loading) {
                const opts = extract();
                if (opts.length > 0) return opts;
            }
            await new Promise((r) => setTimeout(r, 200));
        }

        return extract();
    }

    /** combobox_search (Select2): apre, digita query nella search box globale, ritorna opzioni. */
    private async comboboxSearchSelect2(field: string, query: string, select: HTMLSelectElement): Promise<ToolResult> {
        const container = this.findSelect2Container(select);
        container?.scrollIntoView({ block: 'center', behavior: 'smooth' });

        const search = this.openSelect2(select);
        if (!search) {
            return fail('combobox_search', `Select2 dropdown for field "${field}" did not open.`);
        }
        search.focus();
        search.value = query;
        search.dispatchEvent(new Event('input', { bubbles: true }));
        search.dispatchEvent(new Event('keyup', { bubbles: true }));

        const found = await this.pollSelect2Options(8000);
        const options = found.map((o) => ({ value: o.value, label: o.label }));

        return ok('combobox_search', { actual: `searched "${query}"`, field, options_count: options.length, options });
    }

    /** combobox_set (Select2): apre, digita query, clicca l'option che matcha value. */
    private async comboboxSetSelect2(field: string, value: string, query: string | undefined, select: HTMLSelectElement): Promise<ToolResult> {
        const container = this.findSelect2Container(select);
        container?.scrollIntoView({ block: 'center', behavior: 'smooth' });

        const search = this.openSelect2(select);
        if (!search) {
            return fail('combobox_set', `Select2 dropdown for field "${field}" did not open.`);
        }
        const searchQuery = query ?? value;
        search.focus();
        search.value = searchQuery;
        search.dispatchEvent(new Event('input', { bubbles: true }));
        search.dispatchEvent(new Event('keyup', { bubbles: true }));

        const options = await this.pollSelect2Options(8000);
        const want = value.toLowerCase();
        const match = options.find((o) => o.value.toLowerCase() === want || o.label.toLowerCase() === want)
            ?? options.find((o) => o.label.toLowerCase().includes(want));
        if (!match) {
            return fail('combobox_set', `No option matching "${value}". Available: ${options.map((o) => o.label).join(', ')}`);
        }

        match.el.scrollIntoView({ block: 'nearest' });
        // Select2 ascolta mouseup sul risultato; aggiungiamo click per robustezza.
        match.el.dispatchEvent(new MouseEvent('mouseup', { bubbles: true, cancelable: true }));
        match.el.click();

        // Verifica selezione: chip/rendered aggiornata nella selection del container.
        const rendered = (container?.querySelector('.select2-selection__rendered, .select2-selection__choice')?.textContent ?? '').trim();

        return ok('combobox_set', { actual: rendered || value, field });
    }

    /** combobox_search: apre dropdown, digita query, ritorna opzioni trovate */
    private async comboboxSearch(field: string, query: string): Promise<ToolResult> {
        // Branch Select2: il campo è un <select> potenziato da jQuery Select2.
        const select2 = this.findSelect2(field);
        if (select2) return this.comboboxSearchSelect2(field, query, select2);

        const combo = this.findCombobox(field);
        if (!combo) return fail('combobox_search', `Combobox field "${field}" not found.`);

        const { input } = combo;
        input.focus();
        input.value = query;
        input.dispatchEvent(new Event('input', { bubbles: true }));

        // Attendi che le opzioni popolino (timeout 8s per Select2, 6s per custom)
        const options = await this.pollComboboxOptions(combo, 8000);

        return ok('combobox_search', { actual: `searched "${query}"`, field, options_count: options.length, options });
    }

    /** combobox_set: atomico — cerca, digita, seleziona l'option che matcha value */
    private async comboboxSet(field: string, value: string, query?: string): Promise<ToolResult> {
        // Branch Select2: stesso rilevamento di comboboxSearch.
        const select2 = this.findSelect2(field);
        if (select2) return this.comboboxSetSelect2(field, value, query, select2);

        const combo = this.findCombobox(field);
        if (!combo) return fail('combobox_set', `Combobox field "${field}" not found.`);

        const { input } = combo;
        const searchQuery = query ?? value;
        input.focus();
        input.value = searchQuery;
        input.dispatchEvent(new Event('input', { bubbles: true }));

        const options = await this.pollComboboxOptions(combo, 8000);

        // Cerca match esatto su value o label
        const match = options.find((opt) => opt.value.toLowerCase() === value.toLowerCase() || opt.label.toLowerCase() === value.toLowerCase());
        if (!match) {
            return fail('combobox_set', `No option matching "${value}". Available: ${options.map((o) => o.label).join(', ')}`);
        }

        // Click sull'opzione
        const optionEl = document.querySelector(`[data-kitt-field="${CSS.escape(field)}"] [data-option-value="${CSS.escape(match.value)}"]`) ??
            document.querySelector(`[data-kitt-field="${CSS.escape(field)}"] [role="option"][data-value="${CSS.escape(match.value)}"]`);
        if (optionEl instanceof HTMLElement) {
            optionEl.click();
        } else {
            // Fallback: imposta il valore direttamente sull'input
            input.value = match.value;
            input.dispatchEvent(new Event('change', { bubbles: true }));
        }

        return ok('combobox_set', { actual: value, field });
    }

    private async pollComboboxOptions(combo: { input: HTMLInputElement; dropdown: HTMLElement | null }, timeoutMs: number): Promise<Array<{ value: string; label: string }>> {
        const start = Date.now();
        const extractOptions = (): Array<{ value: string; label: string }> => {
            const parent = combo.dropdown ?? combo.input.closest('[data-kitt-field]') ?? document;
            const items = parent.querySelectorAll('[role="option"], [data-kitt-option], .select2-results__option');
            return Array.from(items).map((el) => ({
                value: (el as HTMLElement).dataset.value ?? (el as HTMLElement).dataset.optionValue ?? el.getAttribute('value') ?? '',
                label: (el.textContent ?? '').trim(),
            })).filter((o) => o.value !== '' || o.label !== '');
        };

        // Attendi che le opzioni appaiano
        while (Date.now() - start < timeoutMs) {
            const opts = extractOptions();
            if (opts.length > 0) return opts.slice(0, 20);
            await new Promise((r) => setTimeout(r, 200));
        }

        return extractOptions().slice(0, 20);
    }

    /** toggle: imposta/inverte checkbox/switch */
    private async toggle(field: string, on?: boolean): Promise<ToolResult> {
        const input = this.findField(field);
        if (!input) return fail('toggle', `Field "${field}" not found.`);

        if (input instanceof HTMLInputElement && (input.type === 'checkbox' || input.type === 'radio')) {
            if (on === undefined) {
                input.checked = !input.checked;
            } else {
                input.checked = on;
            }
            input.dispatchEvent(new Event('change', { bubbles: true }));

            return ok('toggle', { actual: input.checked ? 'on' : 'off', field });
        }

        // Switch custom (data-kitt-switch)
        const wrapper = input.closest('[data-kitt-field]') ?? input.parentElement;
        const switchEl = wrapper?.querySelector('[data-kitt-switch], [role="switch"]');
        if (switchEl instanceof HTMLElement) {
            const isCurrentlyOn = switchEl.getAttribute('aria-checked') === 'true';
            const targetOn = on ?? !isCurrentlyOn;
            if (isCurrentlyOn !== targetOn) {
                switchEl.click();
            }

            return ok('toggle', { actual: targetOn ? 'on' : 'off', field });
        }

        return fail('toggle', `Field "${field}" is not a checkbox or switch.`);
    }

    /** radio: seleziona radio per value */
    private async radio(field: string, value: string): Promise<ToolResult> {
        const wrapper = document.querySelector(`[data-kitt-field="${CSS.escape(field)}"]`);
        if (!wrapper) {
            // Fallback: cerca per name
            const byName = document.querySelectorAll(`input[type="radio"][name="${CSS.escape(field)}"]`);
            for (const r of Array.from(byName)) {
                if (r instanceof HTMLInputElement && r.value === value) {
                    r.checked = true;
                    r.dispatchEvent(new Event('change', { bubbles: true }));

                    return ok('radio', { actual: value, field });
                }
            }

            return fail('radio', `Radio group "${field}" not found.`);
        }

        const options = wrapper.querySelectorAll('input[type="radio"], [role="radio"]');
        for (const opt of Array.from(options)) {
            const optValue = (opt as HTMLInputElement).value ?? (opt as HTMLElement).dataset.value ?? (opt as HTMLElement).getAttribute('aria-label');
            if (optValue?.toLowerCase() === value.toLowerCase() || (opt as HTMLInputElement).value === value) {
                if (opt instanceof HTMLInputElement) {
                    opt.checked = true;
                } else if (opt instanceof HTMLElement) {
                    opt.setAttribute('aria-checked', 'true');
                }
                opt.dispatchEvent(new Event('change', { bubbles: true }));

                return ok('radio', { actual: value, field });
            }
        }

        return fail('radio', `Option "${value}" not found in radio group "${field}".`);
    }

    /** set_locale: cambia la lingua del form */
    private async setLocale(locale: string): Promise<ToolResult> {
        // Cerca un selettore lingua con data-kitt-locale o un select con le lingue
        const localeSwitcher = document.querySelector(`[data-kitt-locale="${CSS.escape(locale)}"]`);
        if (localeSwitcher instanceof HTMLElement) {
            localeSwitcher.click();

            return ok('set_locale', { actual: locale });
        }

        // Fallback: cerca un select che contenga le lingue (set_locale è già validato dal BE)
        const localeSelect = document.querySelector('[data-kitt-field="locale"], [name="locale"]') as HTMLSelectElement | null;
        if (localeSelect) {
            for (const option of Array.from(localeSelect.options)) {
                if (option.value === locale || option.label === locale) {
                    localeSelect.value = option.value;
                    localeSelect.dispatchEvent(new Event('change', { bubbles: true }));

                    return ok('set_locale', { actual: locale });
                }
            }
        }

        return fail('set_locale', `Locale switcher for "${locale}" not found in the page.`);
    }

    /** goto_step: naviga in un wizard multi-step */
    private async gotoStep(step: string, mode: string): Promise<ToolResult> {
        // Cerca step tramite data-kitt-step o region con id
        const stepEl = document.querySelector(`[data-kitt-step="${CSS.escape(step)}"]`) ??
            document.querySelector(`[data-kitt-region="${CSS.escape(step)}"]`);

        if (stepEl instanceof HTMLElement) {
            // Cerca il bottone next/prev/jump nella region
            const actionVerb = mode === 'prev' ? 'prev' : mode === 'next' ? 'next' : null;
            if (actionVerb) {
                const btn = stepEl.querySelector(`[data-kitt-action="${CSS.escape(actionVerb)}"]`);
                if (btn instanceof HTMLElement) {
                    btn.click();

                    return ok('goto_step', { actual: step, mode });
                }
            }
            // Fallback: click sulla region/step
            stepEl.click();

            return ok('goto_step', { actual: step, mode });
        }

        return fail('goto_step', `Step "${step}" not found.`);
    }

    /** wait_for: attende una condizione DOM */
    private async waitFor(condition: string, timeoutMs: number): Promise<ToolResult> {
        // #38 — clamp + guardia NaN: un timeout non finito o <= 0 → default 5000;
        // cap superiore a 30s così un timeout enorme da output LLM non blocca il
        // widget (busy) per minuti/ore, e un valore non numerico non produce NaN.
        let timeout = Number.isFinite(timeoutMs) && timeoutMs > 0 ? timeoutMs : 5000;
        timeout = Math.min(timeout, 30_000);
        const start = Date.now();

        // Condizioni note: elemento visibile, testo presente
        const checkCondition = (): boolean => {
            // Selettore data-kitt-action
            if (document.querySelector(`[data-kitt-action="${CSS.escape(condition)}"]`)) return true;
            // data-testid
            if (document.querySelector(`[data-testid="${CSS.escape(condition)}"]`)) return true;
            // id
            if (document.getElementById(condition)) return true;

            return false;
        };

        while (Date.now() - start < timeout) {
            if (checkCondition()) {
                return ok('wait_for', { actual: condition, waited_ms: Date.now() - start });
            }
            await new Promise((r) => setTimeout(r, 200));
        }

        return fail('wait_for', `Condition "${condition}" not met within ${timeout}ms.`);
    }

    /** tour_step: mostra un overlay tour sopra un elemento */
    private async tourStep(args: Record<string, unknown>): Promise<ToolResult> {
        const highlightTarget = String(args.highlight_target ?? '');
        const message = String(args.message ?? '');
        // Il tour è un concetto UI — per ora emettiamo solo un ok con i dati;
        // il FE renderizzerà l'overlay tramite UiArtifactRenderer (M4.8).
        const el = this.findActionTarget(highlightTarget);

        return ok('tour_step', {
            actual: 'tour_highlight',
            highlight_target: highlightTarget,
            message,
            step_index: args.step_index ?? 0,
            step_total: args.step_total ?? 1,
            found: el !== null,
        });
    }

    /** move_cursor: sposta il cursore visivo su un elemento */
    private async moveCursor(target: string): Promise<ToolResult> {
        const el = this.findActionTarget(target);
        if (!el) {
            return fail('move_cursor', `Target "${target}" not found.`);
        }
        el.scrollIntoView({ block: 'center', behavior: 'smooth' });
        el.focus();

        return ok('move_cursor', { actual: 'cursor_moved', target });
    }

    /** show_recap: mostra un recap riassuntivo — è un tool conversazionale,
     *  il FE renderizza il recap nella chat. */
    private async showRecap(args: Record<string, unknown>): Promise<ToolResult> {
        return ok('show_recap', {
            actual: 'recap_shown',
            summary: String(args.summary ?? ''),
            rows: Array.isArray(args.rows) ? args.rows : [],
        });
    }
}
