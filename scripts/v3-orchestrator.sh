#!/usr/bin/env bash
# AskMyDocs v3.0 Orchestrator — standalone runner
#
# Implements the per-task PR+Copilot review loop documented in
# docs/v3-platform/ORCHESTRATOR.md.
#
# Requires: gh (GitHub CLI), jq, claude (Claude Code CLI in headless mode),
#           git, php, npm, vendor/bin/phpunit
#
# Usage:
#   bash scripts/v3-orchestrator.sh <task-id>      # run a single sub-task
#   bash scripts/v3-orchestrator.sh --wave 0       # dispatch full wave (parallel)
#   bash scripts/v3-orchestrator.sh --resume       # find next pending task and continue
#   bash scripts/v3-orchestrator.sh --status       # print task DAG status

set -uo pipefail

# ─── Config ─────────────────────────────────────────────────────────────────
PLAN_FILE="docs/superpowers/plans/2026-04-26-v3.0-pipeline-filters-grounding.md"
LESSONS_FILE="docs/v3-platform/LESSONS.md"
ORCHESTRATOR_DOC="docs/v3-platform/ORCHESTRATOR.md"
PROGRESS_DIR="docs/v3-platform/progress"
STATE_FILE=".v3-orchestrator-state.json"
LOG_DIR="docs/v3-platform/orchestrator-logs"
MAX_PARALLELISM=4
COPILOT_WAIT_INITIAL_SEC=240   # 4 min
COPILOT_POLL_INTERVAL_SEC=90
COPILOT_TIMEOUT_SEC=900        # 15 min total
MAX_FIX_CYCLES=2
MAX_VERIFICATION_RETRIES=3

mkdir -p "$LOG_DIR" "$PROGRESS_DIR"

# ─── Pre-flight ─────────────────────────────────────────────────────────────
require_cmd() {
  command -v "$1" >/dev/null 2>&1 || { echo "ERROR: required command '$1' not found" >&2; exit 1; }
}
require_cmd gh
require_cmd jq
require_cmd git
require_cmd php

if ! gh auth status >/dev/null 2>&1; then
  echo "ERROR: gh CLI not authenticated. Run 'gh auth login' first." >&2
  exit 1
fi

# ─── State management ──────────────────────────────────────────────────────
init_state() {
  if [[ ! -f "$STATE_FILE" ]]; then
    echo '{"tasks": {}, "started_at": "'$(date -u +%FT%TZ)'"}' > "$STATE_FILE"
  fi
}

set_task_status() {
  local task_id="$1"
  local status="$2"   # pending | in_progress | completed | escalated | blocked
  jq --arg id "$task_id" --arg s "$status" --arg ts "$(date -u +%FT%TZ)" \
    '.tasks[$id] = (.tasks[$id] // {}) + {status: $s, updated_at: $ts}' \
    "$STATE_FILE" > "$STATE_FILE.tmp" && mv "$STATE_FILE.tmp" "$STATE_FILE"
}

get_task_status() {
  jq -r --arg id "$1" '.tasks[$id].status // "pending"' "$STATE_FILE"
}

set_task_field() {
  jq --arg id "$1" --arg k "$2" --arg v "$3" \
    '.tasks[$id][$k] = $v' "$STATE_FILE" > "$STATE_FILE.tmp" && mv "$STATE_FILE.tmp" "$STATE_FILE"
}

# ─── Logging ───────────────────────────────────────────────────────────────
log() {
  local level="$1"; shift
  local msg="$*"
  local ts
  ts=$(date -u +%FT%TZ)
  echo "[$ts] [$level] $msg" | tee -a "$LOG_DIR/orchestrator-$(date +%Y%m%d).log"
}

# ─── Branch utilities ──────────────────────────────────────────────────────
ensure_branch() {
  local branch="$1"
  local base="$2"
  if ! git show-ref --verify --quiet "refs/heads/$branch"; then
    log INFO "Creating branch $branch from $base"
    git checkout "$base" 2>/dev/null
    git pull --quiet 2>/dev/null || true
    git checkout -b "$branch"
  else
    git checkout "$branch"
  fi
}

# ─── Progress file management ──────────────────────────────────────────────
init_progress_file() {
  local task_id="$1"
  local task_branch="$2"
  local progress_file="$PROGRESS_DIR/$task_id.md"

  if [[ -f "$progress_file" ]]; then
    log INFO "Progress file already exists for $task_id — keeping for resume"
    return 0
  fi

  local task_title
  task_title=$(awk -v id="${task_id#T}" '$0 ~ "^### Task " id " " {print substr($0, length("### Task " id " — ")); exit}' "$PLAN_FILE")

  cat > "$progress_file" <<EOF
# $task_id Progress Log

**Status:** in_progress
**Started:** $(date -u +"%Y-%m-%d %H:%M:%S UTC")
**Last update:** $(date -u +"%Y-%m-%d %H:%M:%S UTC")
**Branch:** $task_branch
**Plan reference:** docs/superpowers/plans/2026-04-26-v3.0-pipeline-filters-grounding.md (### Task ${task_id#T} — $task_title)

## Step Updates (append-only — newest at the BOTTOM)

<!-- Sub-agent appends a "### [HH:MM:SS] Step N: ..." block after each step -->

## Verification Gate State

<!-- Sub-agent ticks each gate item from the plan as they pass -->

## Completion Checklist (orchestrator-driven)

- [ ] Verification Gate green
- [ ] LESSONS.md entry appended
- [ ] README delta applied
- [ ] Local commit made (sha: <pending>)
- [ ] Branch pushed
- [ ] PR opened with Copilot reviewer (PR#: <pending>)
- [ ] Copilot review received
- [ ] Fix cycle complete (cycles: 0)
- [ ] PR merged + branch deleted

## Recovery Instructions

If this session was interrupted, the orchestrator MUST:
1. Read the LAST '### [HH:MM:SS] Step N' block in 'Step Updates'
2. Check 'Result:' of that step:
   - If PASS → restart from Step N+1
   - If FAIL → restart from Step N (re-attempt with error context)
   - If missing → restart from Step N (sub-agent crashed mid-step)
3. Run 'git status' and 'git log --oneline -5' on the task branch to confirm state
4. If 'Local commit made' is checked but not 'Branch pushed' → just push, do NOT re-run agent
5. If 'PR opened' is checked → resume the wait-for-Copilot loop, do NOT reopen PR

## Error Log

<!-- Append blocker details here if status flips to escalated/blocked -->
EOF

  log INFO "Progress file initialized: $progress_file"
}

read_last_progress_step() {
  local progress_file="$PROGRESS_DIR/$1.md"
  if [[ ! -f "$progress_file" ]]; then
    echo "NEW"
    return 0
  fi
  # Find last "### [HH:MM:SS] Step N:" block + result line
  local last_step
  last_step=$(grep -E "^### \[[0-9]{2}:[0-9]{2}:[0-9]{2}\] Step [0-9]+:" "$progress_file" | tail -1)
  if [[ -z "$last_step" ]]; then
    echo "NEW"
    return 0
  fi
  local step_num
  step_num=$(echo "$last_step" | grep -oE "Step [0-9]+" | grep -oE "[0-9]+")
  # Check the result of that step (look in the lines following the last "### Step N:" header)
  local last_result
  last_result=$(awk "/^### \[[0-9]+:[0-9]+:[0-9]+\] Step ${step_num}:/{found=1} found && /^\*\*Result:\*\*/{print; exit}" "$progress_file")
  if echo "$last_result" | grep -q "PASS"; then
    echo "RESUME_FROM:$((step_num + 1))"
  else
    echo "RESUME_FROM:$step_num"
  fi
}

# ─── Sub-agent dispatch (Claude Code headless) ─────────────────────────────
# This is the integration seam with Claude. Two implementations:
#  (A) HEADLESS — uses `claude --headless` (if available in your install)
#  (B) MANUAL — script pauses and asks human to paste output from interactive Claude session
#
# Default: MANUAL (works for everyone). To use HEADLESS, set CLAUDE_HEADLESS=1.
dispatch_subagent() {
  local task_id="$1"
  local task_branch="$2"
  local task_excerpt_file="$3"
  local lessons_content
  lessons_content=$(cat "$LESSONS_FILE")

  local prompt_file
  prompt_file=$(mktemp -t "v3-prompt-${task_id}-XXXXXX.md")
  local progress_status
  progress_status=$(read_last_progress_step "$task_id")
  local resume_clause=""
  if [[ "$progress_status" =~ ^RESUME_FROM:([0-9]+)$ ]]; then
    local resume_step="${BASH_REMATCH[1]}"
    resume_clause="

# RESUMPTION CONTEXT

A previous agent worked on this task and produced docs/v3-platform/progress/${task_id}.md.
Read that file FIRST. It tells you exactly which steps already completed (with their results
and file lists) and which step to RESUME from. Inferred resume point: Step ${resume_step}.

Verify repo state matches what the progress log claims (run 'git status', 'git log --oneline -5'
on this branch) before proceeding. If state diverges from progress log, append a discrepancy
note in the Error Log section and re-do the diverged steps.
"
  fi

  cat > "$prompt_file" <<EOF
You are implementing sub-task ${task_id} of AskMyDocs v3.0.

Working directory: $(pwd)
Branch you are on: ${task_branch}
Progress log: docs/v3-platform/progress/${task_id}.md (pre-created by orchestrator)
${resume_clause}
# PLAN EXCERPT FOR ${task_id}

$(cat "$task_excerpt_file")

# LESSONS-FROM-PREVIOUS-TASKS

$lessons_content

# CONSTRAINTS (orchestrator-enforced)

1. Follow ALL steps in order. Do NOT skip the failing-test step before the implementation.
2. **Progress tracking is MANDATORY.** After EACH numbered Step (1, 2, 3, ...) in the plan,
   append a new block to docs/v3-platform/progress/${task_id}.md under "## Step Updates":

   ### [HH:MM:SS] Step N: <step title from plan>
   **Action:** <what you did, 1-2 lines>
   **Files touched:** [paths created/modified]
   **Command run:** <if applicable>
   **Result:** PASS | FAIL (with stdout/stderr excerpt)
   **State after step:** <one sentence describing repo state>

   Update BEFORE moving to the next step. If you stop mid-step, append a partial block
   describing the partial state — never leave a step half-done without a record.
   For long-running steps (>10 min), append a heartbeat block.

3. Do NOT push, do NOT open PR — orchestrator handles those.
4. The Verification Gate is non-negotiable. If any check fails, STOP and:
   - Append an Error Log entry in the progress file with: failing command, stdout, stderr,
     hypothesis, suggested next debug actions
   - Report back with verification: red
5. Append your LESSONS.md entry per the template at the top of $LESSONS_FILE.
6. Update README.md per the README Delta block in your task excerpt (or note 'none' explicitly).
7. Make ONE atomic commit when everything is green, with the conventional commit message
   shown in the plan. Tick the 'Local commit made (sha: <git-sha>)' line in the progress file.
8. Report the final state to stdout in this exact JSON shape:
   {"verification": "green"|"red", "files_changed": [...], "lessons_appended": true|false, "progress_file_updated": true|false, "blocker": null|"..."}

Begin by reading the progress file and either starting Step 1 or resuming from the inferred step.
EOF

  log INFO "Dispatching sub-agent for ${task_id}"

  if [[ "${CLAUDE_HEADLESS:-0}" == "1" ]]; then
    # HEADLESS path — adapt the flag to your Claude Code version
    claude --headless --prompt-file "$prompt_file" --max-turns 50 > "$LOG_DIR/subagent-${task_id}-$(date +%H%M%S).log" 2>&1
    return $?
  else
    # MANUAL path
    echo "═══════════════════════════════════════════════════════════════════════"
    echo " MANUAL DISPATCH for ${task_id}"
    echo "═══════════════════════════════════════════════════════════════════════"
    echo "Open a fresh Claude Code session in this repo and paste the prompt from:"
    echo "  $prompt_file"
    echo ""
    echo "When the agent reports the final JSON, paste it below and press Enter."
    echo "(Or type 'red' if the agent failed without proper JSON)"
    echo ""
    read -r -p "Agent final report (JSON or 'red'): " agent_report
    echo "$agent_report" > "$LOG_DIR/subagent-${task_id}-report.json"
    if [[ "$agent_report" == "red" ]] || ! echo "$agent_report" | jq -e '.verification == "green"' > /dev/null; then
      return 1
    fi
    return 0
  fi
}

# ─── Verification Gate runner (independent of agent — orchestrator double-checks) ─
run_verification_gate() {
  local task_id="$1"
  log INFO "Running independent Verification Gate for ${task_id}"

  # Universal checks every task must pass:
  if ! vendor/bin/phpunit --testsuite=Unit > "$LOG_DIR/phpunit-unit-${task_id}.log" 2>&1; then
    log ERROR "PHPUnit Unit suite failed for ${task_id}"
    return 1
  fi

  if ! vendor/bin/phpunit --testsuite=Feature > "$LOG_DIR/phpunit-feature-${task_id}.log" 2>&1; then
    log ERROR "PHPUnit Feature suite failed for ${task_id}"
    return 1
  fi

  # FE checks if frontend was touched
  if git diff --name-only HEAD~1 HEAD | grep -q '^frontend/'; then
    if ! (cd frontend && npm test -- --run > "$LOG_DIR/vitest-${task_id}.log" 2>&1); then
      log ERROR "Vitest failed for ${task_id}"
      return 1
    fi
  fi

  # E2E only if frontend/e2e/* changed
  if git diff --name-only HEAD~1 HEAD | grep -q '^frontend/e2e/'; then
    if ! (cd frontend && npm run e2e > "$LOG_DIR/playwright-${task_id}.log" 2>&1); then
      log ERROR "Playwright failed for ${task_id}"
      return 1
    fi
  fi

  # R13 enforcement
  if ! bash scripts/verify-e2e-real-data.sh > "$LOG_DIR/r13-${task_id}.log" 2>&1; then
    log ERROR "R13 verifier (verify-e2e-real-data.sh) failed for ${task_id}"
    return 1
  fi

  log INFO "Verification Gate GREEN for ${task_id}"
  return 0
}

# ─── PR open + Copilot review wait ─────────────────────────────────────────
open_pr() {
  local task_id="$1"
  local task_title="$2"
  local task_branch="$3"
  local base_branch="$4"

  log INFO "Pushing $task_branch and opening PR"
  git push -u origin "$task_branch" 2>&1 | tee "$LOG_DIR/push-${task_id}.log"

  cat > .pr-body.md <<EOF
## Sub-task ${task_id}: ${task_title}

Part of v3.0 release. Plan: \`${PLAN_FILE}\`.

### What this PR does
$(grep -A 200 "^### Task ${task_id#T}" "$PLAN_FILE" | sed -n '/^### Task/,/^### Task/p' | head -100)

### Verification
- [x] Unit tests green
- [x] Feature tests green
- [x] E2E tests green (if FE touched)
- [x] R13 verifier green
- [x] LESSONS.md entry appended

### Lessons appended to \`docs/v3-platform/LESSONS.md\`
$(tail -50 "$LESSONS_FILE")

🤖 Auto-generated by v3-orchestrator.sh
EOF

  local pr_url
  pr_url=$(gh pr create \
    --base "$base_branch" \
    --head "$task_branch" \
    --title "feat(v3.0/${task_id}): ${task_title}" \
    --body-file .pr-body.md 2>&1 | tee "$LOG_DIR/pr-create-${task_id}.log" | grep -oE 'https://github.com[^ ]+')

  rm -f .pr-body.md

  if [[ -z "$pr_url" ]]; then
    log ERROR "Failed to open PR for $task_id"
    return 1
  fi

  local pr_num
  pr_num=$(echo "$pr_url" | grep -oE '[0-9]+$')

  # Assign Copilot via REST API (gh CLI rejects 'copilot' as a regular user login;
  # the bot must be added through the requested_reviewers endpoint with the
  # 'copilot-pull-request-reviewer[bot]' login).
  local repo_full
  repo_full=$(gh repo view --json nameWithOwner --jq '.nameWithOwner')
  if ! gh api "repos/${repo_full}/pulls/${pr_num}/requested_reviewers" \
        -X POST -f 'reviewers[]=copilot-pull-request-reviewer[bot]' \
        > "$LOG_DIR/copilot-assign-${task_id}.log" 2>&1; then
    log WARN "Copilot bot assignment via API failed for PR #$pr_num — verify manually"
  else
    log INFO "Copilot assigned as reviewer on PR #$pr_num"
  fi

  set_task_field "$task_id" "pr_url" "$pr_url"
  set_task_field "$task_id" "pr_number" "$pr_num"

  log INFO "PR #$pr_num opened: $pr_url"
  echo "$pr_num"
}

wait_for_copilot() {
  local pr_num="$1"
  local elapsed=0

  log INFO "Waiting initial $COPILOT_WAIT_INITIAL_SEC s for Copilot review"
  sleep "$COPILOT_WAIT_INITIAL_SEC"
  elapsed=$COPILOT_WAIT_INITIAL_SEC

  while [[ $elapsed -lt $COPILOT_TIMEOUT_SEC ]]; do
    local copilot_review
    copilot_review=$(gh pr view "$pr_num" --json reviews --jq \
      '.reviews[] | select(.author.login | test("copilot"; "i")) | {state, body, submittedAt}' 2>/dev/null)

    if [[ -n "$copilot_review" ]]; then
      log INFO "Copilot review received for PR #$pr_num after $elapsed s"
      echo "$copilot_review"
      return 0
    fi

    log INFO "Still waiting for Copilot (elapsed ${elapsed}s, polling again in $COPILOT_POLL_INTERVAL_SEC s)"
    sleep "$COPILOT_POLL_INTERVAL_SEC"
    elapsed=$((elapsed + COPILOT_POLL_INTERVAL_SEC))
  done

  log ERROR "Copilot review timeout after ${elapsed}s for PR #$pr_num — ESCALATE"
  return 1
}

triage_copilot_comments() {
  local pr_num="$1"
  local comments
  comments=$(gh api "repos/{owner}/{repo}/pulls/$pr_num/comments" --jq \
    'map(select(.user.login | test("copilot"; "i"))) | map({path, line, body})')

  local count
  count=$(echo "$comments" | jq 'length')

  if [[ "$count" -eq 0 ]]; then
    log INFO "Copilot left no inline comments"
    echo "[]"
    return 0
  fi

  log INFO "Copilot left $count inline comments — review them in PR #$pr_num"
  echo "$comments" | jq '.'
  return 0
}

merge_pr() {
  local pr_num="$1"
  log INFO "Merging PR #$pr_num (squash + delete branch)"
  if gh pr merge "$pr_num" --squash --delete-branch 2>&1 | tee -a "$LOG_DIR/merge-pr-$pr_num.log"; then
    log INFO "PR #$pr_num merged successfully"
    return 0
  else
    log ERROR "Merge of PR #$pr_num failed"
    return 1
  fi
}

# ─── Main per-task driver ──────────────────────────────────────────────────
run_task() {
  local task_id="$1"
  local task_branch="feature/v3.0-${task_id}"
  local macro_branch
  case "$task_id" in
    T1.*) macro_branch="feature/v3.0-pipeline-foundation" ;;
    T2.*) macro_branch="feature/v3.0-filters-enterprise" ;;
    T3.*) macro_branch="feature/v3.0-grounding-tier1" ;;
    T4.*) macro_branch="feature/v3.0-consolidation" ;;
    *) log ERROR "Unknown task pattern $task_id"; return 1 ;;
  esac

  set_task_status "$task_id" "in_progress"
  ensure_branch "$macro_branch" "feature/v3.0"
  ensure_branch "$task_branch" "$macro_branch"
  init_progress_file "$task_id" "$task_branch"

  # Extract the task excerpt from the plan
  local task_excerpt_file
  task_excerpt_file=$(mktemp -t "v3-excerpt-${task_id}-XXXXXX.md")
  awk -v id="${task_id#T}" '
    /^### Task / {p=0}
    $0 ~ "^### Task " id " " {p=1}
    p {print}
    /^### Task / && p && $0 !~ "^### Task " id " " {exit}
  ' "$PLAN_FILE" > "$task_excerpt_file"

  local retries=0
  while [[ $retries -lt $MAX_VERIFICATION_RETRIES ]]; do
    if dispatch_subagent "$task_id" "$task_branch" "$task_excerpt_file"; then
      if run_verification_gate "$task_id"; then
        break
      fi
    fi
    retries=$((retries + 1))
    log WARN "Sub-agent + Verification Gate failed for $task_id (attempt $retries/$MAX_VERIFICATION_RETRIES)"
  done

  if [[ $retries -ge $MAX_VERIFICATION_RETRIES ]]; then
    log ERROR "Sub-agent + Verification Gate failed $MAX_VERIFICATION_RETRIES times for $task_id — ESCALATING"
    set_task_status "$task_id" "escalated"
    return 1
  fi

  # Push + open PR
  local pr_num
  if ! pr_num=$(open_pr "$task_id" "$(grep -oP "(?<=^### Task ${task_id#T} — ).+" "$PLAN_FILE" | head -1)" "$task_branch" "$macro_branch"); then
    set_task_status "$task_id" "escalated"
    return 1
  fi

  # Copilot review loop
  local fix_cycle=0
  while [[ $fix_cycle -le $MAX_FIX_CYCLES ]]; do
    if ! wait_for_copilot "$pr_num"; then
      set_task_status "$task_id" "escalated"
      return 1
    fi

    local comments
    comments=$(triage_copilot_comments "$pr_num")
    local fix_count
    fix_count=$(echo "$comments" | jq 'length')

    if [[ "$fix_count" -eq 0 ]]; then
      # Verify Copilot APPROVED state (not just no comments)
      local approval
      approval=$(gh pr view "$pr_num" --json reviews --jq \
        '[.reviews[] | select(.author.login | test("copilot"; "i"))][-1].state' 2>/dev/null)

      if [[ "$approval" == "APPROVED" ]] || [[ "$approval" == "COMMENTED" && "$fix_count" -eq 0 ]]; then
        log INFO "Copilot APPROVED PR #$pr_num — proceeding to merge"
        break
      fi

      if [[ "$approval" == "CHANGES_REQUESTED" ]]; then
        log WARN "Copilot requested changes (cycle $fix_cycle / $MAX_FIX_CYCLES) — dispatching fix-agent"
      fi
    else
      log INFO "Copilot left $fix_count inline comments — dispatching fix-agent (cycle $fix_cycle / $MAX_FIX_CYCLES)"
    fi

    if [[ $fix_cycle -ge $MAX_FIX_CYCLES ]]; then
      log ERROR "Copilot fix loop exhausted ($MAX_FIX_CYCLES) for PR #$pr_num — ESCALATE"
      set_task_status "$task_id" "escalated"
      return 1
    fi

    # Dispatch fix-agent with Copilot comments attached
    local fix_excerpt_file
    fix_excerpt_file=$(mktemp -t "v3-fix-${task_id}-XXXXXX.md")
    {
      cat "$task_excerpt_file"
      echo ""
      echo "# COPILOT REVIEW FEEDBACK (cycle $((fix_cycle + 1)))"
      echo ""
      echo "$comments" | jq -r '.[] | "## \(.path):\(.line)\n\(.body)\n"'
    } > "$fix_excerpt_file"

    if ! dispatch_subagent "$task_id-fix$((fix_cycle + 1))" "$task_branch" "$fix_excerpt_file"; then
      log ERROR "Fix-agent failed for $task_id"
      set_task_status "$task_id" "escalated"
      return 1
    fi

    if ! run_verification_gate "$task_id"; then
      log ERROR "Verification Gate failed after fix-agent — ESCALATE"
      set_task_status "$task_id" "escalated"
      return 1
    fi

    git push 2>&1 | tee "$LOG_DIR/push-fix-${task_id}-c${fix_cycle}.log"

    fix_cycle=$((fix_cycle + 1))
  done

  # Merge
  if ! merge_pr "$pr_num"; then
    set_task_status "$task_id" "escalated"
    return 1
  fi

  git checkout "$macro_branch" 2>/dev/null && git pull --quiet 2>/dev/null

  set_task_status "$task_id" "completed"
  log INFO "✅ Task $task_id COMPLETED"
}

# ─── Entry point dispatcher ────────────────────────────────────────────────
init_state

case "${1:-}" in
  --status)
    jq '.tasks' "$STATE_FILE"
    echo ""
    echo "Progress files:"
    ls -1 "$PROGRESS_DIR"/*.md 2>/dev/null | while read -r f; do
      task=$(basename "$f" .md)
      status=$(grep -E '^\*\*Status:\*\*' "$f" | head -1 | sed 's/\*\*Status:\*\* //')
      last_step=$(grep -E '^### \[[0-9]+:[0-9]+:[0-9]+\] Step' "$f" | tail -1 | sed 's/^### //' | head -c 80)
      echo "  $task  ($status)  last: $last_step"
    done
    ;;
  --resume)
    next=$(jq -r '.tasks | to_entries | map(select(.value.status != "completed")) | .[0].key // empty' "$STATE_FILE")
    if [[ -z "$next" ]]; then
      echo "All tasks marked completed. v3.0 done?"
      exit 0
    fi
    log INFO "Resuming with $next"
    run_task "$next"
    ;;
  --wave)
    wave="${2:?wave number required}"
    case "$wave" in
      0) tasks=("T1.1" "T2.10" "T3.1" "T3.2" "T3.4" "T3.8" "T2.6" "T2.9") ;;
      *) echo "Wave $wave not yet defined"; exit 1 ;;
    esac
    log INFO "Dispatching wave $wave: ${tasks[*]}"
    for t in "${tasks[@]}"; do
      run_task "$t" &
      while [[ $(jobs -r | wc -l) -ge $MAX_PARALLELISM ]]; do
        sleep 5
      done
    done
    wait
    log INFO "Wave $wave done"
    ;;
  T*)
    run_task "$1"
    ;;
  *)
    cat <<EOF
Usage:
  $0 <task-id>       Run a single sub-task (e.g. T1.1, T2.7, T3.3)
  $0 --wave 0        Dispatch a parallel wave (max $MAX_PARALLELISM concurrent)
  $0 --resume        Find next pending task and run it
  $0 --status        Print task DAG status

See $ORCHESTRATOR_DOC for the full protocol.
EOF
    exit 1
    ;;
esac
