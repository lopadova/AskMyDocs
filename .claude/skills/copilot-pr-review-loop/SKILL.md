---
name: copilot-pr-review-loop
description: After EVERY commit-push-PR cycle, the agent MUST loop on Copilot review + CI status until ALL Copilot comments resolved AND ALL CI checks green. NEVER stop after a single push. Trigger when opening a PR with `gh pr create`, after `gh pr push`, or when user asks to "fix PR" / "address review" / "make CI green". Applies to ALL repos lopadova/* and padosoft/*. The loop is mandatory for current and future sessions and for any developer working on this codebase.
---

# Copilot PR Review + CI Loop — MANDATORY

## Rule

**NEVER stop a sottotask after a single commit-push.** After every push, the agent
**MUST** loop on the following sequence until both conditions are satisfied:

1. Copilot review has **0 outstanding comments** (all addressed)
2. CI has **0 failing checks** (all green or expected-skipped)

## R46 — Deferred-E2E ordering (READ FIRST, supersedes the test-execution order below)

Playwright E2E (~18-20 min) is the most expensive gate and Copilot reviews the
**diff**, not E2E results — so **E2E never runs inside the test/CI/Copilot
rounds**. It runs in **two phases** (at least twice): once locally before the
PR, then in CI as the final gate after the cloud Copilot loop closes — the CI
phase legitimately re-runs on each fix-push while the `run-e2e` label is on,
plus any flake reruns. Both phases block the merge, so quality is unchanged;
only the per-round wasted E2E cycles are removed.

Per-PR order (the rest of this skill's loop applies WITHIN steps 5-6):

1. Implement.
2. **Local FAST unit gate only**: PHPUnit + Vitest (`vendor/bin/phpunit` +
   `npm test` + `npm run test:legacy`). **No Playwright.** Fix until green.
3. **Local copilot-cli loop (R40)** until `0 must-fix` — between rounds re-run
   **only** php+vite.
4. **Local Playwright** (`npm run e2e`). Fix until green. **No Copilot for
   spec-only fixes** — re-run the local copilot-cli loop (R40) if an E2E fix
   touches non-trivial app code.
5. **Open PR** → CI runs **unit-only** (the `playwright` job is gated OFF; no
   `run-e2e` label). Fix until php+vite CI green.
6. **Cloud Copilot loop** (the canonical loop below) until `0 outstanding
   must-fix` — CI each round is **php+vite only**, ~3 min not ~25.
7. **Final E2E gate**:
   `gh pr edit <N> --repo lopadova/AskMyDocs --add-label run-e2e`. The `labeled`
   event re-fires CI with the label present → the gated `playwright` job runs.
   Fix until E2E green. **No Copilot for E2E-only fixes.**
8. **Merge** when BOTH: `0` Copilot must-fix AND all CI green (incl. the
   labelled E2E run).

**md-only exception:** a diff touching only `.md` files engages **no** Copilot
(skip local copilot-cli AND the cloud loop). `.mdx` doc-site pages are NOT
covered — they ship with feature code (R45) and follow the full flow.

The `run-e2e` label must exist in the repo once: `gh label create run-e2e
--repo lopadova/AskMyDocs --color 5319e7 --description "Fire the gated
Playwright E2E job (R46 final gate)"`.

## The 9-step flow (canonical, applies to EVERY PR on EVERY repo)

```
┌──────────────────────────────────────────────────────────────────┐
│ 1. fine task — implementation complete                            │
│ 2. test tutti verdi in locale — FAST gate first                   │
│    (phpunit + vitest + architecture); Playwright runs ONCE        │
│    locally AFTER the local copilot-cli loop closes (R46 step 4)   │
│ 3. apri PR with --reviewer copilot-pull-request-reviewer          │
│    ← MANDATORY FLAG (canonical bot login; "copilot" alias only    │
│     works when Copilot Code Review is enabled in repo settings)   │
│ 4. attendi CI GitHub verde   (60-180s)                            │
│ 5. attendi Copilot review commenti  (additional 2-15 min)         │
│ 6. leggi commenti (gh pr view N --comments + inline) e fix        │
│ 7. ri-attendi CI tutta verde (after fix push)                     │
│ 8. (se Copilot ri-review) GOTO step 5                             │
│ 9. merge solo dopo:                                               │
│    - Copilot reviewDecision is APPROVED OR no must-fix outstanding│
│    - All CI checks status COMPLETED + conclusion SUCCESS          │
│      (or SKIPPED with explicit reason)                            │
└──────────────────────────────────────────────────────────────────┘
```

**KEY POINT (2026-04-29 reinforcement):** Step 3's `--reviewer copilot-pull-request-reviewer` flag and step 5's wait-for-Copilot-review are **NOT optional**. CI green alone is **not enough** — Copilot review (or explicit absence of must-fix comments) is the second gate. Skipping step 5 ("CI green, merge now") is a protocol violation, even on docs-only PRs.

## The legacy loop (kept for fix-iteration phase only)

When a PR has been opened and the FIRST review/CI cycle has surfaced issues:

```
┌─────────────────────────────────────────────────────────┐
│ A. push fix commit                                       │
│ B. wait 60-180s for Copilot re-review + CI to re-run     │
│ C. read PR review comments  (gh pr view N --comments)    │
│ D. read inline review comments (gh api .../comments)     │
│ E. read CI status            (gh pr checks N)            │
│ F. for each failing CI: read failed log                  │
│ G. fix all issues + run local test gate                  │
│ H. commit + push                                         │
│ I. GOTO step B                                           │
│                                                           │
│ EXIT only when:                                          │
│   - Copilot reviewDecision is APPROVED or no outstanding │
│     must-fix comments remain                              │
│   - All checks have status COMPLETED with conclusion     │
│     either SUCCESS or expected/justified SKIPPED         │
│     (e.g. matrix cells gated by `if:` on a feature flag) │
└─────────────────────────────────────────────────────────┘
```

## Why this exists

Failure mode this rule prevents: "Claude pushes a commit, sees CI red,
sends a status report to user, stops working." This wastes a CI cycle
and hands a half-broken state to the user. The user has explicitly
said this is unacceptable (Lorenzo, 2026-04-28):

> "stanno scoppiando le CI non passano e non le stai fixando ti stai
>  fermando. il loop è quello sopra, fatti delle rules, skills precise
>  per eseguire sempre in ogni repo queste istruzioni meticolosamente"

## Scope

Applies to **every PR** opened on:
- `lopadova/AskMyDocs`
- `padosoft/askmydocs-pro`
- `padosoft/laravel-ai-regolo`
- `padosoft/laravel-flow`
- `padosoft/eval-harness`
- `padosoft/laravel-pii-redactor`
- `padosoft/regolo-php-client` (when created)
- Any future Padosoft/Lopadova repo

Applies to **every developer** (Lorenzo, future Padosoft team members,
any AI agent).

## Exact commands per phase

### Phase A — Open PR
```bash
gh pr create \
  --title "feat(...): ..." \
  --base main \
  --head feature/<branch> \
  --body-file .github/PULL_REQUEST_TEMPLATE.md \
  --reviewer copilot-pull-request-reviewer
```

Use the canonical bot login `copilot-pull-request-reviewer` — `gh`
accepts it whether or not Copilot Code Review is enabled in the repo,
so the assignment never silently fails. The short alias
`--reviewer copilot` only resolves when Copilot Code Review is
enabled at `Settings → Copilot → Code review → "Enable for this
repository"` (the GitHub UI path may drift across versions; on older
UIs the toggle lived under `Settings → General → Pull Requests →
Allow GitHub Copilot to review`). On a fresh repo where the feature
is disabled, `gh` reports "could not resolve user" and opens the PR
with no reviewer assigned. See CLAUDE.md R36 step 3 for the full
rationale and the precise interplay between the alias and the
canonical login.

### Phase B — Read review (after 60-180s wait)

**There are TWO Copilot bots that post on a PR. Both must be polled — missing the second one means missing the verdict.**

| Bot login | Type | Posts where | Triggers when |
|---|---|---|---|
| `copilot-pull-request-reviewer[bot]` | App / Bot | `pull-request reviews` (`/pulls/<N>/reviews`) + inline review comments (`/pulls/<N>/comments`) | At PR open (automatic), and **only** at PR open — does NOT re-fire on subsequent pushes or on `@copilot review` mentions |
| `Copilot` | User type Bot | issue-thread comments (`/issues/<N>/comments`) | In response to every `@copilot review` mention. Posts the conversational summary including verdicts like "Ready to merge" or "Re-review complete. All N fixes verified correct." |

A polling loop that filters reviews on `select(.user.login == "copilot-pull-request-reviewer[bot]")` will see exactly **ONE** review (the initial automatic one) and then sit silent forever — even when `Copilot` has explicitly approved the PR via an issue comment. This was the failure mode on `padosoft/laravel-patent-box-tracker` PR #2 cycle 2 (2026-05-01).

```bash
# overview — covers reviewDecision and CI rollup
gh pr view <PR> --json state,reviewDecision,mergeable,statusCheckRollup

# top-level + issue-thread comments (HERE is where the `Copilot` user bot replies)
gh pr view <PR> --comments

# OR explicitly query the issue-thread API (the same comments)
gh api repos/<owner>/<repo>/issues/<PR>/comments --jq '.[] | {user: .user.login, created_at, body}'

# inline review comments (specific lines — `copilot-pull-request-reviewer[bot]` posts here)
gh api repos/<owner>/<repo>/pulls/<PR>/comments --jq '.[] | {body, path, line}'

# formal pull-request reviews (`copilot-pull-request-reviewer[bot]` only — NOT the user-bot)
gh api repos/<owner>/<repo>/pulls/<PR>/reviews --jq '.[] | {user: .user.login, state, submitted_at}'
```

A correct polling exit condition checks ALL of:
1. `[.statusCheckRollup[] | .conclusion] as $c | ($c | all(. != null)) and (($c | unique) - ["SUCCESS", "SKIPPED"] | length == 0)` — CI green (allow `SUCCESS` + expected `SKIPPED`, but no pending/null or failing conclusions).
2. Either (a) the formal review bot has an `APPROVED` review in `/pulls/<PR>/reviews` when filtered by `user.login == "copilot-pull-request-reviewer[bot]"`, OR (b) every must-fix from that formal bot has been addressed in commits since the review's `submitted_at`. Do not attribute PR-level `reviewDecision` to the formal bot; it is only an aggregate PR signal.
3. The most recent `Copilot` user-bot issue comment (after the latest push) does NOT contain new must-fix issues. See the verdict-token table below.

### Verdict-token recognition (criterion 3)

Programmatically classify the latest `Copilot` user-bot comment with these patterns. A single positive match (and zero negative matches) means R36 is closed for criterion 3.

| Class | Tokens / phrases (case-insensitive substring or regex) | Decision |
|---|---|---|
| ✅ POSITIVE — clean verdict | "Ready to merge" / "All N? must-fix .* verified" / "All fixes verified" / "verified as correctly addressed" / "No new issues found" / "Re-review complete" / "All N? .* addressed" / "LGTM" | merge READY |
| 🔴 NEGATIVE — new issues | "Found N? issues" / "Must fix" / "Blocking" / "Issue #" / "I noticed" / "However," + recommendation / "should be" / "needs to" + change verb | DO NOT merge — fix and loop |
| 🟡 AMBIGUOUS | comment shorter than 80 chars / no enumerated points / no clear verdict | Re-trigger `@copilot review` and wait again. Do NOT proceed. |

One-liner classifier (run this BEFORE attempting the merge):

```bash
LAST_COPILOT_COMMENT=$(gh api repos/<owner>/<repo>/issues/<PR>/comments \
  --jq '. | sort_by(.created_at) | map(select(.user.login == "Copilot" and .user.type == "Bot")) | .[-1].body // ""')

# Positive verdict regex (case-insensitive)
if echo "$LAST_COPILOT_COMMENT" | grep -qiE 'ready to merge|verified as correctly addressed|all .* verified|no new issues|re-review complete|all .* addressed|lgtm'; then
    # Now also confirm there are no negative tokens overriding the positive
    if echo "$LAST_COPILOT_COMMENT" | grep -qiE 'must fix|blocking|found [0-9]+ issue|however[, ]+'; then
        echo "AMBIGUOUS — both positive and negative tokens present; do NOT merge"
    else
        echo "READY — merge allowed"
    fi
else
    echo "NOT READY — no positive verdict yet"
fi
```

If the formal bot has not posted a re-review (which is the common case after the first push), criterion 3 is the authoritative gate. Trigger the user-bot every push:

```bash
gh api -X POST repos/<owner>/<repo>/issues/<PR>/comments \
  -f body="@copilot review"
```

Wait 30s–5min for the user-bot reply to appear in `/issues/<PR>/comments` under `user.login == "Copilot"`.

### Phase C — Read CI failures
```bash
# list runs for branch
gh run list --branch <branch> --limit 3 --json databaseId,status,conclusion,name

# read failed-job log
gh run view <run-id> --log-failed | head -200
```

### Phase D — Fix locally + test gate

During the cloud Copilot loop (R46 step 6) the gate is **php+vite + architecture
only** — Playwright is deferred to the final gate, so do NOT run `npm run e2e`
here:

```bash
vendor/bin/phpunit --no-coverage     # all tests must pass
cd frontend && npm test               # vitest must pass
vendor/bin/phpunit --testsuite Architecture  # R30+R31+R32+R34+R35
```

`npm run e2e` runs in two phases across the whole PR per R46 (at least twice):
once locally before opening the PR (step 4), and in CI as the final gate after
the label is added (step 7) — the CI phase re-runs on each fix-push while the
label is on, plus any flake reruns. It is NOT part of the per-round Copilot gate.

### Phase D-final — Add the E2E label (R46 step 7, ONCE, after cloud Copilot = 0 must-fix)
```bash
# Only after the cloud Copilot loop reports 0 outstanding must-fix AND
# php+vite CI is green. This fires the gated Playwright job via the
# `labeled` pull_request event.
gh pr edit <PR> --repo lopadova/AskMyDocs --add-label run-e2e
# then loop on the Playwright CI result (read failed-job log + artefacts
# per R22) until green — WITHOUT re-engaging Copilot for E2E-only fixes.
```

### Phase E — Commit + push
```bash
git add <changed files>
git commit -m "fix(...): address Copilot review on PR #<N> + CI green"
git push origin <branch>
```

Then GOTO Phase B. Never stop after a single push.

### Phase F — Merge (only when ALL exit conditions met)

**Precondition checklist** (ALL must be true — if even ONE fails, GOTO Phase B):

- [ ] `mergeStateStatus == "CLEAN"` and `mergeable == "MERGEABLE"` (from `gh pr view <PR> --json`).
- [ ] CI rollup criterion 1 above is satisfied (every check `COMPLETED + SUCCESS` or expected `SKIPPED`).
- [ ] At least 5 minutes have elapsed since the last push (R36 wait-window — gives the formal bot a chance to re-review even though it usually doesn't).
- [ ] Verdict-token classifier (above) returns `READY`, OR formal bot posted an explicit `APPROVED` review since the last push.

**Merge command** (squash is the canonical merge mode for AskMyDocs and all `padosoft/*` repos):

```bash
gh pr merge <PR> --repo <owner>/<repo> --squash --delete-branch
```

**Expected harness permission**: the merge command requires `Bash(gh pr merge *)` (or a more specific narrowing) to be present in the project's `.claude/settings.local.json` `permissions.allow` list. If the harness denies the merge with reasoning that mentions "5+ minutes" or "Copilot re-review not landed" while all four preconditions above are objectively satisfied, the deny is a permission-rule gap, NOT a protocol violation. (The reasoning text is templated; a harness permission rule deny prints generic R36-flavored text but the actual cause is the missing allow rule.) In that case:

1. Surface the situation to the user with the precondition evidence (CI screenshot / comment timestamps).
2. Propose adding `"Bash(gh pr merge <PR> --repo <owner>/<repo> --squash --delete-branch)"` to `settings.local.json` (narrow rule for this exact command, not a wildcard) — wait for user confirmation before editing settings.
3. After the rule is in place, retry the merge.

**Capture the merge SHA immediately after**:

```bash
MERGE_SHA=$(gh api repos/<owner>/<repo>/git/ref/heads/main --jq '.object.sha')
```

The SHA is REQUIRED for R39 (`gh release create vX.Y.Z --target $MERGE_SHA ...`). Tagging against the moving `main` ref instead of the captured SHA is a bug — another PR landing between the merge and the release silently shifts the tag.

## What counts as "Copilot must-fix"

- Bug (off-by-one, null deref, race condition)
- Security (XSS, SQLi, auth bypass, secret leak)
- R-rule violation (R30 cross-tenant, R32 memory privacy, R3 N+1, etc.)
- Test gap (untested branch, unhandled error path)

These MUST be fixed before merge.

## What counts as "should-fix"

- Code style, naming, idiom
- Documentation quality
- Minor refactoring

These SHOULD be fixed unless there's an explicit reason not to. If
declining, reply on the comment with a brief rationale.

## What counts as "discuss"

- Ambiguous suggestions where Copilot may have misunderstood context
- False positives
- Intentional design decisions

Reply explaining; mark resolved when consensus reached.

## Anti-patterns (NEVER DO)

- ❌ Push a commit, see CI red, stop and report to user
- ❌ Skip Copilot review because "it's just a small fix"
- ❌ Mark Copilot comment "resolved" without actually fixing
- ❌ Merge with even 1 outstanding Copilot must-fix comment
- ❌ Merge with CI red (any check failure)
- ❌ Run only phpunit and skip vitest / architecture in the per-round gate
  (Playwright is intentionally deferred per R46 — but vitest + architecture are NOT)
- ❌ Run Playwright inside the Copilot rounds, or add the `run-e2e` label before
  the cloud Copilot loop reaches 0 must-fix (R46 violation)
- ❌ Engage Copilot on an md-only PR, or on an E2E-only fix (R46)
- ❌ Wait less than 60s after push before checking CI (CI may not have started)
- ❌ **Poll only `copilot-pull-request-reviewer[bot]` and ignore `Copilot` (User type Bot)** — the formal bot fires once at PR open and almost never re-reviews; the conversational `Copilot` bot is the one that posts "Ready to merge" / "Re-review complete" verdicts in issue-thread comments after every `@copilot review` mention. Missing it means waiting indefinitely on a phantom review that already happened. (Discovered on `padosoft/laravel-patent-box-tracker` PR #2 cycle 2 on 2026-05-01: Copilot user-bot had posted the explicit "Ready to merge" verdict 50+ minutes earlier; the agent sat idle because the polling loop filtered for the wrong login.)
- ❌ Treat absence of new formal `pull-request reviews` after a push as "Copilot is silent / timeout" — the user-bot may have already posted the verdict in `/issues/<N>/comments`. Check that channel before declaring timeout.
- ❌ **Misread a harness permission-rule deny as a protocol violation.** When the harness rejects `gh pr merge` with reasoning mentioning "5 minutes" or "Copilot re-review not landed" while the Phase F precondition checklist is objectively satisfied (CI green + verdict-token positive + 5+ min elapsed + mergeStateStatus CLEAN), the deny is a permission-rule gap in `settings.local.json`. Do NOT push another commit "to refresh" — that compounds the wait window. Instead, surface the four preconditions with evidence to the user and propose the narrow permission rule. Discovered on `lopadova/AskMyDocs` PR #102 (2026-05-03): all four preconditions met for 55+ min, harness still denied; the issue was the missing `Bash(gh pr merge *)` allow rule.

## Operational tip — CI iteration time budget

Each CI run is ~2-5 minutes. Plan accordingly:
- Push 1: typical 5-10 Copilot comments + maybe CI red
- Push 2 (after fixes): 1-3 residual comments + CI usually green
- Push 3+: rare; if you reach push 4 without convergence, the issue is
  deeper than a quick fix — ask for human review.

## Review-provider fallback: Copilot first → Codex on out-of-budget

Standing from 2026-06-14 (Lorenzo). The cloud reviewer has a two-tier
fallback; the local subagent is the always-on safety net.

1. **Copilot is ALWAYS the first source.** Request it on PR open with
   `gh pr create --reviewer copilot-pull-request-reviewer`; re-request with
   `gh pr edit <N> --add-reviewer copilot-pull-request-reviewer` and/or a
   `@copilot review` comment. Loop per the Rule above.

2. **Detect prolonged out-of-budget.** The signature (seen across AskMyDocs
   PRs #272–#274, 2026-06-13/14):
   - the copilot-cli critic (R40) returns HTTP **402**
     `additional_spend_limit_reached`, AND
   - after requesting the cloud review, **no review fires** (empty
     `reviewRequests`, no new `reviews`, 0 inline comments) for well past the
     normal 1–7 min lag.
   That is out-of-budget, not slowness — do NOT keep waiting or merge blind.

3. **Auto-switch to the ChatGPT Codex connector.** Same loop, different bot:
   ```bash
   gh pr comment <N> --body "@codex review"
   ```
   The GitHub App **chatgpt-codex-connector**
   (https://github.com/apps/chatgpt-codex-connector) must be installed on the
   repo. It then posts a review (state `COMMENTED`) with inline findings, just
   like Copilot. **Re-trigger after every fix** by ending the fix-reply comment
   with `@codex review` on its own line (proven on
   `padosoft/scalar-openapi-doc` PR #16, ~20 rounds). Read findings → fix →
   `@codex review` → repeat until 0 must-fix.
   - Verify which login posted: reviews come from `chatgpt-codex-connector`
     (check `gh api repos/<owner>/<repo>/pulls/<N>/reviews`).
   - If `@codex review` produces no reviewer after a fair wait, the app isn't
     installed on that repo — fall through to step 4 and note it for the user.

4. **Always-on local gate — code-reviewer SUBAGENT.** Regardless of which cloud
   bot is live, run an independent `code-reviewer` subagent (Task tool) on the
   `git diff <base>...HEAD` before merge — it's fast, billing-free, and has
   caught real must-fix issues (the P2 auto-slug-squat) while Copilot budget was
   out. When BOTH cloud bots are unavailable, the subagent's verdict carries the
   merge (CI green + subagent 0 must-fix); schedule a **retroactive** cloud
   review once budget returns.

Order of authority for the cloud review: **Copilot → (out-of-budget) → Codex**;
the subagent is the local belt-and-suspenders, not a replacement for the cloud
pass when one is available. See [[feedback_review_escalation_copilot_then_codex]].

## Reference

- `EXECUTION_PROTOCOL.md` Phase 4-5-6 (in private workspace)
- `CLAUDE.md` rule R36 (Copilot review loop + Codex fallback)
- Lessons in `notes/lessons/v4.0.W1.B-lesson.md` (first instance)
