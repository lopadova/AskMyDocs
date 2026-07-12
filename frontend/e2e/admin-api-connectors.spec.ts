import { test, expect } from './fixtures';
import type { Page } from '@playwright/test';

/*
 * v8.27 — API Connector (Connettore API) admin SPA scenarios.
 *
 * Auth posture: every /api/admin/api-connectors/* endpoint is gated by
 * `can:manageConnectors` (admin + super-admin). This file runs under the default
 * `chromium` project (storageState playwright/.auth/admin.json, admin@demo.local)
 * and relies on the `seeded` auto-fixture (reset → DemoSeeder → re-login).
 *
 * R13 — real backend, real DB, real Sanctum cookies, real Gate. The "Test
 * connessione" / "Prova tool" calls are issued BY THE BACKEND over HTTP, so
 * Playwright cannot page.route them (same constraint as the IMAP TCP seam). The
 * happy path therefore points the route at the app's OWN /healthz endpoint — a
 * real, deterministic local 200 — with the SSRF guard relaxed for E2E in
 * playwright.config.ts (API_CONNECTOR_SSRF_ENABLED=false). NO internal /api/*
 * route is intercepted on the happy path.
 *
 * R11/R12 — happy path (create connector → add route → test → activate) +
 * failure path (422 on an invalid route create).
 */

const HEALTHZ = 'http://127.0.0.1:8000/healthz';
const LIST_FIXTURE = 'http://127.0.0.1:8000/testing/api-fixture/users';
const DETAIL_FIXTURE = 'http://127.0.0.1:8000/testing/api-fixture/users/{id}';
const PAGED_FIXTURE = 'http://127.0.0.1:8000/testing/api-fixture/paged';

async function createConnector(page: Page, name: string): Promise<void> {
    await page.getByTestId('api-connector-create').click();
    const form = page.getByTestId('api-connector-form');
    await expect(form).toBeVisible();
    await page.getByTestId('api-connector-form-name').fill(name);
    await page.getByTestId('api-connector-form-base_url').fill('http://127.0.0.1:8000');
    await page.getByTestId('api-connector-form-submit').click();
    await expect(page.getByTestId('toast-api-connector-created')).toBeVisible({ timeout: 15_000 });
}

async function connectorIdFromCard(page: Page): Promise<string> {
    const card = page.locator('[data-testid^="api-connector-"][data-testid$="-card"]').first();
    await expect(card).toBeVisible();
    const cardId = (await card.getAttribute('data-testid'))!;
    return cardId.replace('api-connector-', '').replace('-card', '');
}

/**
 * Create a route via the workspace's left config pane, then reopen the workspace
 * and test it against its local fixture from the right "Prova & esplora" console.
 * Returns the route-row base testid. Asserts the auto-detected endpoint type.
 * `withIdParam` adds an `id` (path/llm) parameter for a detail route.
 */
async function addRouteAndTest(
    page: Page,
    connectorId: string,
    opts: { name: string; url: string; expectedType: 'list' | 'detail'; withIdParam?: boolean; exampleArgs?: string },
): Promise<string> {
    await page.getByTestId(`api-connector-${connectorId}-route-add`).click();
    await expect(page.getByTestId('api-route-form')).toBeVisible();
    await page.getByTestId('api-route-form-name').fill(opts.name);
    await page.getByTestId('api-route-form-http_method').selectOption('GET');
    await page.getByTestId('api-route-form-url').fill(opts.url);
    if (opts.withIdParam) {
        await page.getByTestId('api-route-form-param-add').click();
        await page.getByTestId('api-route-form-param-0-name').fill('id');
        await page.getByTestId('api-route-form-param-0-location').selectOption('path');
        await page.getByTestId('api-route-form-param-0-source').selectOption('llm');
        await page.getByTestId('api-route-form-param-0-type').selectOption('integer');
    }
    await page.getByTestId('api-route-form-submit').click();
    await expect(page.getByTestId('toast-api-route-created')).toBeVisible({ timeout: 15_000 });

    const statusLoc = page
        .locator(`[data-testid^="api-connector-${connectorId}-route-"][data-testid$="-status"]`)
        .last();
    const statusId = (await statusLoc.getAttribute('data-testid'))!;
    const base = statusId.replace('-status', '');

    // Reopen the workspace (the row's Test action) and run the call from the
    // right-hand console — same modal as the editor now.
    await page.getByTestId(`${base}-test`).click();
    await expect(page.getByTestId('api-route-wb')).toBeVisible({ timeout: 15_000 });
    if (opts.exampleArgs) {
        await page.getByTestId('api-route-wb-example-args').fill(opts.exampleArgs);
    }
    await page.getByTestId('api-route-wb-test-run').click();
    const result = page.getByTestId('api-route-wb-test-result');
    await expect(result).toBeVisible({ timeout: 20_000 });
    await expect(result).toHaveAttribute('data-ok', 'true');
    // The auto-detected endpoint type is shown in the console.
    await expect(page.getByTestId('api-route-wb-test-endpoint-type')).toHaveAttribute(
        'data-endpoint-type',
        opts.expectedType,
        { timeout: 15_000 },
    );
    await page.getByTestId('api-route-form-cancel').click();
    // …and as a chip on the route row.
    await expect(page.getByTestId(`${base}-endpoint-type`)).toHaveAttribute('data-endpoint-type', opts.expectedType, {
        timeout: 15_000,
    });
    return base;
}

test.describe('Admin API Connectors', () => {
    test.beforeEach(async ({ page }) => {
        await page.goto('/app/admin/api-connectors');
        await expect(page.getByTestId('api-connectors-view')).toBeVisible({ timeout: 15_000 });
    });

    test('starts empty with a create CTA', async ({ page }) => {
        await expect(page.getByTestId('api-connectors-view')).toHaveAttribute('data-state', 'empty', {
            timeout: 15_000,
        });
        await expect(page.getByTestId('api-connectors-empty')).toBeVisible();
        await expect(page.getByTestId('api-connector-create')).toBeVisible();
    });

    test('happy path — create connector → add route → test → activate', async ({ page }) => {
        await createConnector(page, 'E2E Local API');

        // The view flips to ready with one card.
        await expect(page.getByTestId('api-connectors-view')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });
        const card = page.locator('[data-testid^="api-connector-"][data-testid$="-card"]').first();
        await expect(card).toBeVisible();
        const cardId = (await card.getAttribute('data-testid'))!; // api-connector-{id}-card
        const connectorId = cardId.replace('api-connector-', '').replace('-card', '');

        // Add a route pointing at the app's own /healthz (deterministic local 200).
        await page.getByTestId(`api-connector-${connectorId}-route-add`).click();
        const routeForm = page.getByTestId('api-route-form');
        await expect(routeForm).toBeVisible();
        await page.getByTestId('api-route-form-name').fill('Ping healthz');
        await page.getByTestId('api-route-form-http_method').selectOption('GET');
        await page.getByTestId('api-route-form-url').fill(HEALTHZ);
        // Mode is locked to `tool`; ingest/both are Fase 2 (the select is disabled).
        const mode = page.getByTestId('api-route-form-mode');
        await expect(mode).toBeDisabled();
        await expect(mode).toHaveValue('tool');
        await page.getByTestId('api-route-form-submit').click();
        await expect(page.getByTestId('toast-api-route-created')).toBeVisible({ timeout: 15_000 });

        // The route row renders in draft.
        const routeRow = page
            .locator(`[data-testid^="api-connector-${connectorId}-route-"][data-testid$="-status"]`)
            .first();
        await expect(routeRow).toBeVisible();
        await expect(routeRow).toHaveAttribute('data-status', 'draft');
        const statusId = (await routeRow.getAttribute('data-testid'))!; // api-connector-{c}-route-{r}-status
        const base = statusId.replace('-status', ''); // api-connector-{c}-route-{r}

        // Reopen the workspace + run the real backend test from the console.
        await page.getByTestId(`${base}-test`).click();
        await expect(page.getByTestId('api-route-wb')).toBeVisible({ timeout: 15_000 });
        await page.getByTestId('api-route-wb-test-run').click();

        const result = page.getByTestId('api-route-wb-test-result');
        await expect(result).toBeVisible({ timeout: 20_000 });
        await expect(result).toHaveAttribute('data-ok', 'true');
        await page.getByTestId('api-route-form-cancel').click();

        // The route is now `tested` — activate it.
        await expect(page.getByTestId(`${base}-status`)).toHaveAttribute('data-status', 'tested', {
            timeout: 15_000,
        });
        await page.getByTestId(`${base}-activate`).click();
        await expect(page.getByTestId('toast-api-route-activated')).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId(`${base}-status`)).toHaveAttribute('data-status', 'active', {
            timeout: 15_000,
        });
    });

    test('failure path — invalid route surfaces a 422 error in the DOM', async ({ page }) => {
        // R13: failure injection — drive a real 422 from the backend by posting a
        // route with a blank required URL. The happy-path test above already
        // exercises the real create+test+activate flow against real data; this
        // case proves the error surfaces loudly (R14) rather than failing silently.
        await createConnector(page, 'E2E Invalid Route Connector');
        const card = page.locator('[data-testid^="api-connector-"][data-testid$="-card"]').first();
        const cardId = (await card.getAttribute('data-testid'))!;
        const connectorId = cardId.replace('api-connector-', '').replace('-card', '');

        await page.getByTestId(`api-connector-${connectorId}-route-add`).click();
        await expect(page.getByTestId('api-route-form')).toBeVisible();
        await page.getByTestId('api-route-form-name').fill('Broken route');
        // Bypass the browser `required` attribute so the BLANK url reaches the
        // backend and produces a real 422 (HTML5 validation would otherwise
        // block submit before the request leaves the page).
        await page
            .getByTestId('api-route-form-url')
            .evaluate((el) => el.removeAttribute('required'));
        await page.getByTestId('api-route-form-submit').click();

        // The top-level banner (R14) AND the field-level error next to the URL
        // input (R15) both surface the backend 422.
        const error = page.getByTestId('api-route-form-error');
        await expect(error).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('api-route-form-url-error')).toBeVisible();
        // The form stays open on error (no success toast, no close).
        await expect(page.getByTestId('api-route-form')).toBeVisible();
        await expect(page.getByTestId('toast-api-route-created')).toHaveCount(0);
    });

    // Free-endpoint playground — the big probe modal. No persistence: it fires an
    // ad-hoc live call and reads the response. Happy path points at the app's OWN
    // /healthz (R13 — the same internal seam the route test uses; SSRF relaxed in
    // playwright.config.ts). Failure path = client-side URL validation.
    test('probe modal — method-conditional body + empty-URL validation', async ({ page }) => {
        await page.getByTestId('api-connector-probe').click();
        const panel = page.getByTestId('api-probe-panel');
        await expect(panel).toBeVisible();

        // Body is hidden for GET, shown for a body-bearing method.
        await expect(page.getByTestId('api-probe-body')).toHaveCount(0);
        await page.getByTestId('api-probe-method').selectOption('POST');
        await expect(page.getByTestId('api-probe-body')).toBeVisible();
        await page.getByTestId('api-probe-method').selectOption('GET');
        await expect(page.getByTestId('api-probe-body')).toHaveCount(0);

        // Failure path: sending an empty URL surfaces a validation error, no call.
        await page.getByTestId('api-probe-send').click();
        await expect(page.getByTestId('api-probe-url-error')).toBeVisible();
        await expect(page.getByTestId('api-probe-response')).toHaveCount(0);

        await page.getByTestId('api-probe-close').click();
        await expect(panel).toHaveCount(0);
    });

    test('probe modal — live send against /healthz reads the response', async ({ page }) => {
        await page.getByTestId('api-connector-probe').click();
        await expect(page.getByTestId('api-probe-panel')).toBeVisible();

        await page.getByTestId('api-probe-url').fill(HEALTHZ);
        await page.getByTestId('api-probe-send').click();

        const response = page.getByTestId('api-probe-response');
        await expect(response).toBeVisible({ timeout: 15_000 });
        await expect(page.getByTestId('api-probe-response-status')).toContainText('HTTP 200');
        await expect(page.getByTestId('api-probe-response-body')).toBeVisible();
    });

    // Full list → detail relation flow (spec Obj 1-3, Fase 2). Real backend, real
    // DB, real Gate; the list/detail calls hit the app's OWN local fixtures
    // (/testing/api-fixture/*), SSRF relaxed in playwright.config.ts. Proves:
    // auto-typed list + detail, a relation with a field map, and a drill-test that
    // maps a list item into the detail call and reads the raw detail response.
    test('list → detail relation + drill-test against local fixtures', async ({ page }) => {
        await createConnector(page, 'E2E Relations API');
        await expect(page.getByTestId('api-connectors-view')).toHaveAttribute('data-state', 'ready', {
            timeout: 15_000,
        });
        const connectorId = await connectorIdFromCard(page);

        // A LIST endpoint ({data:[…]} → auto-detected as `list`) …
        await addRouteAndTest(page, connectorId, {
            name: 'List users',
            url: LIST_FIXTURE,
            expectedType: 'list',
        });
        // … and a DETAIL endpoint (/{id} → single object → `detail`).
        await addRouteAndTest(page, connectorId, {
            name: 'User detail',
            url: DETAIL_FIXTURE,
            expectedType: 'detail',
            withIdParam: true,
            exampleArgs: '{"id": 1}',
        });

        // Relate them: map the list item's `id` onto the detail's `id` param.
        await page.getByTestId(`api-connector-${connectorId}-relation-add`).click();
        await expect(page.getByTestId('api-route-relation-form')).toBeVisible();
        await page.getByTestId('api-route-relation-form-list_route').selectOption({ label: /List users/ });
        await page.getByTestId('api-route-relation-form-detail_route').selectOption({ label: /User detail/ });
        await page.getByTestId('api-route-relation-form-map-0-from').fill('id');
        await page.getByTestId('api-route-relation-form-map-0-to_param').fill('id');
        await page.getByTestId('api-route-relation-form-submit').click();
        await expect(page.getByTestId('toast-api-relation-created')).toBeVisible({ timeout: 15_000 });

        // The relation row renders with a drill-test action.
        const relationRow = page
            .locator(`[data-testid^="api-connector-${connectorId}-relation-"][data-testid$="-drill"]`)
            .first();
        await expect(relationRow).toBeVisible({ timeout: 15_000 });

        // Drill-test: item #0 of the list (id=1) → detail call /users/1 → raw object.
        await relationRow.click();
        await expect(page.getByTestId('api-route-drill-panel')).toBeVisible();
        await page.getByTestId('api-route-drill-item-index').fill('0');
        await page.getByTestId('api-route-drill-run').click();

        const drillResult = page.getByTestId('api-route-drill-result');
        await expect(drillResult).toBeVisible({ timeout: 20_000 });
        await expect(drillResult).toHaveAttribute('data-ok', 'true');
        // The field map bound item[0].id (=1) into the detail arguments…
        await expect(page.getByTestId('api-route-drill-args')).toContainText('"id": 1');
        // …and the raw detail response came back.
        await expect(page.getByTestId('api-route-drill-body')).toContainText('Ada Lovelace');
        await page.getByTestId('api-route-drill-close').click();
    });

    // Route Workspace console (spec items 1-6): open the workspace from the row,
    // run a real call against the paged fixture, inspect the JSON + filter it, and
    // detect the cursor pagination deterministically into the left card, then
    // persist it. All against the local paged fixture (R13; SSRF relaxed). The
    // AI-driven "Configura con AI" path is intentionally NOT exercised here — it
    // would hit the external provider (R13) — its no-AI detect is covered instead.
    test('route workspace — test, inspect, filter and detect pagination', async ({ page }) => {
        await createConnector(page, 'E2E Workspace API');
        await expect(page.getByTestId('api-connectors-view')).toHaveAttribute('data-state', 'ready', { timeout: 15_000 });
        const connectorId = await connectorIdFromCard(page);

        // A route at the paged fixture with a `q` query (llm) param.
        await page.getByTestId(`api-connector-${connectorId}-route-add`).click();
        await expect(page.getByTestId('api-route-form')).toBeVisible();
        await page.getByTestId('api-route-form-name').fill('Paged catalog');
        await page.getByTestId('api-route-form-http_method').selectOption('GET');
        await page.getByTestId('api-route-form-url').fill(PAGED_FIXTURE);
        await page.getByTestId('api-route-form-param-add').click();
        await page.getByTestId('api-route-form-param-0-name').fill('q');
        await page.getByTestId('api-route-form-param-0-location').selectOption('query');
        await page.getByTestId('api-route-form-param-0-source').selectOption('llm');
        await page.getByTestId('api-route-form-param-0-type').selectOption('string');
        await page.getByTestId('api-route-form-submit').click();
        await expect(page.getByTestId('toast-api-route-created')).toBeVisible({ timeout: 15_000 });

        // Reopen the workspace from the row.
        const statusLoc = page
            .locator(`[data-testid^="api-connector-${connectorId}-route-"][data-testid$="-status"]`)
            .last();
        const base = (await statusLoc.getAttribute('data-testid'))!.replace('-status', '');
        await page.getByTestId(`${base}-edit`).click();
        await expect(page.getByTestId('api-route-wb')).toBeVisible({ timeout: 15_000 });

        // Testa chiamata — real backend call, `q` flavours the fixture names.
        await page.getByTestId('api-route-wb-example-args').fill('{"q":"boot"}');
        await page.getByTestId('api-route-wb-test-run').click();
        const wbTest = page.getByTestId('api-route-wb-test-result');
        await expect(wbTest).toBeVisible({ timeout: 20_000 });
        await expect(wbTest).toHaveAttribute('data-ok', 'true');
        await expect(page.getByTestId('api-route-wb-test-endpoint-type')).toHaveAttribute('data-endpoint-type', 'list');

        // JSON view shows the raw body; Cerca filters it to the matching lines.
        await page.getByTestId('api-route-wb-tab-raw').click();
        await expect(page.getByTestId('api-route-wb-response')).toContainText('match boot');
        await page.getByTestId('api-route-wb-tab-search').click();
        await page.getByTestId('api-route-wb-search').fill('boot');
        await expect(page.getByTestId('api-route-wb-response')).toContainText('match boot');

        // Left pane — deterministic cursor detection from meta.next_cursor.
        await page.getByTestId('api-route-form-pagination-detect').click();
        await expect(page.getByTestId('api-route-form-pagination-source')).toBeVisible({ timeout: 20_000 });
        await expect(page.getByTestId('api-route-form-pagination-type')).toHaveValue('cursor');
        await expect(page.getByTestId('api-route-form-pagination-next_cursor_path')).toHaveValue('meta.next_cursor');

        // Persist the whole config (incl. the detected pagination).
        await page.getByTestId('api-route-form-submit').click();
        await expect(page.getByTestId('toast-api-route-updated')).toBeVisible({ timeout: 15_000 });
    });
});
