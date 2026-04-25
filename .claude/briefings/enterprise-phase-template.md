# Enterprise phase briefing template

Reusable skeleton for the NEXT enterprise-project phase. Every phase
agent in the AskMyDocs enhancement series (PRs #16 → #31) used a
briefing of this shape; PR16 distilled them into one reusable
template so Phase 1 of the next project is pre-wired against R1..R21.

Copy this file → `docs/<next-project>-plan/PR-NN-phase-X.md`, fill
the `<<<...>>>` placeholders, hand to the agent. The agent gets a
pre-briefed, rule-aware Phase 1 on day one.

---

## Environment boilerplate

- **Worktree**: `<<<absolute-path-to-worktree>>>` on branch
  `<<<feature/enh-X-slug>>>`.
- **Base for PR**: `<<<parent branch or origin/main>>>`.
- **Shell for PHP on Windows**: **PowerShell only**. PHP commands via
  PowerShell `& php ...` or `vendor/bin/phpunit` (the `php.bat →
  php84` shim lives in the user-memory entry `env_php_shim.md`).
- **E2E gate**: `bash scripts/verify-e2e-real-data.sh` must stay OK
  through every commit.
- **Catalogue gate**: `bash scripts/verify-copilot-catalogue.sh` must
  stay OK through every commit.
- **Stash warning**: any stash on the worktree is historical; don't
  touch.

## Scope definition — what this phase IS

<<<one paragraph describing the phase's goal. Concrete files, concrete
routes, concrete migrations. Not aspirational.>>>

## Out of scope — what this phase IS NOT

List explicitly; the agent should stop if it finds itself about to
touch any of these:

- <<<new-AI-provider integration>>>
- <<<breaking migration on an existing column>>>
- <<<reshaping a shipped API contract>>>
- <<<new-dependency additions without an ADR>>>
- <<<anything in `app/` or `routes/` that PR16 said was off-limits>>>

## Rule checklist — R1..R21 compliance

Before every commit, verify:

- [ ] **R1** — every new `source_path` / `path` consumer calls
      `App\Support\KbPath::normalize()`. No inline `trim()` +
      `str_replace('\\', '/', ...)`.
- [ ] **R2** — every query on `KnowledgeDocument` that runs under
      `--force` / retention uses `withTrashed()` / `onlyTrashed()`.
      Read-path queries stay default-scoped.
- [ ] **R3** — every sweep ≥ 500 rows uses `chunkById()` /
      `cursor()` and pushes filters into SQL. Long `whereIn` lists
      split with `array_chunk(…, 1000)`. No custom `orderBy` before
      `chunkById`. No N+1 inside the chunk walker.
- [ ] **R4** — every `Storage::put/delete/copy`, `mkdir`,
      `file_put_contents`, `copy`, `rename` has its return value
      checked, or the call is wrapped in a method that throws on
      failure. No `@`-silenced calls.
- [ ] **R5** — any `action.yml` edit keeps `jq --rawfile` (not
      `--arg`) for large files, lock-step full-sync vs diff patterns,
      `git diff --diff-filter=AMR` for ingest, `DR` for delete.
- [ ] **R6** — new / renamed env var lives in `.env.example` +
      `config/*.php` + README in the SAME diff.
- [ ] **R7** — no `@`-prefixed filesystem calls; no `0777`
      (directories are `0755`, files `0644`).
- [ ] **R8** — any disk walker honours `KB_PATH_PREFIX`. If a CLI
      accepts an absolute path, it rejects-or-resolves-relative-to
      the prefix explicitly.
- [ ] **R9** — every column / env / flag / route / filename quoted
      in a doc was copied from the source of truth this diff
      modifies. Block comments atop modified components match the
      current surface. Docblocks match method bodies. PROGRESS.md
      filenames `test -f` clean.
- [ ] **R10** — queries use canonical scopes (`canonical()`,
      `accepted()`, `raw()`, `byType()`, `bySlug()`) — no bare
      `where('is_canonical', …)`. Audit rows stamped with POST-edit
      identifiers. Every `kb_edges` insert carries `project_key`.
- [ ] **R11** — every new actionable element has a kebab-case
      `data-testid`; every async region publishes
      `data-state` ∈ `{idle,loading,ready,empty,error}` (no custom
      values); pagination carries `*-pagination-prev/next` testids.
- [ ] **R12** — every PR that touches `frontend/src/` ships a
      `frontend/e2e/*.spec.ts` with ≥ 1 happy + ≥ 1 failure path.
- [ ] **R13** — `bash scripts/verify-e2e-real-data.sh` is 0. Every
      `page.route` / `context.route` call targets an external
      boundary OR carries `R13: failure injection` marker.
- [ ] **R14** — no endpoint answers 200 with empty / null / NaN in
      an error branch. Status code chosen by exception TYPE, not
      message prefix. `Math.max(...arr)` guards `arr.length === 0`.
- [ ] **R15** — every new input has `<label htmlFor>` / `aria-label`
      (placeholder is NOT a label). No `display:none` on real
      inputs. Role/state on the focusable element. Tooltips respond
      to focus/blur. Icon-only buttons have `aria-label`.
- [ ] **R16** — every test's BODY matches its NAME. Ordering tests
      use strictly-monotonic fixtures AND strict comparisons. Tests
      that mutate global state (env, DI, `window.location`,
      `Date.now`) restore it in `afterEach` / `tearDown`.
      Failure-path tests actually fire the failure.
- [ ] **R17** — React effects that re-read server state sync any
      imperative cache (EditorView, canvas) in the SAME branch.
      Optimistic updates stay until refetch resolves. `NaN` is
      guarded pre-equality. `.map()` of multi-element rows wraps in
      `<Fragment key>`, NOT `<>` with key on an inner child.
- [ ] **R18** — UI filters derive from the DB / API, not a
      hard-coded subset. Time-windows accept what the cache key
      encodes. File-extension stripping covers every accepted
      extension (`.md` AND `.markdown`). Seeders grant intent-based
      access, not cross-product fan-in.
- [ ] **R19** — LIKE escapes `%` + `_` + `\\` with explicit
      `ESCAPE '\\'`. `fnmatch` passes `FNM_PATHNAME` on paths. Regex
      literals escape `.` or use `grep -Fq`. CSV env vars go through
      `array_filter(array_map('trim', explode(',', $raw)))`.
- [ ] **R20** — FE call-site shape matches BE FormRequest shape.
      TanStack parent routes render `<Outlet />`. Artisan wrappers
      distinguish positional from option. Implicit bindings that
      need trashed rows declare `->withTrashed()`.
- [ ] **R21** — every `lockForUpdate()` read + `update()` write
      lives inside the SAME `DB::transaction` closure. Single-use
      tokens / nonces / rate counters have DB-level `UNIQUE` (or
      partial-unique) backing. Concurrency-sensitive services ship a
      concurrent-access regression test.

## Commit cadence

- **Atomic** — one commit per logical group; prefer 8–12 commits per
  phase over one mega-commit.
- **Test gate after each commit** — `vendor/bin/phpunit` + `npm test`
  + `bash scripts/verify-e2e-real-data.sh` + `bash
  scripts/verify-copilot-catalogue.sh` must all stay green. If a
  commit breaks any of them, fix before moving on.
- **Commit-message shape** — Conventional Commits with a phase
  prefix: `feat(enh-X): short title`, `test(enh-X): ...`,
  `docs(enh-X): ...`. Body explains WHY not WHAT.
- **Fix commits** — when a Copilot review lands, the fix commit
  subject follows the protocol shape: `fix(enh-X): address Copilot
  review on PR #N (M findings)`. The same commit touches
  `docs/enhancement-plan/COPILOT-FINDINGS.md` with one row per
  finding + the fix SHA. `verify-copilot-catalogue.sh` enforces this.

## Pre-push routine (opt-in)

- **Run the anticipator** — `@copilot-review-anticipator` on the
  branch diff vs `origin/main`. Every finding it reports is a
  Copilot finding waiting to happen; fix before push.
- **Re-run the gates** — `phpunit`, `vitest`, `playwright --list`,
  `verify-e2e-real-data.sh`, `verify-copilot-catalogue.sh`.
- **Commit the fixes separately** from the feature commits.

## Final step — `gh pr create`

```
gh pr create \
  --base <<<parent branch or main>>> \
  --head <<<feature/enh-X-slug>>> \
  --title "feat(enh-X): <<<short title>>>" \
  --body "$(cat <<'EOF'
## Summary
<<<1–3 bullets on what the PR delivers>>>

## Scope
<<<files touched / migrations added / routes added>>>

## Out of scope
<<<what's deliberately NOT here>>>

## Test plan
- [ ] PHPUnit — <<<before → after counts>>>
- [ ] Vitest — <<<before → after>>>
- [ ] Playwright — <<<scenarios added>>>
- [ ] R13 gate — `bash scripts/verify-e2e-real-data.sh` → OK
- [ ] Catalogue gate — `bash scripts/verify-copilot-catalogue.sh` → OK

## R1..R21 compliance
<<<either "All 21 rules applicable to this phase pass" or a list of
the rules triggered by the diff with a line on how each was
satisfied>>>

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

## What to do when Copilot review lands

1. Harvest: `gh api repos/<org>/<repo>/pulls/<N>/comments --paginate`.
2. Write each finding into `COPILOT-FINDINGS.md` under `### PR #N`.
3. Fix each finding. Commit as a single
   `fix(enh-X): address Copilot review on PR #N (M findings)` commit.
4. Update the catalogue row's `Fix SHA` column.
5. Re-run gates.
6. Push. Copilot will ACK on the fix commit.

## What NOT to do

- **Never force-push `main`**. Always push your branch; PR merges
  are human-gated.
- **Never skip hooks** (`--no-verify` / `--no-gpg-sign`) unless the
  user explicitly requested it.
- **Never amend a commit** that already pushed. Create a new commit.
- **Never ship a rule-breaking diff "because it's tiny"** — the
  whole point of R1..R21 is "tiny" is the reason things silently
  drift.
- **Never invent a new `data-state` value** (see R11 + the
  `frontend-testid-conventions` skill's `data-state` extension).
- **Never add a "just-in-case" dependency** — R6 + R9 mean every
  new dep must land with docs + example + coverage in the same PR.
- **Never demote a `security`-tagged finding to an anecdote.** R21
  mints a rule on one occurrence because the blast radius is
  RCE-class.

## References

- `CLAUDE.md` — project brief + R1..R21 in full.
- `.github/copilot-instructions.md` — R1..R21 mirror for Copilot.
- `.claude/skills/*` — one skill per rule, with fix templates.
- `.claude/agents/copilot-review-anticipator.md` — pre-push review.
- `docs/enhancement-plan/COPILOT-FINDINGS.md` — catalogue (append
  rows on every fix commit).
- `docs/enhancement-plan/LESSONS.md` — pass-through between agents.
- `docs/enhancement-plan/PROGRESS.md` — tracker (keep in sync).
- `scripts/verify-e2e-real-data.sh` — R13 CI gate.
- `scripts/verify-copilot-catalogue.sh` — catalogue integrity gate.
