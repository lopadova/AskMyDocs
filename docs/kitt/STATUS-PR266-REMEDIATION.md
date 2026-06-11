# PR #266 — KITT widget remediation: status & merge handoff

> **Branch:** `feature/kitt-host-tools-foundation` → **base:** `main`
> **State:** ✅ all CI green · ✅ R40 local critic clean · ⏸️ **NOT merged** (left for human merge decision)
> **HEAD at close:** `cf1f1f14`
> **Date:** 2026-06-11
> Plan executed: [`REMEDIATION-PLAN-PR266.md`](./REMEDIATION-PLAN-PR266.md)

All **44 review findings** from `/code-review max pr266` were fixed across 8
phases, each committed + pushed with rigorous tests (PHPUnit unit + feature,
Vitest for the widget runtime / admin SPA, and Playwright E2E for the
user-visible security behaviour). Environment was moved to **PHP 8.5** (Herd
php85 + recompiled composer) per the request — everything below is tested on
PHP 8.5.7.

## Phase commits (all pushed)

| Phase | Commit | Findings closed |
|---|---|---|
| 0 — CI/deploy unblock | `c75a011` | #5 bundle build, #6 R13 gate (FakeProvider scripted tool-calls) |
| 1 — core features | `82b9b59` | #1 chunk-array 500, #4 refusal gate, #7 provider tools, #8 host-tools turn-2, #15 empty-tools 400 |
| 2 — session-token | `961494d` | #10 mode-dead, #11 origin bypass, #12/#13 token burn, #14 plaintext-at-rest |
| 3 — security boundary | `1cb75fb` | #2 action gating, #3 snapshot PII, #9 open-redirect, #23 size cap, #44 demo key |
| 4 — correctness | `548881c` | #16 depth cap, #17 timeout, #18 dup-label 422, #19 exec-tool cap, #20 dup step, #21 skill, #22 idempotency, #24 CORS, #37 isVisible, #38 wait_for |
| 5 — perf/observability | `eb81b37` | #25 buildMessages, #26 last_used throttle, #27 admin list, #28 index, #29 token prune, #30 chat_logs |
| 6 — admin SPA/docs | `22272ac` | #31 role gate, #32 mutation errors, #33 status filter, #34 cmd name, #35 mode note, #36 dead state |
| 7 — hygiene | `5e4fb80` | #39 binary test file, #40 .DS_Store, #41 env drift, #42 PII detectors, #43 Observer note |

## R40 local critic (copilot-cli) — converged in 2 rounds

The cloud Copilot reviewer **could not review this PR** — it returned *"exceeds
the maximum number of lines (20,000)"* (the PR is ~24k lines). So the R40
copilot-cli local critic (which read the full diff in chunks) is the **review of
record**. It found and fixed:

- **Round 1** (`0b7932dd`): 2 must-fix + 4 nits — the big one was a **regression I
  introduced**: `peekKey()` (the #12 pre-consume rate-limit lookup) matched any
  token hash, so replaying an expired/consumed token incremented the key's
  rate-limit bucket → DoS. Fixed to filter `expires_at > now() AND consumed_at
  IS NULL`. Also strengthened the #11/#13 R21 tests to assert `consumed_at` stays
  NULL (the actual burn-not-happening invariant), gated the system prompt on
  `hasTools` (R43 OFF-path), documented the nav + project_key decisions.
- **Round 2** (`07c726aa`): 1 must-fix + 2 nits — R12 E2E testid (not CSS
  selector), EOF newline, buildMessages step-vs-message note.

## Playwright failures — root-caused via artefacts (R22), both pre-existing

The Playwright E2E failed on 5 `*-super-admin` widget specs. The report-artefact
page snapshot showed the **login page** — i.e. NOT the remediation code (the SPA
never reached `WidgetAdminView`):

1. `8cd26be` — the 3 `*-super-admin.spec.ts` files imported raw `baseTest` from
   `@playwright/test`, **bypassing the `seeded` DemoSeeder auto-fixture**. A prior
   `admin-dashboard` test reseeds with `EmptyAdminSeeder` (which by design does
   NOT create `super@demo.local`), so the authenticated user vanished →
   `/api/auth/me` 401 → login redirect. Fix: import the fixtures `test` (the
   documented intended pattern). This cleared 4/5.
2. `cf1f1f1` — the host-tools list-row toggle is a **server-controlled checkbox**
   (no optimistic update), so `checked` doesn't flip until the PATCH + refetch;
   Playwright's `.uncheck()` requires the immediate state change and threw.
   Switched to `.click()` (the persisted OFF state is asserted after the
   response). Cleared the 5th.
   - `bb35386f` also brought the Phase-6 #31 `WidgetAdminView` tab to a reactive
     derivation (not a sticky `useState`) so a super-admin lands on Keys once
     roles load — composes with the auth fix.

## Final CI state (run `27322162706`, HEAD `cf1f1f14`)

```
PHPUnit (PHP 8.3)   pass   PHPUnit (PHP 8.4)  pass   PHPUnit (PHP 8.5)  pass
Vitest              pass   RAG regression     pass   Playwright E2E     pass
```
Local full suite at close: **2529 PHPUnit tests, 8196 assertions, OK** (2
pre-existing PHP-8.5 deprecations only) + full Vitest green + tsc clean +
`verify-e2e-real-data.sh` exit 0.

## Merge handoff — open decision

Per the AskMyDocs auto-merge convention the R36 conditions are met (CI green +
0 outstanding Copilot must-fix comments). **Not merged** by request, because:

- The **cloud Copilot review structurally could not run** (PR > 20k lines) — the
  local critic + CI are the only automated vouchers.
- It is a ~24k-line **foundation PR to `main`** that was remediated, not
  authored here; the merge is irreversible and outward-facing.

To merge: `gh pr merge 266 --merge` (preserves history) once you're comfortable.
Consider whether this foundation should target `main` directly or a
`feature/vX.Y` integration branch per R37 — and, separately, whether the PR
should be split under the 20k-line Copilot cloud-review threshold so future
pushes get the cloud reviewer back.

## Round 2 — deep enterprise review (11 findings), commit `1fb1a0e9`

A second external "deep enterprise review" of the full module surfaced 11
findings; every one was verified against the real code and fixed:

| # | Severity | Fix |
|---|---|---|
| BUG1 | 🔴 | `step()` denylist → **allowlist** status gate (BLOCKED/ERROR no longer re-enter via `resetErrors`); same contract as `execTool()`. |
| BUG2 | 🔴 | status gate moved **before** the step-cap mutation (terminal COMPLETED no longer overwritten to BLOCKED). |
| BUG3 | 🟠 | `index()` `withCount('sessions')` + single-row fallback in `serialize()` (kills N+1). |
| BUG4 | 🟡 | `bcrypt()` → `Hash::make()` in `store()`/`rotate()`. |
| BUG5 | 🟡 | `sanitizeText()` `preg_replace('/u')` null-coalesced `?? ''` (invalid-UTF-8 no longer fatal). |
| BUG6 | 🟡 | Italian P.IVA masking now **checksum-validated** (Luhn-variant) — non-VAT 11-digit codes stay readable. |
| R30 | 🟡 | `index()`/`findForTenant()` use `->forTenant()` not raw `where('tenant_id')`. |
| ARCH | 🟡 | `providerSupportsToolCalling()` config-driven (`widget.tool_calling_providers`). |
| RACE1/2 | 🟡 | documented as deliberate (soft step cap; conservative non-rolled-back rate-limit increment on the peekKey→consume TOCTOU). |

Tests added (R16): `step()`→409 for BLOCKED/ERROR/ABORTED/COMPLETED with
state-preservation asserts; COMPLETED-at-cap-not-overwritten; `sessions_count`
accuracy **and** batching (query-log: zero standalone `count(*)` on
`widget_sessions`); VAT valid-masked / non-VAT-untouched; invalid-UTF-8 no-crash.
All backend, unchanged JSON shapes → no Playwright scenario warranted.

**Gates:** full PHPUnit on PHP 8.5 = **2550 tests green** (only the 2 pre-existing
`fnmatch` deprecations). **R40 local critic converged in 2 rounds → 0 must-fix,
0 nit** (round 1 caught a real R16 gap: the "batched" test only proved accuracy →
fixed with the query-log assertion). CI run `27330526788` on `1fb1a0e9`: all six
checks pass (Playwright E2E 15m38s), `mergeState: CLEAN`. Cloud Copilot again
declined (>20k lines) — local critic + CI are the gate of record. Still **not
merged** by the standing "leave green" decision.
