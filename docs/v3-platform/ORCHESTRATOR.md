# AskMyDocs v3.0 — Orchestrator Role & Protocol

**Purpose:** Define exactly how an Orchestrator agent (human or AI) drives v3.0 implementation through the 32 sub-tasks defined in `docs/superpowers/plans/2026-04-26-v3.0-pipeline-filters-grounding.md`, while enforcing the per-task PR+Copilot review loop and the LESSONS.md injection pattern.

This document is **the contract** between the orchestrator and the implementing sub-agents. It is also the spec that `scripts/v3-orchestrator.sh` implements as a runnable workflow.

---

## 1. Roles

### Orchestrator (you, when wearing this hat)
- Reads the plan top-to-bottom
- Maintains the **task DAG** in memory: which sub-tasks are `pending | in_progress | blocked | completed | escalated`
- Dispatches sub-agents per sub-task, respecting:
  - Dependencies (DAG §1 of the plan)
  - Mutex zones (no two agents touch the same file simultaneously)
  - Parallelism budget (initial batch: T1.1, T2.10, T3.1, T3.2, T3.4, T3.8, T2.6, T2.9 — all zero-deps)
- Receives sub-agent reports, validates the Verification Gate
- Drives the per-task PR+Copilot review loop
- Resolves escalations (or hands them up to the human)
- Maintains LESSONS.md hygiene and injects it into every dispatched sub-agent

### Sub-Agent (one per sub-task dispatch)
- Receives: task ID, plan excerpt for that task, current LESSONS.md content
- Executes ALL steps of the sub-task in order (read → write code → write tests → run tests)
- Verifies the Verification Gate locally BEFORE reporting back
- Appends to LESSONS.md
- Updates README per the README Delta block
- Commits locally with the conventional commit message
- Reports back to orchestrator: `{verification: green|red, files_changed, lessons_appended, blocker?}`
- Does NOT push, does NOT open PR — that's orchestrator's job (single owner of remote state)

### Reviewer (GitHub Copilot)
- Auto-assigned on PR creation
- Returns review within ~4-9 minutes typically
- May post inline comments + summary verdict
- Orchestrator reads, classifies, dispatches a fix-agent if needed

### Human (Lorenzo)
- Final escalation point
- Authorizes the initial design-spec push + first PR (already done if you're reading this)
- Steps in when orchestrator escalates (per §5 below)

---

## 2. Per-Sub-Task Workflow (atomic loop)

```
┌─────────────────────────────────────────────────────────────────────┐
│ STEP A — Pre-flight                                                 │
│  - Verify all dependencies are completed                            │
│  - Verify no mutex conflict with currently-running agents           │
│  - Read LESSONS.md (full content)                                   │
│  - Read plan excerpt for this task (Files, Steps, Verification Gate,│
│    README Delta, LESSONS Entry slot)                                │
└──────────────────────────────┬──────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────────┐
│ STEP B — Dispatch sub-agent                                         │
│  Spawn agent with prompt template:                                  │
│  ┌─────────────────────────────────────────────────────────────┐    │
│  │ You are implementing sub-task T<id> of AskMyDocs v3.0.      │    │
│  │                                                              │    │
│  │ <PLAN EXCERPT FOR T<id> — paste verbatim>                   │    │
│  │                                                              │    │
│  │ <LESSONS-FROM-PREVIOUS-TASKS>                                │    │
│  │ <full content of docs/v3-platform/LESSONS.md>               │    │
│  │ </LESSONS-FROM-PREVIOUS-TASKS>                               │    │
│  │                                                              │    │
│  │ Constraints:                                                 │    │
│  │ - Follow ALL steps in order. Do NOT skip the failing test   │    │
│  │   step before the implementation.                            │    │
│  │ - Do NOT push, do NOT open PR — orchestrator handles those. │    │
│  │ - Verification Gate is non-negotiable. If any check fails,   │    │
│  │   STOP and report the failure with stdout+stderr+hypothesis. │    │
│  │ - Append your LESSONS.md entry per the template in §0.2.    │    │
│  │ - Update README per the README Delta block.                  │    │
│  │ - Make ONE commit on branch <task-branch>.                   │    │
│  │ - Report back: {verification: ..., files_changed: [...],    │    │
│  │   lessons_appended: bool, blocker: null | "..."}             │    │
│  └─────────────────────────────────────────────────────────────┘    │
└──────────────────────────────┬──────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────────┐
│ STEP C — Validate report                                            │
│  - If verification: red → re-dispatch with failure log attached     │
│    (max 3 retries, then escalate to human)                          │
│  - If lessons_appended: false → re-dispatch with reminder           │
│  - Else: continue                                                   │
└──────────────────────────────┬──────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────────┐
│ STEP D — Push + open PR                                             │
│  git push origin <task-branch>                                      │
│  PR_URL=$(gh pr create \                                            │
│    --base <macro-task-branch> \                                     │
│    --title "feat(v3.0/T<id>): <title>" \                            │
│    --body-file .pr-body.md \                                        │
│    --reviewer copilot)                                              │
│  PR_NUM=$(echo $PR_URL | grep -oE '[0-9]+$')                        │
└──────────────────────────────┬──────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────────┐
│ STEP E — Wait for Copilot review (4-9 min typical)                  │
│  Loop every 90s up to max 15 min:                                   │
│   gh pr view $PR_NUM --json reviews,comments,statusCheckRollup      │
│   - If review by 'copilot-pull-request-reviewer' present → break    │
│   - If 15 min elapsed → escalate "Copilot timeout"                  │
└──────────────────────────────┬──────────────────────────────────────┘
                               ↓
┌─────────────────────────────────────────────────────────────────────┐
│ STEP F — Triage Copilot comments                                    │
│  For each comment:                                                  │
│   - bug → must fix                                                  │
│   - nitpick → fix if low-effort, else reply "acknowledged, deferred"│
│   - wrong → reply with explanation, do NOT fix                      │
│   - doc-drift → must fix (R9 enforcement)                           │
│   - style → fix if pre-commit hook would catch it                   │
│  Build a fix-list                                                   │
└──────────────────────────────┬──────────────────────────────────────┘
                               ↓
              ┌────────────────┴────────────────┐
              ↓                                 ↓
   ┌─────────────────────┐         ┌──────────────────────┐
   │ Fix-list non-empty? │         │ Fix-list empty +     │
   │  → STEP G           │         │ Copilot APPROVED →   │
   └──────────┬──────────┘         │ STEP I               │
              ↓                    └──────────┬───────────┘
   ┌─────────────────────┐                    ↓
   │ STEP G — Dispatch   │         ┌──────────────────────┐
   │ fix-agent           │         │ STEP I — Merge PR    │
   │ (same prompt        │         │  - CI must be green  │
   │  template, but with │         │  - gh pr merge \\     │
   │  fix-list attached) │         │    $PR_NUM --squash \\│
   │  Re-run Verif Gate  │         │    --delete-branch   │
   └──────────┬──────────┘         │  - git checkout      │
              ↓                    │    <macro-branch>    │
   ┌─────────────────────┐         │  - git pull          │
   │ STEP H — Re-push    │         └──────────┬───────────┘
   │  git push           │                    ↓
   │  GOTO STEP E        │         ┌──────────────────────┐
   │  (1 more cycle MAX  │         │ STEP J — Bookkeeping │
   │   then escalate)    │         │  - Mark T<id> done   │
   └─────────────────────┘         │  - Inspect blocked   │
                                   │    tasks, unblock    │
                                   │    those whose deps  │
                                   │    are now green     │
                                   │  - Pick next task(s) │
                                   │  - GOTO STEP A       │
                                   └──────────────────────┘
```

---

## 3. Parallelism Rules

**Initial dispatch wave (T-zero, all dependencies = ∅):**
```
WAVE 0 (parallel-safe):
  T1.1  Core Interfaces + DTOs
  T2.10 Tags admin UI scaffold
  T3.1  Migration grounding columns
  T3.2  ConfidenceCalculator
  T3.4  Prompt sentinel update    [LATER blocked by T3.3 wiring]
  T3.8  i18n strings
  T2.6  KbDocumentSearchController
  T2.9  chat_filter_presets
```

**WAVE 1 (after T1.1 completes):**
```
  T1.2  MarkdownChunker refactor
  T1.3  Markdown+Text passthrough converters
  T1.4  PipelineRegistry + DocumentIngestor refactor (mutex: DocumentIngestor)
  T2.1  KbSearchService.applyFilters scaffold (mutex: KbSearchService)
```

**WAVE 2 (after WAVE 1):**
```
  After T1.4: T1.5 (Pdf), T1.6 (Docx), T1.7 (PdfPageChunker), T1.8 (source_type enum)
  After T2.1+T2.2: T2.3 (tag), T2.4 (folder), T2.5 (doc_ids)
  After T2.6+T2.7: T2.8 (mention popover)
  After T3.1+T3.2: T3.3 (refusal logic)
  After T3.3+T3.4: T3.5 (response shape)
  After T3.5: T3.6 (ConfidenceBadge)
  After T3.6: T3.7 (RefusalNotice)
```

**WAVE 3 (FINAL — only after M1+M2+M3 merged into feature/v3.0):**
```
  T4.1 → T4.2 → T4.3 → T4.4 → T4.5 → T4.6 (sequential, single agent each)
```

**Mutex Zones (orchestrator-enforced exclusivity):**
- `app/Services/Kb/KbSearchService.php`: T1.4, T2.1, T2.3, T2.4, T2.5
- `app/Http/Controllers/Api/KbChatController.php`: T2.2, T3.3, T3.4, T3.5
- `app/Services/Kb/DocumentIngestor.php`: T1.2, T1.4, T1.8
- `frontend/src/features/chat/Composer.tsx`: T2.7, T2.8, T3.6, T3.7
- `frontend/src/features/chat/MessageBubble.tsx`: T3.6, T3.7

**Recommended max parallelism:** 4 sub-agents at once. More creates merge-conflict risk on shared config files (`config/kb-pipeline.php`, `routes/api.php`).

---

## 4. LESSONS.md Injection Protocol

**File:** `docs/v3-platform/LESSONS.md` (append-only, see plan §0.2 for entry format).

**Orchestrator hygiene:**
- Before EVERY dispatch (STEP B), read full LESSONS.md.
- Inject into agent prompt verbatim, wrapped in `<LESSONS-FROM-PREVIOUS-TASKS>...</LESSONS-FROM-PREVIOUS-TASKS>`.
- Do NOT summarize, do NOT skip — sub-agents need full context to apply lessons consistently.

**If LESSONS.md grows large (>5000 tokens):**
- Triage: keep `Severity: high|critical` entries verbatim
- Cluster `Severity: low|medium` discoveries by area, summarize each cluster in 1-2 lines
- Save full archive at `docs/v3-platform/LESSONS-archive-<date>.md`
- Continue injecting the trimmed version

**On final consolidation (T4.1):**
- Single agent reads full LESSONS.md (un-trimmed)
- Produces `LESSONS-v3.0-digest.md` with R23+ candidates and skill candidates
- Subsequent T4.3, T4.4 promote candidates into permanent rules + skills

---

## 5. Escalation Triggers (orchestrator MUST stop and ask human)

| Trigger | Action |
|---|---|
| Sub-agent verification gate fails 3 times | Pause, summarize attempts, ask human for guidance |
| Copilot review absent after 15 min | Pause, ask human (rate-limited? outage?) |
| Copilot requests changes after 2 fix cycles | Pause, ask human to read the disagreement |
| CI red after 2 fix attempts | Pause, ask human |
| Sub-agent reports a security issue not in plan scope | Pause immediately, do NOT push |
| Merge conflict on macro branch | Pause, ask human (orchestrator should never resolve conflicts blindly) |
| External service outage (PG, GitHub, AI provider) detected by sub-agent | Pause, retry once after 5 min, then ask human |

---

## 6. Branch Conventions

```
main
 └── feature/v3-design-spec        ← THIS file lives here
 └── feature/v3-platform           ← integration branch (long-lived)
       └── feature/v3.0            ← release branch (medium-lived)
             ├── feature/v3.0-pipeline-foundation
             │     ├── feature/v3.0-T1.1   ← per-sub-task branch (squash-merged + deleted)
             │     ├── feature/v3.0-T1.2
             │     └── ...
             ├── feature/v3.0-filters-enterprise
             │     ├── feature/v3.0-T2.1
             │     └── ...
             ├── feature/v3.0-grounding-tier1
             │     └── ...
             └── feature/v3.0-consolidation
                   └── ...
```

**Macro-task PRs:** opened only after ALL sub-task PRs merged into the macro-task branch.

**Final v3.0 PR:** `feature/v3.0` → `feature/v3-platform`, opened after consolidation merged.

---

## 7. Reporting Cadence

**Orchestrator reports to human:**
- Once per WAVE completion: `WAVE N done — N tasks merged, M lessons added, X minutes elapsed`
- On every escalation: full context + recommended action
- Daily summary if running > 24h: `docs/v3-platform/orchestrator-log-<date>.md`

---

## 8. Tooling References

- **Standalone runner:** `scripts/v3-orchestrator.sh` (implements this protocol — bash, runs without Claude session needed)
- **Plan source:** `docs/superpowers/plans/2026-04-26-v3.0-pipeline-filters-grounding.md`
- **Lessons log:** `docs/v3-platform/LESSONS.md`
- **Digest target (T4.1):** `docs/v3-platform/LESSONS-v3.0-digest.md`
- **Design spec:** `docs/superpowers/specs/2026-04-26-v3-enterprise-knowledge-platform-design.md`

---

## 9. Anti-patterns (DO NOT do these)

- ❌ Dispatch a sub-agent without injecting LESSONS.md
- ❌ Push or open PR from inside a sub-agent (orchestrator owns remote state)
- ❌ Mark a task done without Verification Gate green AND Copilot APPROVED AND merged
- ❌ Skip a fix because "it's just a nitpick" — Copilot's nitpicks become tomorrow's R-rules
- ❌ Resolve merge conflicts in macro branch without escalating
- ❌ Trim LESSONS.md too aggressively — lose context, agents repeat mistakes
- ❌ Run > 4 sub-agents in parallel against the same monorepo — race conditions on shared files

---

**End of orchestrator protocol.**
