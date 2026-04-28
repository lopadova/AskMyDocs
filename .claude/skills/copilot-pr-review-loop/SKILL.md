---
name: copilot-pr-review-loop
description: After EVERY commit-push-PR cycle, the agent MUST loop on Copilot review + CI status until ALL Copilot comments resolved AND ALL CI checks green. NEVER stop after a single push. Trigger when opening a PR with `gh pr create`, after `git push` to a PR branch, or when user asks to "fix PR" / "address review" / "make CI green". Applies to ALL repos lopadova/* and padosoft/*. The loop is mandatory for current and future sessions and for any developer working on this codebase.
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
│ 3. apri PR with --reviewer copilot   ← MANDATORY FLAG             │
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

**KEY POINT (2026-04-29 reinforcement):** Step 3's `--reviewer copilot` flag and step 5's wait-for-Copilot-review are **NOT optional**. CI green alone is **not enough** — Copilot review (or explicit absence of must-fix comments) is the second gate. Skipping step 5 ("CI green, merge now") is a protocol violation, even on docs-only PRs.

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
│   - All checks status COMPLETED + conclusion SUCCESS     │
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
  --reviewer copilot
```

Note: `--reviewer copilot` may fail with "could not resolve user". In
that case, the repo must have Copilot Code Review enabled at:
`Settings → General → Pull Requests → Allow GitHub Copilot to review`.
Ask the user to enable it once per repo (one-time manual setup).

### Phase B — Read review (after 60-180s wait)
```bash
# overview
gh pr view <PR> --json state,reviewDecision,mergeable,statusCheckRollup

# top-level comments
gh pr view <PR> --comments

# inline review comments (specific lines)
gh api repos/<owner>/<repo>/pulls/<PR>/comments --jq '.[] | {body, path, line}'
```

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
