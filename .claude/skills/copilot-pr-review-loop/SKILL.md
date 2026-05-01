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

## The 9-step flow (canonical, applies to EVERY PR on EVERY repo)

```
┌──────────────────────────────────────────────────────────────────┐
│ 1. fine task — implementation complete                            │
│ 2. test tutti verdi in locale                                     │
│    (phpunit + vitest + playwright + architecture)                 │
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
1. `[.statusCheckRollup[] | .conclusion] | unique == ["SUCCESS"]` — CI green.
2. Either (a) `reviewDecision == "APPROVED"` from the formal bot, OR (b) every must-fix from the formal bot has been addressed in commits since the review's `submitted_at`.
3. The most recent `Copilot` user-bot issue comment (after the latest push) does NOT contain new must-fix issues. Look for explicit verdict tokens: "Ready to merge", "All fixes verified", "No new issues found", or the corresponding negative tokens "Found N issues", "Must fix", "Blocking".

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
```bash
vendor/bin/phpunit --no-coverage     # all tests must pass
cd frontend && npm test               # vitest must pass
npm run e2e                           # playwright must pass
vendor/bin/phpunit --testsuite Architecture  # R30+R31+R32+R34+R35
```

### Phase E — Commit + push
```bash
git add <changed files>
git commit -m "fix(...): address Copilot review on PR #<N> + CI green"
git push origin <branch>
```

Then GOTO Phase B. Never stop after a single push.

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
- ❌ Run only phpunit and skip vitest / playwright / architecture
- ❌ Wait less than 60s after push before checking CI (CI may not have started)
- ❌ **Poll only `copilot-pull-request-reviewer[bot]` and ignore `Copilot` (User type Bot)** — the formal bot fires once at PR open and almost never re-reviews; the conversational `Copilot` bot is the one that posts "Ready to merge" / "Re-review complete" verdicts in issue-thread comments after every `@copilot review` mention. Missing it means waiting indefinitely on a phantom review that already happened. (Discovered on `padosoft/laravel-patent-box-tracker` PR #2 cycle 2 on 2026-05-01: Copilot user-bot had posted the explicit "Ready to merge" verdict 50+ minutes earlier; the agent sat idle because the polling loop filtered for the wrong login.)
- ❌ Treat absence of new formal `pull-request reviews` after a push as "Copilot is silent / timeout" — the user-bot may have already posted the verdict in `/issues/<N>/comments`. Check that channel before declaring timeout.

## Operational tip — CI iteration time budget

Each CI run is ~2-5 minutes. Plan accordingly:
- Push 1: typical 5-10 Copilot comments + maybe CI red
- Push 2 (after fixes): 1-3 residual comments + CI usually green
- Push 3+: rare; if you reach push 4 without convergence, the issue is
  deeper than a quick fix — ask for human review.

## Reference

- `EXECUTION_PROTOCOL.md` Phase 4-5-6 (in private workspace)
- `CLAUDE.md` rule R36 (Copilot review loop)
- Lessons in `notes/lessons/v4.0.W1.B-lesson.md` (first instance)
