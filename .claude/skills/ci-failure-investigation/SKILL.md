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
