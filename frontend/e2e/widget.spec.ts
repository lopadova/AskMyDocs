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

    test('#3 — il valore di un campo password non lascia mai la pagina nello snapshot', async ({ page }) => {
        await page.goto('/widget-demo');

        // L'utente digita una password nel form ospite (campo annotato data-kitt
        // ma SENZA data-kitt-sensitive: la sensibilità è dedotta dal type).
        const secret = 'SuperSegreta-12345';
        await page.locator('#secret').fill(secret);

        // Osserva (NON stubba: page.on, non page.route → R13 ok) il corpo della
        // POST /sessions/start che porta lo snapshot al backend.
        const startReq = page.waitForRequest(
            (r) => r.url().includes('/api/widget/sessions/start') && r.method() === 'POST',
        );

        const launcher = page.getByTestId('askmydocs-widget-launcher');
        await expect(launcher).toBeVisible({ timeout: 15_000 });
        await launcher.click();
        await page.getByTestId('askmydocs-widget-input').fill('Posso lavorare da remoto?');
        await page.getByTestId('askmydocs-widget-send').click();

        const body = (await startReq).postData() ?? '';
        expect(body.length).toBeGreaterThan(0);
        expect(body).not.toContain(secret);
    });
});

/*
 * M4.14 — Scenari agentici end-to-end contro il VERO backend (R13).
 *
 * Con AI_PROVIDER=fake il FakeProvider emette una sequenza di tool_call
 * SCRIPTATA (vedi app/Ai/Providers/FakeProvider::scriptToolCalls):
 *   - "compila il profilo" → type(full-name) → click(submit) → report_done;
 *   - "policy / remote work" → search_knowledge_base (tool BE) → risposta.
 * NESSUNA rotta interna /api/widget/* è stubbata: snapshot, orchestratore,
 * executor DOM, bridge e /exec-tool girano realmente sul backend seeded.
 * È esattamente questo che cattura le regressioni del wire-format agentico
 * (lo stub precedente le nascondeva, R13).
 */
test.describe('KITT widget — scenario agentico M4 (dati reali)', () => {
    test('compila il campo nome e salva: type → click → report_done (orchestratore reale)', async ({ page }) => {
        await page.goto('/widget-demo');

        const launcher = page.getByTestId('askmydocs-widget-launcher');
        await expect(launcher).toBeVisible({ timeout: 15_000 });
        await launcher.click();

        const panel = page.getByTestId('askmydocs-widget-panel');
        await expect(panel).toHaveAttribute('data-open', 'true');

        // "compila il profilo" innesca lo script agentico del FakeProvider.
        await page.getByTestId('askmydocs-widget-input').fill('Compila il profilo per me');
        await page.getByTestId('askmydocs-widget-send').click();

        // L'executor DOM reale ha scritto nel campo full-name…
        await expect(page.locator('#full-name')).toHaveValue('Mario Rossi', { timeout: 15_000 });
        // …e cliccato Salva → il form della pagina ospite mostra "Form inviato".
        await expect(page.locator('#demo-result')).toContainText('Form inviato', { timeout: 15_000 });
        // report_done chiude il turno → panel torna idle.
        await expect(panel).toHaveAttribute('data-state', 'idle', { timeout: 15_000 });
    });

    test('search_knowledge_base: il tool BE reale ritorna un artifact renderizzato in chat', async ({ page }) => {
        await page.goto('/widget-demo');

        const launcher = page.getByTestId('askmydocs-widget-launcher');
        await expect(launcher).toBeVisible({ timeout: 15_000 });
        await launcher.click();

        const panel = page.getByTestId('askmydocs-widget-panel');
        await expect(panel).toHaveAttribute('data-open', 'true');

        // "policy / remote work" innesca search_knowledge_base (tool BE): il
        // widget chiama il VERO /api/widget/sessions/{id}/exec-tool, che esegue
        // SearchKnowledgeBaseTool sulla retrieval reale e ritorna un artifact
        // (data-table con risultati, o alert se la KB del progetto demo è
        // vuota). In entrambi i casi l'artifact REALE viene renderizzato in chat.
        await page.getByTestId('askmydocs-widget-input').fill('Qual è la policy sul remote work?');
        await page.getByTestId('askmydocs-widget-send').click();

        const artifact = page.locator('.amd-artifact').first();
        await expect(artifact).toBeVisible({ timeout: 15_000 });
        // Il turno si chiude con la risposta testuale → panel idle.
        await expect(panel).toHaveAttribute('data-state', 'idle', { timeout: 15_000 });
    });
});
