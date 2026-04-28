---
name: branching-strategy-feature-vx
description: For AskMyDocs (which has v3 stable on main), each major release works in its own `feature/v4.x` integration branch. Sub-branches per sottotask target `feature/v4.x`, not main. Merge to main happens ONCE per major release when RC complete + CI green + acceptance criteria passed. For new repos (padosoft/*) PRs target main directly. Trigger when creating a feature branch on AskMyDocs, when opening a PR on AskMyDocs, when planning a new release cycle, or when user asks about branching/merging strategy.
---

# Branching strategy — `feature/v4.x` integration branches → main

## Rule

For **AskMyDocs** (`lopadova/AskMyDocs`):

```
main ← stable production release (v3 today, v4.0 next, v4.1 after, ...)
  │
  ├── feature/v4.0  ← integration branch for entire v4.0 cycle (~8 weeks)
  │     ├── feature/v4.0/W1.B   → PR target: feature/v4.0
  │     ├── feature/v4.0/W1.C   → PR target: feature/v4.0
  │     ├── feature/v4.0/W1.D   → PR target: feature/v4.0
  │     ├── feature/v4.0/W2.A   → PR target: feature/v4.0
  │     └── ...
  │     [when all sub-branches merged + CI green on feature/v4.0 + RC accepted]
  │     → PR feature/v4.0 → main
  │     → tag v4.0.0 on main
  │
  ├── feature/v4.1  ← next integration branch (after v4.0 → main merged)
  │     ├── feature/v4.1/track-b/W11.1   → PR target: feature/v4.1
  │     └── ...
  │     → PR feature/v4.1 → main → tag v4.1.0
  │
  └── feature/v4.2 ...
```

**Merge to main happens ONCE per major release**, NEVER per sub-task.

For **new repos** (`padosoft/agent-llm`, `padosoft/laravel-flow`, etc.,
created fresh for v4): PRs target `main` directly — no stable code to
preserve; main is develop is release from day 1.

## Why this exists

- `main` must keep v3 stable for production hotfixes during the 6-month v4 development
- Half-merged v4 features on `main` would break v3 production users who pull main
- Single merge per release = single review surface, single deploy event, clean release notes
- v4.x is a major version bump (architectural change), not a minor patch

Lorenzo decided this on 2026-04-28 during W1.B PR #78 review:

> "feature/v4.0, feature/v4.1, etc... e si aprono pr verso questi rami
>  e quando finita una feature/v4.x si mergia nel main"

## Operational checklist

### Starting a sub-task in v4.x cycle

```bash
# 1. Make sure feature/v4.x integration branch exists
git fetch origin
git checkout feature/v4.0   # or feature/v4.1, etc.
git pull origin feature/v4.0

# 2. Create sub-branch for the sottotask
git checkout -b feature/v4.0/W1.C
# (note the slash convention: feature/<release>/<sottotask-id>)

# 3. Work + commit + push as usual
git push -u origin feature/v4.0/W1.C
```

### Opening a PR for a sub-task

```bash
# Target the integration branch, NOT main
gh pr create \
  --base feature/v4.0 \
  --head feature/v4.0/W1.C \
  --title "feat(v4.0/W1.C): tenant_id migration" \
  --body-file .github/PULL_REQUEST_TEMPLATE.md \
  --reviewer copilot
```

⚠️ **NEVER** open a sub-task PR with `--base main`. If you do by mistake:
```bash
gh pr edit <PR-number> --base feature/v4.0
```

### Closing a major release (e.g., v4.0 RC complete)

```bash
# 1. Verify all acceptance criteria passed (sezione 25 design doc)
# 2. Verify CI green on feature/v4.0
git checkout feature/v4.0
git pull origin feature/v4.0
gh pr checks <last-PR>  # all green

# 3. Open release PR feature/v4.0 → main
gh pr create \
  --base main \
  --head feature/v4.0 \
  --title "release(v4.0): Foundation + agentic platform RC1" \
  --reviewer copilot

# 4. After review + merge:
git checkout main
git pull origin main
git tag -a v4.0.0 -m "Release v4.0.0: Foundation + agentic platform"
git push origin v4.0.0
```

## Sub-branch naming convention

`feature/<release>/<sottotask-id>`

Examples:
- `feature/v4.0/W1.B`            — week 1 sottotask B
- `feature/v4.0/W1.C-tenant-id`  — descriptive suffix optional
- `feature/v4.1/track-b/W13.1`   — track sub-grouping for v4.1
- `feature/v4.2/A.W21.1-cart-agent`

## Anti-patterns

- ❌ Open sub-task PR `--base main` — pollutes main with intermediate state
- ❌ Merge sub-branch direct to main without going through `feature/v4.x`
- ❌ Force-push to `feature/v4.x` (others have sub-branches based on it)
- ❌ Long-living "develop" branch that mirrors main — `feature/v4.x` IS develop for that release
- ❌ Forget to delete sub-branches after merge (clutters repo)

## When this rule does NOT apply

- **New repos created for v4** (`padosoft/agent-llm`, `padosoft/laravel-flow`,
  `padosoft/eval-harness`, `padosoft/laravel-pii-redactor`, `padosoft/askmydocs-pro`):
  no stable code yet, PRs target `main` directly, no integration branch needed.

- **Hotfix on v3 stable** while v4.x is in flight: branch from `main`,
  PR target `main`, deploy to production, then cherry-pick to `feature/v4.x`
  if relevant.

- **Trivial single-commit doc fix on AskMyDocs**: still PR target `feature/v4.x`
  if v4 is in development; trivial fixes accumulate too if dropped.

## Reference

- `CLAUDE.md` rule R37 (codified)
- This skill (operational detail)
- `notes/lessons/v4.0.W1.B-lesson.md` (first instance, what triggered this)
