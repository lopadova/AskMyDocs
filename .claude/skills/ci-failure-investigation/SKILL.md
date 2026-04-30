---
name: ci-failure-investigation
description: When `gh pr checks` shows a Playwright (or any E2E) job red, NEVER guess fixes from the test name alone. Pull the failed-job log, the playwright-report.zip artefact, and the inline Laravel log dump BEFORE editing code — false-iteration cycles cost 4–8 min each plus a misleading next-iteration baseline. Trigger when a CI job has failed and the next step is "fix the failure", or when investigating Playwright/E2E timeouts, 500s, hangs, or flakes on a PR. Mirrors CLAUDE.md §R22.
---

# CI failure investigation (R22)

## Rule

When `gh pr checks <PR>` shows Playwright (or any E2E suite) red, NEVER
guess at fixes from the test name alone. The cost of a wrong commit is
one CI cycle (4–8 min) AND a misleading next-iteration baseline (the new
failure is now "different", but you don't know why). Always pull the
full failure context first.

## Operational checklist

1. **Failed-job log** — first stop, always:
   ```bash
   gh pr checks <PR>
   # copy the failed job ID from the URL, then:
   gh run view --job <id> --log-failed
   ```
   Look for the `✘` lines, the spec:line that failed, and the error
   excerpt. This already tells you 60% of the time which cluster of tests
   share a root cause.

2. **Playwright HTML report artefact** — `tests.yml` uploads
   `playwright-report/` on failure (retention 7d). Download via:
   - GitHub UI: PR → Checks → failed job → Artifacts → `playwright-report.zip`
   - Or CLI: `gh run download <run-id> --name playwright-report --dir /tmp/pr-report`

   Inside the zip, `data/<hash>.md` files are the **error contexts** for
   each failed test. They contain:
   - The locator that timed out / failed
   - The stack trace with line numbers
   - The page snapshot URL
   - The screenshot path (`test-results/.../test-failed-1.png`)

   Read these BEFORE diffing code — the snapshot often shows the page
   in a state that explains the failure (e.g. an unresolved spinner, an
   error banner, or a stale modal).

3. **Laravel log tail** — the workflow's "Dump Laravel log on failure"
   step prints the last 200 lines of `storage/logs/laravel.log` inline
   in the failed-job log (search for `=== storage/logs/laravel.log`).
   Read it before assuming the failure is FE-only — a 500 from
   `/api/admin/...` will surface as a Playwright "element not visible"
   while the actual stack trace lives in laravel.log.

4. **Diagnostic throws in tests** — when a non-2xx response masks itself
   as a generic timeout, add a temporary `waitForResponse` + throw so
   the next CI run prints the real status + JSON body in the failed-job
   log:
   ```ts
   const respPromise = page.waitForResponse(
       (r) => /\/api\/admin\/kb\/documents\/\d+\/raw/.test(r.url())
           && r.request().method() === 'PATCH',
       { timeout: 15_000 },
   );
   await save.click();
   const resp = await respPromise;
   if (!resp.ok()) {
       throw new Error(`PATCH /raw returned non-OK: ${resp.status()} ${await resp.text()}`);
   }
   ```
   PR #33 caught the DemoSeeder frontmatter regression this way: the
   "toast not visible" timeout was actually a 422 with
   `{"errors":{"frontmatter":{"slug":["Missing required field 'slug'."]}}}`.
   Without the throw, the timeout was indistinguishable from a slow
   render. Leave the throws in until the test goes green; they're
   documentation by another name.

## Anti-patterns

- **Guessing from the test name** — "test X timed out, must be flaky,
  bump the timeout" — don't. Always artefact-first.
- **Re-running CI hoping for a different result** — if you don't have a
  hypothesis grounded in artefact data, you're burning runner minutes
  on noise.
- **Editing the test instead of the code** — if a Playwright test fails
  consistently in CI but passes locally, the difference is usually data
  (DemoSeeder), env (Postgres vs SQLite), or queue (database vs sync).
  The test is correct; the seeder/config drift is the bug.
- **Removing diagnostic throws too early** — keep them in for the full
  red→green CI cycle. Remove them in a polish commit only after the
  fix is verified green.
- **Chasing process management when the request is too heavy** — see
  the `auth.setup` ECONNREFUSED rabbit hole below.

## The `auth.setup` ECONNREFUSED rabbit hole (PR #83 lesson)

Symptom seen across multiple PRs:

```
✘ [setup] auth.setup.ts: authenticate as admin
  Error: /testing/reset failed after 16 attempts:
  apiRequestContext.post: connect ECONNREFUSED 127.0.0.1:8000
```

### Wrong rabbit hole (what NOT to do)

The `php artisan serve` log shows the server bound on :8000 and
answered `/healthz` in 500 ms, then went silent. Tempting next moves:

- `setsid -f` to detach artisan-serve from the runner step's session
- `lsof -ti tcp:8000 -sTCP:LISTEN` to find the listener PID
- PGID-based `kill -- -$PGID` cleanup with belt-and-braces lsof sweep
- `nohup`, `disown`, `bash -c '...'` wrapping

PR #83 went through ~7 commits of these variants without converging.
None of them fix the underlying issue, because the underlying issue
is NOT a process-management problem.

### Right diagnosis (the structural fix in PR #85)

PHP's built-in dev server (`php artisan serve` / `php -S`) has a
**single-threaded accept loop per worker**. When one HTTP handler
runs a multi-second blocking operation (`migrate:fresh`, large
seeder, asset compilation, image generation), every subsequent
connection on that worker stalls / refuses. With
`PHP_CLI_SERVER_WORKERS=4` you have 4 such loops, but the FIRST heavy
request often arrives before workers fan out and you stall worker
zero anyway. `--no-reload` doesn't fix the heavy-work-in-HTTP problem
on its own (the worker still blocks on the long handler) — it IS,
however, required separately so Laravel's ServeCommand actually honors
the `PHP_CLI_SERVER_WORKERS` env var when Playwright manages the
server, so keep it on the `php artisan serve` invocation regardless.
Detaching from the runner session does not help either. The server is
doing its job — it's blocked on the work the test asked it to do.

The fix is to stop asking the dev server to do that work:

1. Move `migrate:fresh` to a CLI step BEFORE Playwright starts.
   Note: the example below deliberately does NOT run
   `php artisan key:generate --force` — `APP_KEY` is already written
   into `.env` by the earlier "Prepare .env for testing" step, and
   running `key:generate` here would append a duplicate `APP_KEY=`
   line and leave it ambiguous which value `php` ends up reading
   (the workflow on PR #85 hit this exact regression and removed it
   in a follow-up). Seeding is intentionally NOT bundled here either —
   it stays on the HTTP path via `/testing/seed` because it's small
   and per-scenario seeders need to switch dynamically (e.g.
   `EmptyAdminSeeder` after `DemoSeeder`).
   ```yaml
   - name: Migrate test database (CLI)
     env: { APP_ENV: testing }
     run: |
       php artisan migrate:fresh --force      # ← was `migrate --force`
   ```
2. Tell the test setup to skip the HTTP `/testing/reset` call when
   the workflow already did the migration:
   ```ts
   const SKIP_HTTP_RESET = process.env.E2E_SKIP_HTTP_RESET === '1';
   if (!SKIP_HTTP_RESET) {
       await postWithRetry(page, '/testing/reset');
   }
   await postWithRetry(page, '/testing/seed', { seeder });
   ```
3. Set the env var on the Playwright step:
   ```yaml
   - name: Run Playwright (chromium)
     env:
       E2E_SKIP_HTTP_RESET: '1'
     run: npm run e2e
   ```

The diff is ~58 lines across two files. The remaining HTTP traffic
(login, seed, normal pages) is light enough that the dev server
handles it without breaking a sweat.

### Trigger conditions for the structural fix

If ALL FOUR are true, the move-work-to-CLI fix is the right answer
(don't spelunk artisan-serve internals):

- The failing endpoint runs `migrate:fresh`, big seeders, or any
  multi-second blocking artisan command inside one HTTP request.
- The endpoint is hit ONCE at suite startup (not per-test).
- The work is idempotent (CLI can run it once per workflow start).
- The test environment is disposable (CI runner / local docker, not
  shared with humans).

### What to ask BEFORE editing process flags

1. "What is the failing endpoint actually DOING under the hood?"
   `grep -rn '/testing/reset' app/Http/` and read the controller.
2. "Could that work happen via CLI BEFORE the web server starts
   handling traffic?" If yes, that is your fix.
3. Only if the answer is "no, the work has to happen inside the
   request lifecycle" should you start looking at process detachment
   knobs — and even then, prefer FrankenPHP / RoadRunner / php-fpm
   over making `php artisan serve` more reliable.

### Cross-references

- Memory: `feedback_heavy_work_belongs_in_cli_not_http.md` (private).
- CLAUDE.md: the in-repo rule mirrors this section.
- Worked example: PR #85 commit `e2c87c29`. Read its diff before
  attempting any future ECONNREFUSED fix on this codebase.
- Anti-pattern: PR #83 commits `6071b81 → 4cde177` (7 process-
  management iterations without converging). Skim if you feel
  tempted to detach a child process from a bash session in CI.

## When this rule kicks in

- Any time you see `gh pr checks <PR>` with a non-zero exit and a fail
  line on a Playwright/E2E job.
- When investigating Playwright timeouts, locator-not-visible errors,
  500s, or "passes locally but fails in CI" reports.
- Before opening a "fix CI" commit — read the artefacts, confirm the
  hypothesis, THEN edit code.

## Why this exists

PR #33 spent multiple hours iterating on the wrong fixes (queue=sync
fixups, optimize+retry experiments, re-hover hacks) because the early
iterations skipped artefact analysis. Once we pulled the
`playwright-report.zip` and the diagnostic throws were in place, the
4 remaining failures resolved into 2 clusters with one targeted commit:
DemoSeeder frontmatter (missing `slug:`, invalid `type: policy`) +
chat re-hover that detached the popover. 30 min of artefact reading
saved 2+ hours of false-positive CI cycles.
