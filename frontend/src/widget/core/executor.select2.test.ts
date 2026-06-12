/**
 * Select2 support per i tool combobox dell'Executor.
 *
 * Il widget AskMyDocs deve pilotare anche i campi gestiti da jQuery Select2
 * (es. campo "Entita'" della pagina Consumption Rate su gescat), non solo i
 * combobox custom con data-kitt-dropdown / [role=listbox]. Select2:
 *   - usa un <select class="select2-hidden-accessible" data-kitt-input> nel
 *     wrapper [data-kitt-field], NON un <input>;
 *   - monta la search box (.select2-search__field) e la lista risultati
 *     (.select2-results__options) in .select2-container--open a livello body,
 *     SOLO quando il dropdown e' aperto.
 *
 * COSA E' REALE vs SIMULATO IN QUESTO TEST
 * ----------------------------------------
 * jsdom non esegue jQuery ne' il runtime di Select2. Qui montiamo un DOM
 * Select2-like statico e SIMULIamo l'apertura: il click sulla
 * `.select2-selection` (il path di fallback che l'Executor usa quando
 * window.jQuery non c'e') aggiunge a livello body un `.select2-container--open`
 * con la search box e le opzioni. Cio' che il test verifica davvero e' la
 * LOGICA DELL'EXECUTOR: rilevamento del select hidden, apertura via click sulla
 * selection, lettura/poll delle opzioni dal container globale, filtro dei
 * placeholder, risoluzione del match e click (mouseup+click) sull'option giusta.
 * Il runtime Select2 reale (jQuery, eventi input/keyup che filtrano l'AJAX) e'
 * stato verificato a mano nel browser, fuori da questo test.
 */
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { Executor } from './executor';

const OPTIONS = ['Felpa Nike', 'Felpa Adidas', 'Maglia Puma'];

/**
 * Monta un campo Select2-like: wrapper [data-kitt-field] con
 * <select select2-hidden-accessible data-kitt-input multiple> + sibling
 * .select2-container con la .select2-selection cliccabile.
 *
 * Il click sulla .select2-selection (handler aggiunto qui per SIMULARE
 * l'apertura) crea a livello body il dropdown aperto con le opzioni filtrate
 * sulla query digitata nella search box — come farebbe Select2 reale.
 */
function mountSelect2Field(field: string): void {
    document.body.innerHTML = `
        <main>
            <div data-kitt-field="${field}">
                <select id="cr-entity-select" class="select2-hidden-accessible" data-kitt-input data-kitt-field-inner multiple>
                    <option value="nike">Felpa Nike</option>
                    <option value="adidas">Felpa Adidas</option>
                    <option value="puma">Maglia Puma</option>
                </select>
                <span class="select2-container select2" id="select2-cr-entity-select-container">
                    <span class="selection">
                        <span class="select2-selection select2-selection--multiple" role="combobox">
                            <ul class="select2-selection__rendered"></ul>
                        </span>
                    </span>
                </span>
            </div>
        </main>
    `;

    const select = document.getElementById('cr-entity-select') as HTMLSelectElement;
    const selection = document.querySelector('.select2-selection') as HTMLElement;

    const openDropdown = (): void => {
        // Già aperto? niente da fare (idempotente come Select2).
        if (document.querySelector('.select2-container--open')) return;

        const open = document.createElement('span');
        open.className = 'select2-container select2-container--open';
        const search = document.createElement('input');
        search.className = 'select2-search__field';
        search.type = 'search';
        const results = document.createElement('ul');
        results.className = 'select2-results__options';
        results.setAttribute('role', 'listbox');
        open.appendChild(search);
        open.appendChild(results);
        document.body.appendChild(open);

        const renderOptions = (q: string): void => {
            results.innerHTML = '';
            const filtered = OPTIONS.filter((label) => label.toLowerCase().includes(q.toLowerCase()));
            if (filtered.length === 0) {
                // Placeholder Select2 "No results": NON e' un'opzione valida.
                const msg = document.createElement('li');
                msg.className = 'select2-results__option select2-results__message';
                msg.setAttribute('role', 'alert');
                msg.textContent = 'Nessun risultato';
                results.appendChild(msg);

                return;
            }
            for (const label of filtered) {
                const li = document.createElement('li');
                li.className = 'select2-results__option';
                li.setAttribute('role', 'option');
                const opt = Array.from(select.options).find((o) => o.label === label || o.text === label);
                li.setAttribute('data-select2-id', opt ? opt.value : label);
                li.textContent = label;
                // Click su un'opzione: aggiorna la chip e chiude il dropdown (come Select2).
                li.addEventListener('mouseup', () => {
                    const rendered = document.querySelector('.select2-selection__rendered') as HTMLElement;
                    const chip = document.createElement('li');
                    chip.className = 'select2-selection__choice';
                    chip.textContent = label;
                    rendered.appendChild(chip);
                    if (opt) opt.selected = true;
                    open.remove();
                });
                results.appendChild(li);
            }
        };

        // Filtra al digitare nella search box (Select2 ascolta input/keyup).
        search.addEventListener('input', () => renderOptions(search.value));
        // Render iniziale con query vuota (tutte le opzioni).
        renderOptions('');
    };

    selection.addEventListener('click', openDropdown);
}

afterEach(() => {
    document.body.innerHTML = '';
});

describe('Executor combobox — Select2 branch', () => {
    let executor: Executor;

    beforeEach(() => {
        executor = new Executor();
        mountSelect2Field('entity');
    });

    it('comboboxSearch apre il dropdown Select2 e ritorna le opzioni filtrate', async () => {
        const res = await executor.run('combobox_search', { field: 'entity', query: 'felpa' });

        expect(res.ok).toBe(true);
        expect(res.tool).toBe('combobox_search');
        const diag = res.diagnostic as { options_count: number; options: Array<{ value: string; label: string }> };
        expect(diag.options_count).toBe(2);
        const labels = diag.options.map((o) => o.label);
        expect(labels).toEqual(expect.arrayContaining(['Felpa Nike', 'Felpa Adidas']));
        expect(labels).not.toContain('Maglia Puma');
        // value letto da data-select2-id (jsdom non ha jQuery → fallback su attributo).
        const adidas = diag.options.find((o) => o.label === 'Felpa Adidas');
        expect(adidas?.value).toBe('adidas');
    });

    it('comboboxSet clicca l\'option Select2 che matcha value (per label)', async () => {
        const res = await executor.run('combobox_set', { field: 'entity', value: 'Felpa Adidas' });

        expect(res.ok).toBe(true);
        expect(res.tool).toBe('combobox_set');
        // La chip aggiornata riflette la selezione avvenuta.
        const chip = document.querySelector('.select2-selection__choice');
        expect(chip?.textContent).toBe('Felpa Adidas');
        // L'<option> nativo corrispondente è selezionato.
        const select = document.getElementById('cr-entity-select') as HTMLSelectElement;
        expect(Array.from(select.selectedOptions).map((o) => o.value)).toContain('adidas');
    });

    it('comboboxSet matcha per value (option value, non label)', async () => {
        const res = await executor.run('combobox_set', { field: 'entity', value: 'nike', query: 'felpa' });

        expect(res.ok).toBe(true);
        const select = document.getElementById('cr-entity-select') as HTMLSelectElement;
        expect(Array.from(select.selectedOptions).map((o) => o.value)).toContain('nike');
    });

    // NB: questi due casi cercano una query senza risultati validi. L'Executor
    // fa poll fino al timeout di 8s (comportamento corretto: aspetta che le
    // opzioni popolino) → serve un testTimeout > 8s, altrimenti vitest scade
    // al default di 5s.
    it('comboboxSet fallisce con ok:false e lista le opzioni disponibili quando nessun match', async () => {
        // query="felpa" popola due opzioni, ma value="Felpa Reebok" non matcha
        // nessuna → fail con la lista delle opzioni viste (recovery per l'LLM).
        const res = await executor.run('combobox_set', { field: 'entity', value: 'Felpa Reebok', query: 'felpa' });

        expect(res.ok).toBe(false);
        expect(res.error_message).toContain('No option matching');
        // Espone le opzioni disponibili per il recovery dell'LLM.
        expect(res.error_message).toContain('Felpa Nike');
        expect(res.error_message).toContain('Felpa Adidas');
    });

    it('comboboxSearch su query senza risultati esclude il placeholder Select2 ("No results")', async () => {
        const res = await executor.run('combobox_search', { field: 'entity', query: 'zzz-inesistente' });

        expect(res.ok).toBe(true);
        const diag = res.diagnostic as { options_count: number };
        // Il nodo .select2-results__message (role=alert) NON è contato come opzione.
        expect(diag.options_count).toBe(0);
    }, 12_000);

    it('riconosce Select2 anche via sibling .select2-container quando manca la classe hidden', async () => {
        // Rimuove la classe select2-hidden-accessible: il rilevamento deve
        // comunque scattare grazie al sibling .select2-container adiacente.
        const select = document.getElementById('cr-entity-select') as HTMLSelectElement;
        select.classList.remove('select2-hidden-accessible');

        const res = await executor.run('combobox_search', { field: 'entity', query: 'felpa' });
        expect(res.ok).toBe(true);
        expect((res.diagnostic as { options_count: number }).options_count).toBe(2);
    });
});

describe('Executor combobox — path custom intatto (no regressione)', () => {
    let executor: Executor;

    afterEach(() => {
        document.body.innerHTML = '';
    });

    beforeEach(() => {
        executor = new Executor();
        // Combobox custom: input + dropdown con [role=option][data-value].
        document.body.innerHTML = `
            <div data-kitt-field="city">
                <input data-kitt-input type="text" />
                <div data-kitt-dropdown role="listbox">
                    <div role="option" data-value="rome">Roma</div>
                    <div role="option" data-value="milan">Milano</div>
                </div>
            </div>
        `;
    });

    it('comboboxSearch legge le opzioni dal dropdown custom (non Select2)', async () => {
        const res = await executor.run('combobox_search', { field: 'city', query: 'ro' });

        expect(res.ok).toBe(true);
        const diag = res.diagnostic as { options: Array<{ value: string; label: string }> };
        expect(diag.options.map((o) => o.label)).toEqual(expect.arrayContaining(['Roma', 'Milano']));
    });

    it('comboboxSet clicca l\'option custom che matcha', async () => {
        let clicked = '';
        for (const el of Array.from(document.querySelectorAll('[role="option"]'))) {
            el.addEventListener('click', () => {
                clicked = (el as HTMLElement).dataset.value ?? '';
            });
        }

        const res = await executor.run('combobox_set', { field: 'city', value: 'Roma' });
        expect(res.ok).toBe(true);
        expect(clicked).toBe('rome');
    });
});
