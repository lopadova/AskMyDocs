---
name: rc-tag-per-week-milestone
description: At the end of every Wn weekly milestone on AskMyDocs `feature/vX.Y` (CI green + closure status doc shipped), tag `vX.Y.0-rcN` on the integration branch with a refreshed README + CHANGELOG. Final `vX.Y.0` GA fires only when the last Wn of the cycle closes (W8 for v4.0) and `feature/vX.Y` merges into `main` per R37. Trigger after merging the LAST sub-task PR of any Wn closure on AskMyDocs, or when the user asks "should we tag rc?" / "release candidate" / "milestone tag".
---

# RC tag per Wn milestone — MANDATORY at every closure

## Rule

After every Wn weekly milestone closes on AskMyDocs `feature/vX.Y`:

1. Verify the closure pre-conditions (every sub-task merged + CI green + closure status doc shipped).
2. Open a small docs PR refreshing `README.md` (Features at a glance + Roadmap) and `CHANGELOG.md` (new `## [vX.Y.0-rcN] - {date}` heading).
3. After the docs PR merges, tag `vX.Y.0-rcN` on the integration branch HEAD via `gh release create`.

The convention bridges R37 ("merge to main once per major") and Lorenzo's standing requirement for milestone visibility every week. Standing rule from 2026-05-02.

## When to apply

Trigger this skill at the end of every Wn cycle on AskMyDocs:

- **W4** of v4.0 → tag `v4.0.0-rc1` (after `STATUS-2026-05-01-week4.md` lands)
- **W5** of v4.0 → tag `v4.0.0-rc2` (after `STATUS-{date}-week5.md` lands)
- **W6** → `v4.0.0-rc3`
- **W7** → `v4.0.0-rc4`
- **W8** → final `v4.0.0` GA + merge `feature/v4.0` → `main` per R37 (no `-rc` suffix on the final).

Then the next major (v4.1) cycle starts — same rule applies, rc1 after the first Wn closure.

## Pre-conditions (verify ALL before tagging)

| Pre-condition | How to verify |
|---|---|
| Every sub-task PR of Wn merged into `feature/vX.Y` | `gh pr list --repo lopadova/AskMyDocs --state open --base feature/vX.Y` returns empty |
| CI green on integration HEAD | `gh run list --repo lopadova/AskMyDocs --branch feature/vX.Y --limit 1 --json conclusion --jq '.[0].conclusion'` returns `"success"` |
| Closure status doc shipped | `git ls-tree origin/feature/vX.Y -- docs/v4-platform/STATUS-{date}-week{N}.md` returns the file |
| README "Features at a glance" + "Roadmap" reflect the new milestone | Read sections + diff against the previous rc tag |
| CHANGELOG.md has the Wn entries | grep for the Wn-related commits in `## [Unreleased]` |

If any check fails, ship the docs PR first; tag after merge.

## Procedure

### 1. Open the docs PR

```bash
cd "C:/Users/lopad/Documents/DocLore/Visual Basic/Ai/AskMyDocs"
git checkout feature/vX.Y && git pull
git checkout -b feature/vX.Y-Wn-rc-readme

# Edit README.md — Features at a glance + Roadmap sections
# Edit CHANGELOG.md — promote the [Unreleased] block to [vX.Y.0-rcN] - {date},
# add a fresh empty [Unreleased] block above

git add README.md CHANGELOG.md
git commit -m "docs(vX.Y/Wn): refresh README + CHANGELOG for vX.Y.0-rcN milestone"
git push -u origin feature/vX.Y-Wn-rc-readme

gh pr create --base feature/vX.Y --head feature/vX.Y-Wn-rc-readme \
  --reviewer copilot-pull-request-reviewer \
  --title "docs(vX.Y/Wn): refresh README + CHANGELOG for vX.Y.0-rcN milestone" \
  --body "..."
```

R36 loop applies — wait for CI green + Copilot 0 outstanding must-fix before merging.

### 2. Tag the rc

After the docs PR merges:

```bash
git checkout feature/vX.Y && git pull

gh release create vX.Y.0-rcN \
  --repo lopadova/AskMyDocs \
  --target feature/vX.Y \
  --title "vX.Y.0-rcN — Wn milestone" \
  --prerelease \
  --notes "$(cat <<'EOF'
{title}

## What ships in this RC

[bullet list of capabilities from W1..Wn — pull from CHANGELOG.md]

## Still pending in v{X.Y}

[bullet list of W{n+1}..Wlast roadmap items]

## How to install

```bash
composer require lopadova/askmydocs:^X.Y.0-rcN
```

This is a release candidate. Stable channel remains v{X-1}.x until v{X.Y}.0 GA at the end of W{last}.

## Verifying the integrity

The rc tag is signed via the GitHub Actions tag protection policy. Verify with `gh release view vX.Y.0-rcN`.
EOF
)"
```

### 3. Update the project memory

After tagging, update `MEMORY.md` index with the freshly-tagged rc + record the merge SHA in the Wn closure status doc.

## Why prerelease + on integration branch (not main)

- **Composer / Packagist semver**: `^X.Y` resolution **skips** RC builds by default. Stable consumers stay on the previous major. Opt-in via `^X.Y@beta` or `^X.Y.0-rcN` for the milestone preview.
- **AskMyDocs `main` hosts v3 stable production**: until W8 ships and the final `vX.Y.0` GA goes through R37's once-per-major merge, main must NOT receive the integration branch.
- **Audit + community visibility**: each rc is a public checkpoint that demonstrates progress without committing to a final API contract. Patent Box auditors get a clean per-week artefact.
- **Rollback target**: if Wn+1 breaks something, `vX.Y.0-rcN` is the known-good fallback.

## Anti-patterns

- ❌ Tagging the rc on `main` — rejected by R37.
- ❌ Skipping the README + CHANGELOG refresh — leaves consumers staring at a stale "Roadmap" claiming the freshly-shipped feature is still pending.
- ❌ Tagging mid-Wn (between sub-task merges) — wait for the closure status doc to land.
- ❌ Re-tagging the same `rcN` after subsequent commits — bump to `rcN+1` instead.
- ❌ Applying this rule to standalone `padosoft/*` packages — those follow plain SemVer (W4 already established `padosoft/laravel-patent-box-tracker` v0.1.0 final at the end of its Wn cycle, no RC chain).

## Scope

- **In scope**: `lopadova/AskMyDocs` integration-branch cycles (`feature/vX.Y`).
- **Out of scope**: Standalone `padosoft/*` packages — they tag plain `v0.1.0` / `v0.1.1` / `v0.2.0` per normal SemVer at the end of their respective Wn.

## Related

- **R36** — Copilot review + CI green loop (the pre-condition check for the docs PR).
- **R37** — Branching strategy: `feature/vX.Y` integration branch + once-per-major merge to main. R39 lives ON TOP of R37 (it does NOT modify the merge-to-main rule; it adds a release-candidate tag on the integration branch in parallel).
- **`feedback_rc_tag_per_week_milestone.md`** — the project-memory copy of this convention.
