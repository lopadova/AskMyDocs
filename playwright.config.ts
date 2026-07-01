import { defineConfig, devices } from '@playwright/test';

/*
 * Playwright configuration for AskMyDocs SPA E2E.
 *
 * Tests live under frontend/e2e/. The `setup` project signs in once,
 * writes the storage state to playwright/.auth/admin.json, and all
 * subsequent projects reuse it — no per-test login.
 *
 * CI runs with APP_ENV=testing so the TestingController endpoints are
 * reachable (/testing/reset + /testing/seed). Local dev can run the
 * same pipeline with `APP_ENV=testing npm run e2e`.
 *
 * Copilot #2/#3 fix: `webServer` boots `php artisan serve` automatically
 * so `npm run e2e` is self-contained in CI and locally. Set
 * `E2E_SKIP_WEBSERVER=1` when an external server is already running.
 *
 * PR6 Phase F1 — added the `viewer-setup` + `chromium-viewer` projects
 * so the admin-dashboard RBAC scenarios can run as a non-admin without
 * trampling the admin storage state.
 */
const baseURL = process.env.E2E_BASE_URL ?? 'http://127.0.0.1:8000';
const skipWebServer = process.env.E2E_SKIP_WEBSERVER === '1';

export default defineConfig({
    testDir: './frontend/e2e',
    fullyParallel: true,
    forbidOnly: !!process.env.CI,
    /*
     * v4.0/W3.2 — Consolidate visual-regression snapshots under a
     * single `frontend/e2e/__visual__/` tree (PLAN-W3 §7.4).
     *
     * Default Playwright behaviour places snapshots beside each spec
     * in `<spec>-snapshots/` folders, which scatters golden images
     * across the repo and makes bulk-update / grep operations awkward.
     * Channelling every `toHaveScreenshot()` call through this
     * template gives a deterministic, easy-to-audit location:
     *
     *   frontend/e2e/__visual__/<spec-relative-path>/<test-name>-<projectName>-<platform>.png
     *
     * Rationale (Lorenzo, 2026-04-30): "easier to grep + bulk-update"
     * once the W3.2 swap commit captures the FE rewrite baseline.
     * Pixel-perfect comparisons (maxDiffPixels: 0) live alongside the
     * snapshots — see `chat-visual.spec.ts` for the 15 representative
     * states the FE-rewrite gate compares against.
     *
     * Supported since Playwright 1.28; the project pins ^1.59.
     */
    snapshotPathTemplate: '{testDir}/__visual__/{testFilePath}/{arg}-{projectName}-{platform}{ext}',
    // CI gets ONE retry (v8.24): the suite is stable, but a small set of
    // streaming / admin-kb-edit specs flake intermittently under the
    // single-worker CI job (timing on SSE + CodeMirror), which forced a
    // manual `gh run rerun --failed` on nearly every PR's final gate.
    // retries:1 lets Playwright auto-recover a genuine flake in-run while
    // still surfacing a real regression (which fails twice). We stay at 1
    // (not 2) to bound worst-case runner time on the single-worker job.
    // Local stays 0 for fast, honest feedback.
    retries: process.env.CI ? 1 : 0,
    workers: process.env.CI ? 1 : undefined,
    // Per-test timeout. Default is 30s; tighter so a stuck test
    // (e.g., page.goto blocking on a slow CI server response) fails
    // before it costs serious wall-clock budget.
    timeout: 20_000,
    reporter: [['list'], ['html', { open: 'never' }]],
    use: {
        baseURL,
        trace: 'on-first-retry',
        video: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    webServer: skipWebServer
        ? undefined
        : {
              // `--no-reload` is required to honour PHP_CLI_SERVER_WORKERS
              // — without it Laravel's `ServeCommand` silently drops the
              // env var and runs single-threaded again. The handling
              // sits at the top of `ServeCommand::handle()` (search for
              // `PHP_CLI_SERVER_WORKERS` in vendor/laravel/framework's
              // `Illuminate/Foundation/Console/ServeCommand.php`); we
              // intentionally don't pin a line number because the file
              // drifts across patch / minor framework upgrades. PR #82
              // set the env var without the flag, so the workers
              // configuration was never actually applied.
              command: 'php artisan serve --no-reload --host=127.0.0.1 --port=8000',
              // `/healthz` returns a plain 200 with no auth / no DB hit.
              // The previous `baseURL` poll on `/` was hitting the home
              // route (auth middleware → 302 to /login) which CI's webServer
              // probe interpreted as not-ready and timed out after 120s.
              // `/healthz` is the unambiguous green signal.
              url: `${baseURL}/healthz`,
              reuseExistingServer: !process.env.CI,
              timeout: 120_000,
              env: {
                  APP_ENV: 'testing',
                  // SAFETY (local-only — CI sets E2E_SKIP_WEBSERVER=1 and skips this
                  // whole block): point the dev-spawned `php artisan serve` at a
                  // DEDICATED test database, NEVER the dev DB from `.env`
                  // (DB_DATABASE=askmydoc). `/testing/reset` runs `migrate:fresh`,
                  // which DROPS every table — without this override a local
                  // `playwright test` would wipe the developer's data. Matches the
                  // CI test DB name so migrations behave identically. Create it once:
                  // `createdb askmydocs_test` (see .env.example). The TestingController
                  DB_DATABASE: 'askmydocs_test',
                  // drives the real /messages/stream SSE through the real
                  // @ai-sdk transport. The fake provider streams a canned
                  // answer + a constant embedding vector (so retrieval always
                  // returns the ingested chunk → the real `source-url` citation
                  // frame is exercised). No external LLM call, no API key.
                  AI_PROVIDER: 'fake',
                  AI_EMBEDDINGS_PROVIDER: 'fake',
                  // v8.8.3 — anonymous chat ON for E2E so the happy-path
                  // spec (anonymous-chat.spec.ts) exercises the real
                  // stateless /api/kb/chat turn end-to-end. The OFF /
                  // 422-reject state is covered by KbChatAnonymousTest
                  // (phpunit) + the AnonymousChatView Vitest disabled
                  // landing, per R43 (both states tested).
                  KB_ANONYMOUS_CHAT_ENABLED: 'true',
                  // v8.13/P11 — light up the Evidence & Risk Review admin
                  // surface so its happy-path spec exercises the real enabled
                  // dashboards against seeded review rows (R13). The default-OFF
                  // "unavailable" landing (flag off) is covered by the
                  // EvidenceRiskReviewView Vitest (R43 both states).
                  EVIDENCE_RISK_REVIEW_ADMIN_ENABLED: 'true',
                  // v8.16/W4 — light up the AI FinOps admin SPA so its
                  // happy-path spec (admin-ai-finops.spec.ts) reaches the real
                  // package-served Blade SPA shell under /admin/ai-finops and
                  // proves the viewAiFinOps gate (admin allowed, viewer 403).
                  // The default-OFF clean-404 landing (flag off) is covered by
                  // FinOpsDisabledTest (phpunit), per R43 (both states tested).
                  AI_FINOPS_ADMIN_ENABLED: 'true',
                  // v8.19/W3 — light up the AI Guardrails admin SPA so
                  // admin-ai-guardrails.spec.ts reaches the real package-served
                  // Blade shell under /admin/ai-guardrails and proves the
                  // viewAiGuardrails gate (admin allowed, viewer 403). Default-OFF
                  // clean-404 is covered by GuardrailsAdminMountingTest (phpunit), R43.
                  AI_GUARDRAILS_ADMIN_ENABLED: 'true',
                  // v8.x — light up the Invitations admin SPA so
                  // admin-invitations.spec.ts reaches the real package-served
                  // Blade panel under /admin/invitations and proves the
                  // manageInvitations gate (admin allowed; viewer 403 on the
                  // package mount URL, not just the env=false 404). The
                  // default-OFF clean-404 landing is covered by
                  // InvitationsAdminDisabledTest (phpunit), per R43 (both states).
                  INVITATIONS_ADMIN_ENABLED: 'true',
                  // v8.17 — OFFLINE IMAP seam so connectors-imap-super-admin.spec.ts
                  // can drive the real credential-connector flow end-to-end (the IMAP
                  // server is a BACKEND TCP dependency Playwright can't stub). The fake
                  // ping is input-driven: host containing `invalid`/`fail` → 422,
                  // otherwise → ACTIVE. Default-OFF in production.
                  CONNECTOR_IMAP_FAKE_PING: 'true',
                  // v8.18/W4 — gamification stays ON (default) so the badges +
                  // coaching surfaces render, but the AI NARRATION layer is forced
                  // OFF for E2E: the narrator resolves the named `openrouter`
                  // provider directly (not the `fake` default), so an enabled
                  // narrate/regenerate would make a real OpenRouter HTTP call with a
                  // 120s timeout and flake CI (R13/R38). With it off the deterministic
                  // copy is used — the on-path the admin-gamification spec exercises.
                  // The AI-ON path is covered by GamificationInsightsTest (phpunit,
                  // mocked AiManager), per R43.
                  KB_GAMIFICATION_AI_ENABLED: 'false',
                  // PHP_CLI_SERVER_WORKERS spawns N worker children for
                  // the PHP built-in dev server (PHP 7.4+). Without
                  // this env var (AND `--no-reload` above so the var
                  // is actually honoured by ServeCommand), `php artisan
                  // serve` falls back to its default single-process /
                  // single-accept-loop mode and stalls during a long
                  // migrate:fresh request, causing every concurrent /
                  // immediately-following request to ECONNREFUSED for
                  // ≥12s — the root of the recurring auth.setup flake.
                  // With both knobs set the server runs four worker
                  // children in parallel — enough headroom for
                  // healthz + reset + seed + login to land at the
                  // same time.
                  PHP_CLI_SERVER_WORKERS: '4',
              },
              stdout: 'pipe',
              stderr: 'pipe',
          },
    projects: [
        // Setup projects are chained sequentially via `dependencies` so
        // they don't all hammer /testing/reset (migrate:fresh on real
        // Postgres) at the same instant. Even with
        // PHP_CLI_SERVER_WORKERS=4 + --no-reload (see webServer.env
        // above) three parallel migrate:fresh requests
        // queue + sometimes lock the server long enough for downstream
        // requests to ECONNREFUSED. Chaining keeps the API surface
        // exercised one-at-a-time during boot.
        { name: 'setup', testMatch: /auth\.setup\.ts/ },
        { name: 'viewer-setup', testMatch: /viewer\.setup\.ts/, dependencies: ['setup'] },
        // PR13 / Phase H2 — super-admin setup. Seeds super@demo.local
        // (DemoSeeder) and persists storage state so the
        // chromium-super-admin project can exercise destructive
        // maintenance commands behind the `commands.destructive`
        // permission — which the admin role alone doesn't hold.
        {
            name: 'super-admin-setup',
            testMatch: /super-admin\.setup\.ts/,
            dependencies: ['viewer-setup'],
        },
        {
            name: 'chromium',
            use: {
                ...devices['Desktop Chrome'],
                storageState: 'playwright/.auth/admin.json',
            },
            dependencies: ['setup'],
            // Every *-viewer.spec.ts file runs under the viewer storage
            // state; every *-super-admin.spec.ts under the super-admin
            // one. Keep the ignore list a single regex so new RBAC
            // denial / elevation specs don't need this config touched.
            testIgnore: [/.*\.setup\.ts/, /.*-viewer\.spec\.ts/, /.*-super-admin\.spec\.ts/, /role-access\.spec\.ts/],
        },
        {
            // Non-admin project — runs ONLY the *-viewer scenarios.
            // Uses a separate storage state so the admin cookie from
            // `auth.setup.ts` does not leak in.
            name: 'chromium-viewer',
            use: {
                ...devices['Desktop Chrome'],
                storageState: 'playwright/.auth/viewer.json',
            },
            dependencies: ['viewer-setup'],
            testMatch: /.*-viewer\.spec\.ts/,
        },
        {
            // PR13 / Phase H2 — super-admin project. Scoped ONLY to
            // *-super-admin.spec.ts specs so destructive command
            // flows don't leak into the admin project's scope.
            name: 'chromium-super-admin',
            use: {
                ...devices['Desktop Chrome'],
                storageState: 'playwright/.auth/super-admin.json',
            },
            dependencies: ['super-admin-setup'],
            testMatch: /.*-super-admin\.spec\.ts/,
        },
        {
            // R32 — per-role access-control gate. Runs role-access.spec.ts in a
            // CLEAN (unauthenticated) context: the spec logs in as each of the
            // five roles inline + asserts the API allow-set, plus a guest case.
            // No pre-auth storage state may leak in. Depends on `setup` only to
            // serialise the boot-time DB migrate; the spec's own beforeEach
            // reseeds via resetAndSeed.
            name: 'chromium-role-access',
            use: {
                ...devices['Desktop Chrome'],
                storageState: { cookies: [], origins: [] },
            },
            dependencies: ['setup'],
            testMatch: /role-access\.spec\.ts/,
        },
    ],
});
