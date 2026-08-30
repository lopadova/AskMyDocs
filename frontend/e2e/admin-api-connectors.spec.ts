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
 * happy path therefore points the route at the app's OWN JSON fixture — a real,
 * deterministic local 200 — with the SSRF guard relaxed for E2E in
 * playwright.config.ts (API_CONNECTOR_SSRF_ENABLED=false). NO internal /api/*
 * route is intercepted on the happy path. The free-form probe separately uses
 * /healthz to prove that successful non-JSON responses remain inspectable.
 *
 * R11/R12 — happy path (create connector → add route → test → activate) +
 * failure path (422 on an invalid route create).
 */

/*
 * The app under test is the CLIENT here: these scenarios configure a
 * connector whose routes point at a real endpoint and then ask the server to
 * call it. Aiming that at the server's own address made it the client of
 * itself, and PHP's built-in dev server answers such a request with an empty
 * reply (cURL 52) whenever the worker that would serve it is the one already
 * busy issuing it. It is not a flake and no retry escapes it.
 *
 * So the outbound target is a SECOND instance of the same application on
 * another port (see `webServer` in playwright.config.ts, and the matching
 * step in tests.yml for CI). Separate process, real HTTP round trip, same
 * app and database — no route is mocked, so R13 still holds.
 */
const OUTBOUND_BASE = process.env.E2E_OUTBOUND_BASE_URL ?? 'http://127.0.0.1:8001';

const HEALTHZ = `${OUTBOUND_BASE}/healthz`;
const LIST_FIXTURE = `${OUTBOUND_BASE}/testing/api-fixture/users`;
const DETAIL_FIXTURE = `${OUTBOUND_BASE}/testing/api-fixture/users/{id}`;
const PAGED_FIXTURE = `${OUTBOUND_BASE}/testing/api-fixture/paged`;

async function createConnector(page: Page, name: string): Promise<void> {
    await page.getByTestId('api-connector-create').click();
    const form = page.getByTestId('api-connector-form');
    await expect(form).toBeVisible();
    await page.getByTestId('api-connector-form-name').fill(name);
    await page.getByTestId('api-connector-form-base_url').fill(OUTBOUND_BASE);
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
 * Add a route via the config modal, dry-run it against its local fixture BEFORE
 * saving (the config-modal "Testa" works in create mode), then Save — which
 * persists the config + runs the final test that promotes it and detects the
 * endpoint type. Returns the route-row base testid.
 * `withIdParam` adds an `id` (path/llm) parameter for a detail route.
 */
async function addRouteAndTest(
    page: Page,
    connectorId: string,
    opts: { name: string; url: string; expectedType: 'list' | 'detail'; withIdParam?: boolean; exampleArgs?: string },
): Promise<string> {
    const statusSelector = `[data-testid^="api-connector-${connectorId}-route-"][data-testid$="-status"]`;
    const statusRows = page.locator(statusSelector);
    const knownStatusIds = new Set(
        await statusRows.evaluateAll((elements) =>
            elements
                .map((element) => element.getAttribute('data-testid'))
                .filter((id): id is string => id !== null),
        ),
    );

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
    if (opts.exampleArgs) {
        await page.getByTestId('api-route-form-example-args').fill(opts.exampleArgs);
    }

    // Testa BEFORE saving — a real dry-run of the in-memory config (create mode).
    await page.getByTestId('api-route-form-test').click();
    const result = page.getByTestId('api-route-form-test-result');
    await expect(result).toBeVisible({ timeout: 20_000 });
    await expect(result).toHaveAttribute('data-ok', 'true');
    await expect(page.getByTestId('api-route-form-test-endpoint-type')).toHaveAttribute(
        'data-endpoint-type',
        opts.expectedType,
        { timeout: 15_000 },
    );

    // Save persists the config + runs the final test (promotes it, detects type).
    await page.getByTestId('api-route-form-submit').click();
    await expect(page.getByTestId('toast-api-route-created')).toBeVisible({ timeout: 15_000 });

    await expect(statusRows).toHaveCount(knownStatusIds.size + 1, { timeout: 15_000 });
    const createdStatusId = (
        await statusRows.evaluateAll((elements) =>
            elements
                .map((element) => element.getAttribute('data-testid'))
                .filter((id): id is string => id !== null),
        )
    ).find((id) => !knownStatusIds.has(id));
    if (createdStatusId === undefined) {
        throw new Error('The saved API route did not render a distinct status row.');
    }
    const base = createdStatusId.replace('-status', '');
    // The auto-detected endpoint type shows as a chip on the route row.
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

        // Add a route pointing at the app's own deterministic JSON list fixture.
        await page.getByTestId(`api-connector-${connectorId}-route-add`).click();
        const routeForm = page.getByTestId('api-route-form');
        await expect(routeForm).toBeVisible();
        await page.getByTestId('api-route-form-name').fill('List users');
        await page.getByTestId('api-route-form-http_method').selectOption('GET');
        await page.getByTestId('api-route-form-url').fill(LIST_FIXTURE);
        // Mode is locked to `tool`; ingest/both are Fase 2 (the select is disabled).
        const mode = page.getByTestId('api-route-form-mode');
        await expect(mode).toBeDisabled();
        await expect(mode).toHaveValue('tool');

        // Testa the config BEFORE saving (dry-run against JSON), then Save —
        // which persists + runs the final test that promotes draft→tested.
        await page.getByTestId('api-route-form-test').click();
        const result = page.getByTestId('api-route-form-test-result');
        await expect(result).toBeVisible({ timeout: 20_000 });
        await expect(result).toHaveAttribute('data-ok', 'true');
        await page.getByTestId('api-route-form-submit').click();
        await expect(page.getByTestId('toast-api-route-created')).toBeVisible({ timeout: 15_000 });

        // The route row is now `tested` (the Save-time final test promoted it).
        const routeRow = page
            .locator(`[data-testid^="api-connector-${connectorId}-route-"][data-testid$="-status"]`)
            .first();
        await expect(routeRow).toBeVisible();
        const statusId = (await routeRow.getAttribute('data-testid'))!; // api-connector-{c}-route-{r}-status
        const base = statusId.replace('-status', ''); // api-connector-{c}-route-{r}
        await expect(page.getByTestId(`${base}-status`)).toHaveAttribute('data-status', 'tested', {
            timeout: 15_000,
        });

        // Activate it.
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
        // Options render as "{name} ({slug})"; Playwright's selectOption label is an
        // exact string (no regex), so select by the value read from the matching option.
        const listRoute = page.getByTestId('api-route-relation-form-list_route');
        await listRoute.selectOption((await listRoute.locator('option', { hasText: 'List users' }).getAttribute('value'))!);
        const detailRoute = page.getByTestId('api-route-relation-form-detail_route');
        await detailRoute.selectOption((await detailRoute.locator('option', { hasText: 'User detail' }).getAttribute('value'))!);
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

    // Config modal (spec items 1-6): configure a paged route, dry-run it against
    // the local fixture (create mode, no save-first), read the response, and
    // detect the cursor pagination deterministically into the config, then save.
    // All against the local paged fixture (R13; SSRF relaxed). The AI-driven
    // "Configura con AI" is intentionally NOT clicked (external provider, R13);
    // its no-AI "Rileva" detect is covered instead.
    test('config modal — test, read response and detect pagination in create mode', async ({ page }) => {
        await createConnector(page, 'E2E Config API');
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

        // Testa the in-memory config BEFORE saving — `q` flavours the fixture names.
        await page.getByTestId('api-route-form-example-args').fill('{"q":"boot"}');
        await page.getByTestId('api-route-form-test').click();
        const testRes = page.getByTestId('api-route-form-test-result');
        await expect(testRes).toBeVisible({ timeout: 20_000 });
        await expect(testRes).toHaveAttribute('data-ok', 'true');
        await expect(page.getByTestId('api-route-form-test-endpoint-type')).toHaveAttribute('data-endpoint-type', 'list');
        // The response body is shown; it carries the searched flavour.
        await expect(page.getByTestId('api-route-form-response')).toContainText('match boot');

        // Deterministic cursor detection from meta.next_cursor → into the config.
        await page.getByTestId('api-route-form-pagination-detect').click();
        await expect(page.getByTestId('api-route-form-pagination-source')).toBeVisible({ timeout: 20_000 });
        await expect(page.getByTestId('api-route-form-pagination-type')).toHaveValue('cursor');
        await expect(page.getByTestId('api-route-form-pagination-next_cursor_path')).toHaveValue('meta.next_cursor');

        // Save the whole config (incl. the detected pagination) → created + final test.
        await page.getByTestId('api-route-form-submit').click();
        await expect(page.getByTestId('toast-api-route-created')).toBeVisible({ timeout: 15_000 });
    });
});
