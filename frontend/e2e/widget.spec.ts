import { test, expect } from './fixtures';

/*
 * M3 + M4.14 — E2E del widget KITT embeddabile sulla pagina demo pubblica
 * (/widget-demo, non-SPA). Esercita il flusso reale: il widget legge il DOM
 * ospite, apre una sessione su /api/widget/sessions/start e rende la risposta.
 *
 * R13: il provider AI è il FakeProvider deterministico (AI_PROVIDER=fake nel
 * webServer Playwright) — niente LLM esterno, niente API key. Le rotte interne
 * /api/widget/* NON sono stubbate nello happy path: gira tutto contro il vero
 * backend + DB. La pagina è pubblica, quindi NON serve login (il widget vive
 * dietro la embed-key, non dietro la sessione admin).
 *
 * M4.14: gli scenari agentici intercettano le risposte API per simulare
 * tool_call DOM (type + click) e tool BE (search_knowledge_base → artifact).
 * Questo perché il FakeProvider restituisce solo testo, senza tool_call.
 */
test.describe('KITT widget — chat embeddabile', () => {
    test('si apre, invia una domanda e mostra la risposta', async ({ page }) => {
        await page.goto('/widget-demo');

        const launcher = page.getByTestId('askmydocs-widget-launcher');
        await expect(launcher).toBeVisible({ timeout: 15_000 });
        await launcher.click();

        const panel = page.getByTestId('askmydocs-widget-panel');
        await expect(panel).toHaveAttribute('data-open', 'true');

        await page.getByTestId('askmydocs-widget-input').fill('Posso lavorare da remoto?');
        await page.getByTestId('askmydocs-widget-send').click();

        // FakeProvider ritorna una risposta canned ("…work remotely…").
        await expect(page.getByTestId('askmydocs-widget-message').last()).toContainText(
            /remote|remoto|knowledge base/i,
            { timeout: 15_000 },
        );
        // Tornato idle a fine turno (stato osservabile, R14/R15).
        await expect(panel).toHaveAttribute('data-state', 'idle', { timeout: 15_000 });
    });

    test('mostra un errore quando la key è rifiutata', async ({ page }) => {
        /* R13: failure injection — lo happy path sopra copre il flusso reale
           contro /api/widget/sessions/start; qui iniettiamo un 401 per
           verificare che il fallimento sia visibile in UI (R14: mai 200 muto). */
        await page.route('**/api/widget/sessions/start', (route) =>
            route.fulfill({
                status: 401,
                contentType: 'application/json',
                body: JSON.stringify({ error: 'widget_key_invalid', message: 'Unknown widget key.' }),
            }),
        );

        await page.goto('/widget-demo');
        await page.getByTestId('askmydocs-widget-launcher').click();
        await page.getByTestId('askmydocs-widget-input').fill('ciao');
        await page.getByTestId('askmydocs-widget-send').click();

        await expect(page.getByTestId('askmydocs-widget-error')).toBeVisible({ timeout: 15_000 });
    });

    test('applica il tema inline: il launcher usa il colore del theme', async ({ page }) => {
        await page.goto('/widget-demo');

        const launcher = page.getByTestId('askmydocs-widget-launcher');
        await expect(launcher).toBeVisible({ timeout: 15_000 });
        // La pagina demo imposta theme.launcherBackground = #16a34a, applicato
        // in fase 1 (inline, sincrono) prima del primo paint → rgb(22,163,74).
        await expect(launcher).toHaveCSS('background-color', 'rgb(22, 163, 74)');
    });

    test('modalità inline: blocco chat montato nel container, sempre aperto, senza launcher', async ({ page }) => {
        await page.goto('/widget-demo?mode=inline');

        // Il pannello è renderizzato dentro il container ospite e già aperto:
        // nessun click sul launcher (che in inline non esiste come affordance).
        const panel = page.getByTestId('askmydocs-widget-panel');
        await expect(panel).toBeVisible({ timeout: 15_000 });
        await expect(panel).toHaveAttribute('data-open', 'true');
        await expect(page.getByTestId('askmydocs-widget-launcher')).toBeHidden();

        await page.getByTestId('askmydocs-widget-input').fill('Posso lavorare da remoto?');
        await page.getByTestId('askmydocs-widget-send').click();

        await expect(page.getByTestId('askmydocs-widget-message').last()).toContainText(
            /remote|remoto|knowledge base/i,
            { timeout: 15_000 },
        );
        await expect(panel).toHaveAttribute('data-state', 'idle', { timeout: 15_000 });
    });
});

/*
 * M4.14 — Scenari agentici completi: multi-step DOM (type + click) e
 * artifact BE (search_knowledge_base → ui-data-table).
 *
 * Il FakeProvider non emette tool_call, quindi intercettiamo le risposte
 * API con page.route() per simulare il comportamento agentico del LLM.
 * Il resto (snapshot, executor DOM, bridge) gira realmente nel browser.
 */
test.describe('KITT widget — scenario agentico M4', () => {
    /** Costruisce la risposta JSON per /sessions/start con tool_call DOM. */
    function toolCallStart(sessionId: string, tool: string, args: Record<string, unknown>, botMessage = '') {
        return {
            type: 'tool_call',
            session: { id: sessionId, status: 'waiting_tool' },
            tool_call: { tool, args, confirmation_required: false, is_be_tool: false },
            bot_message: botMessage,
        };
    }

    /** Costruisce la risposta JSON per /sessions/{id}/step con tool_call DOM. */
    function toolCallStep(tool: string, args: Record<string, unknown>, botMessage = '') {
        return {
            type: 'tool_call',
            session: { status: 'waiting_tool' },
            tool_call: { tool, args, confirmation_required: false, is_be_tool: false },
            bot_message: botMessage,
        };
    }

    /** Costruisce la risposta JSON per report_done (chiusura sessione). */
    function reportDoneStep(summary: string) {
        return {
            type: 'tool_call',
            session: { status: 'completed' },
            tool_call: { tool: 'report_done', args: { summary }, confirmation_required: false, is_be_tool: false },
            bot_message: '',
        };
    }

    test('compila il campo nome e salva: scenario agentico multi-step (type → click → report_done)', async ({ page }) => {
        const sessionId = 'sess-agentico-001';
        let stepCount = 0;

        // Intercetta /start → il "LLM" chiede di compilare il campo full-name
        await page.route('**/api/widget/sessions/start', (route) =>
            route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify(toolCallStart(
                    sessionId,
                    'type',
                    { field: 'full-name', value: 'Mario Rossi' },
                    'Compilo il campo nome per te.',
                )),
            }),
        );

        // Intercetta /step: turno 1 → click submit, turno 2 → report_done
        await page.route('**/api/widget/sessions/*/step', (route) => {
            stepCount++;
            if (stepCount === 1) {
                return route.fulfill({
                    status: 200,
                    contentType: 'application/json',
                    body: JSON.stringify(toolCallStep('click', { target: 'submit' }, 'Ora clicco su Salva profilo.')),
                });
            }
            return route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify(reportDoneStep('Profilo compilato e salvato con successo.')),
            });
        });

        await page.goto('/widget-demo');

        const launcher = page.getByTestId('askmydocs-widget-launcher');
        await expect(launcher).toBeVisible({ timeout: 15_000 });
        await launcher.click();

        const panel = page.getByTestId('askmydocs-widget-panel');
        await expect(panel).toHaveAttribute('data-open', 'true');

        // Invia il messaggio utente che innesca il loop agentico
        await page.getByTestId('askmydocs-widget-input').fill('Compila il profilo per me');
        await page.getByTestId('askmydocs-widget-send').click();

        // Verifica che il campo sia stato compilato dall'executor (DOM reale)
        await expect(page.locator('#full-name')).toHaveValue('Mario Rossi', { timeout: 10_000 });

        // Verifica che il submit sia stato cliccato → il form mostra "Form inviato"
        await expect(page.locator('#demo-result')).toContainText('Form inviato', { timeout: 10_000 });

        // La sessione deve terminare (report_done) → panel torna idle
        await expect(panel).toHaveAttribute('data-state', 'idle', { timeout: 15_000 });
    });

    test('tool BE search_knowledge_base ritorna artifact ui-data-table nella chat', async ({ page }) => {
        const sessionId = 'sess-artifact-001';

        // Intercetta /start → il "LLM" chiama search_knowledge_base (BE tool)
        await page.route('**/api/widget/sessions/start', (route) =>
            route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({
                    type: 'tool_call',
                    session: { id: sessionId, status: 'waiting_tool' },
                    tool_call: {
                        tool: 'search_knowledge_base',
                        args: { query: 'remote work policy' },
                        confirmation_required: false,
                        is_be_tool: true,
                    },
                    bot_message: 'Cerco nella knowledge base per te.',
                }),
            }),
        );

        // Intercetta /exec-tool → ritorna artifact ui-data-table
        await page.route('**/api/widget/sessions/*/exec-tool', (route) =>
            route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({
                    artifact: {
                        componentType: 'ui-data-table',
                        componentProps: {
                            columns: [
                                { key: 'title', label: 'Titolo' },
                                { key: 'source', label: 'Fonte' },
                            ],
                            rows: [
                                { title: 'Remote Work Policy', source: 'HR Handbook' },
                                { title: 'WFH Guidelines', source: 'IT Security' },
                            ],
                        },
                    },
                    has_results: true,
                    interaction_mode: 'selection',
                }),
            }),
        );

        await page.goto('/widget-demo');

        const launcher = page.getByTestId('askmydocs-widget-launcher');
        await expect(launcher).toBeVisible({ timeout: 15_000 });
        await launcher.click();

        // Invia il messaggio utente che innesca la ricerca
        await page.getByTestId('askmydocs-widget-input').fill('Qual è la policy sul remote work?');
        await page.getByTestId('askmydocs-widget-send').click();

        // Verifica che l'artifact ui-data-table sia renderizzato nella chat
        // UiArtifactRenderer crea un wrapper con classe amd-artifact--ui-data-table
        const artifact = page.locator('.amd-artifact--ui-data-table').first();
        await expect(artifact).toBeVisible({ timeout: 15_000 });

        // Verifica che i dati del artifact siano visibili (titolo del documento)
        await expect(artifact).toContainText('Remote Work Policy', { timeout: 10_000 });
    });
});
