# STATUS — AskMyDocs v8.39.0 GA — Auto-Wiki maintenance as a delegated routine (W5)

**Cycle:** v8.39 (Auto-Wiki maintenance as a routine — fifth and last of the five Document
Intelligence workstreams planned at v8.36; the cycle's own W6 "vision" item is deferred, see
below).
**Closed:** 2026-09-26. **GA tag:** `v8.39.0` (merge `feature/v8.39 → main`, R37 per-release).
**Origin:** the fifth workstream of
[`PLAN-v8.36-document-intelligence-and-llm-wiki-export.md`](PLAN-v8.36-document-intelligence-and-llm-wiki-export.md)
§W5. Design: [ADR 0033](../adr/0033-v839-auto-wiki-maintenance-as-a-delegated-routine.md).

## Single PR, not a sub-task split (deviation from W1-W4's pattern, explained)

Unlike v8.36-v8.38 — each split into multiple sub-task PRs merged into a `feature/vX.Y`
integration branch before a separate closure/GA PR — v8.39 has exactly one workstream (W5),
so the whole cycle (implementation + review-loop fixes + this closure doc) ships as a single
PR directly against `main`: **#512**, `feature/v8.39 → main`. There is no second sub-task to
justify a separate integration branch, so this closure step folds into the same PR instead of
opening a distinct one, as noted in the PR body itself when it was opened.

## What shipped

`kb:wiki-maintain`'s existing nightly cron (index rebuild + lint + backfill) gains an
OPTIONAL routine identity via `padosoft/laravel-routines` v1.2.0 (+ `-contracts`), a real
agentic-routine engine (schedule, dispatch, mandate/consent, budget, escalation) this
platform now also uses for delegated background work generally, not only wiki maintenance.
Default **OFF** end to end (`KB_WIKI_ROUTINE_ENABLED`, R43, both states tested):

- **`WikiMaintenanceRoutineTarget implements RoutineTarget`** — a thin adapter over the
  UNCHANGED `WikiMaintainer` core, declaring exactly the two action classes
  (`kb.wiki.compile`, `kb.wiki.lint`) it has always had.
- **`WikiRoutineService`** — the ONE core behind the tri-surface: status, run, and
  auto-provisioning a `Routine` row on first use. The package's generic `organization_id`
  column holds this app's `tenant_id` (the engine has no tenant concept — R30 scoping is
  entirely this service's job).
- **`WikiMaintenanceRoutineGate`** — the "no double run, no orphaned run" scheduler decision:
  keep the legacy cron slot registered (the safe default) unless the flag is on, the adapter
  is registered, AND an active routine exists **for the tenant the legacy cron slot itself
  services** (see the tenant-scoping fix below). Wrapped in `try/catch`, failing safe to
  "keep the legacy cron" on any database error, since `withSchedule()`'s closure runs on
  every console boot, not only `schedule:run`.
- **The package's own generic admin API is turned OFF** (`ROUTINES_API_ENABLED=false`) — it
  has no tenant concept and defaults to cross-tenant read access for any authenticated user
  with no policy defined. AskMyDocs' own tenant-scoped, RBAC-gated tri-surface is the only
  sanctioned surface.
- **The package's own webhook ingress is turned OFF too** (`ROUTINES_WEBHOOKS_ENABLED=false`
  — see the review-loop fix below).
- **Tri-surface (R44):** `kb:wiki-routine {status|run}` · `GET /api/admin/kb/wiki-routine` +
  `POST .../run` (`role:admin|super-admin`) · MCP `KbWikiRoutineStatusTool` (read-only — no
  MCP `run` tool, documented R44 exception, same posture as W1's OCR re-run and W3's review
  approval).
- **Documented gap (ADR 0033 §7):** no mandate/consent-grant flow is wired this cycle — the
  routine runs as the application, the same authority the cron already had. The
  `MandateExceeded`/pause-and-ask machinery is real and wired through, but nothing this cycle
  can trigger it; a future cycle needs to build the step-up consent UI to actually exercise
  it.

## Correction to the plan's own framing (ADR 0033 §2)

Once the real `padosoft/laravel-routines` package was installed and its source read, ADR
0033 corrects the plan's characterization of the dependency as "optional": every
`padosoft/*` package in this repo is a `require` Composer dependency (verified against the
existing `composer.json` block), never conditionally absent. "Optional" in this codebase
means the *feature* an operator may not enable (`KB_WIKI_ROUTINE_ENABLED=false`), not the
*package* an installation may not have. Both `padosoft/laravel-routines-contracts` and
`padosoft/laravel-routines` are `require` entries accordingly.

## Independent review (Copilot/Codex unavailable, R36 fallback) — twice

Neither GitHub Copilot Code Review nor a `@codex review` fallback comment responded across
this PR's review window (Copilot: no response ~35 min after an explicit
`request_copilot_review` call; Codex: no response ~15 min after the fallback comment). Per
R36's "always-on local gate," an independent subagent review (Agent tool, general-purpose,
given only the PR diff — no context from the implementing session) carried the pre-merge
safety net. It found **two genuine must-fix issues and one should-fix**, all fixed in a
follow-up commit (`4fe727c7`) and re-verified by a second, narrower subagent review before
merge:

1. **Cross-tenant gate leak (must-fix).** `WikiMaintenanceRoutineGate::cronSlotShouldStayActive()`
   checked for ANY active routine of the target type, with no tenant scoping. The legacy
   `kb_wiki_maintain` cron slot only ever maintains tenant `default`
   (`KbWikiMaintainCommand`'s own `--tenant=default`), so any OTHER tenant turning the
   routine on for itself would silently disable the shared cron slot `default` still depends
   on — no error, no log line naming the loss. Fixed by scoping the `exists()` check to
   `organization_id = 'default'` (a new `LEGACY_CRON_TENANT_ID` constant), with a regression
   test proving an active routine for a DIFFERENT tenant does NOT disable the cron (the
   original test asserted the opposite, using `acme` as the tenant that disables it — fixed
   to use `default` for the real disable case, and a new test added for the different-tenant
   non-disable case).
2. **TOCTOU race in routine provisioning (must-fix).** `WikiRoutineService::ensureRoutine()`
   was a bare find-then-create with no unique database constraint on `(target_type,
   organization_id)` and no lock — two concurrent `run()` calls for the same tenant (a
   double-click on "Run now," or a concurrent HTTP+CLI trigger) could both provision a
   duplicate `Routine` row, producing duplicate cron-fired maintenance runs from then on.
   Fixed by wrapping the find-then-create in `Cache::lock(provisionLockKey($tenantId),
   15)->block(10, ...)`. Proved genuine mutual exclusion (not merely sequential idempotency,
   which a bare double-call test would trivially pass even without any lock) by holding the
   exact same lock externally in a test and asserting `run()` throws
   `LockTimeoutException` — mirroring `App\Support\Kb\SourceKeyLock`'s established test
   convention.
3. **Unreasoned webhook attack surface (should-fix).** `padosoft/laravel-routines`' own
   signed webhook ingress (`hooks/routines/{id}`, HMAC-per-routine) mounts by the package's
   own default, even though nothing this app creates is a `trigger_kind=webhook` routine.
   The route is soundly built (HMAC-SHA256, constant-time compare, replay window,
   anti-enumeration 404), so this was not exploitable, but it was unreasoned public surface
   for zero functional benefit. Fixed by flipping `config/routines.php`'s
   `webhooks.enabled` default to `false` (`ROUTINES_WEBHOOKS_ENABLED`), with both-states
   tests (R43) proving the route is genuinely absent by default and genuinely mounts when an
   operator re-enables it.

ADR 0033 §4/§5/§6 were updated in the same fix commit to match the corrected code, so the
design doc never drifts from what actually shipped (R9).

## New schema

No new tables of this app's own — `padosoft/laravel-routines`' `routines` and
`routine_runs` tables (its own migration, mirrored verbatim into
`tests/database/migrations/` per this repo's convention for third-party package schema) are
reused with the package's own generic `organization_id` column repurposed to hold this app's
`tenant_id`. Unlike `padosoft/laravel-invitations` (where the package itself is R30/R31-aware
across its own 9 tables), `laravel-routines` has no tenant concept at all — every scoping
guarantee for the wiki-maintenance routine specifically is enforced entirely by
`WikiRoutineService` and `WikiMaintenanceRoutineGate`, not by the package.

## Defaults / cost posture

- `KB_WIKI_ROUTINE_ENABLED` default **OFF** (R43, both states tested) — with it off,
  `kb:wiki-maintain`'s scheduler entry is byte-identical to before this cycle.
- `ROUTINES_API_ENABLED` default **OFF** — the package's own generic admin API has no tenant
  boundary; this app's own tri-surface is the only sanctioned way to read or trigger a
  routine.
- `ROUTINES_WEBHOOKS_ENABLED` default **OFF** (new this cycle, review-loop fix) — no
  `trigger_kind=webhook` routine exists yet to justify exposing the ingress.

## Deferred (documented, per ADR 0033 §7)

- **The mandate/consent-grant step-up flow.** The `MandateExceeded`/pause-and-ask machinery
  the engine provides is real and wired through end to end, but nothing this cycle grants a
  mandate or can trigger an escalation. The routine runs today with exactly the same
  authority the unattended cron already had (compile + lint only, never touching a
  `human`-tier page). A future cycle that wants the full "ask a human when the routine
  exceeds its mandate" experience needs to build the step-up consent UI first — genuinely
  separate scope.
- **W6 ("vision" column of the original Tabular Review, v8.40).** Per the original plan, W6
  is explicitly optional/deferred rather than a committed workstream. This closure does not
  implement it; a separate, lightweight decision checkpoint (promote it into a real v8.40
  cycle, or close the Document Intelligence arc at W5) follows as its own step.

## Tags — blocked by tooling, operator follow-up required

Same blocker documented at v8.36, v8.37 and v8.38 closure: this session's git credential can
push branch commits and merge PRs, but `git push` of a **tag** ref returns `403` (confirmed
reproducible, unchanged since v8.36). `v8.36.0`, `v8.37.0` and `v8.38.0` remain untagged
despite all three cycles being fully merged to `main`. An operator with a tag-capable
credential should run, once the v8.39 → `main` GA merge (below) lands:

```bash
# v8.36.0 — already on main at the PR #492 merge commit
git tag -a v8.36.0 62dd0b25 -m "v8.36.0 — Document intelligence W1+W2 (OCR ingestion, conversion artifacts). See docs/v4-platform/STATUS-2026-09-16-v836-w1-w2-closure.md."
git push origin v8.36.0

# v8.37.0 — at the GA merge commit on main (PR #499)
git tag -a v8.37.0 ebc12f22 -m "v8.37.0 — Digitization Review (W3). See docs/v4-platform/STATUS-2026-09-20-v837-w3-closure.md."
git push origin v8.37.0

# v8.38.0 — at the GA merge commit on main (PR #511)
git tag -a v8.38.0 1f27af95 -m "v8.38.0 — Portable Wiki Export + candidate-only import (W4). See docs/v4-platform/STATUS-2026-09-25-v838-w4-closure.md."
git push origin v8.38.0

# v8.39.0 — at the GA merge commit on main (fill in the actual SHA once merged)
git tag -a v8.39.0 <GA-merge-sha-on-main> -m "v8.39.0 — Auto-Wiki maintenance as a delegated routine (W5). See docs/v4-platform/STATUS-2026-09-26-v839-w5-closure.md."
git push origin v8.39.0
```

Per R39, an rc tag would normally precede the GA tag; given all four are already blocked by
the same credential scope and the code is stable (full CI green — 4939 tests, 22189
assertions, 0 failures — plus two rounds of independent subagent review clean), this closure
skips straight to proposing the GA tag rather than adding a redundant rc step an operator
would have to push twice.

Cycle plan: [`PLAN-v8.36-document-intelligence-and-llm-wiki-export.md`](PLAN-v8.36-document-intelligence-and-llm-wiki-export.md) §W5.
Design: [ADR 0033](../adr/0033-v839-auto-wiki-maintenance-as-a-delegated-routine.md).
