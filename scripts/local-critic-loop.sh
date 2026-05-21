#!/usr/bin/env bash
#
# scripts/local-critic-loop.sh — R40 local critic loop wrapper.
#
# Runs the pre-flight critic on the current branch BEFORE `git push`:
#   1. Stages the diff (HEAD vs the base branch, default `feature/v8.0`)
#      and the PR-style metadata (title + body if `gh` finds an open PR
#      for this branch) into /tmp/.
#   2. Invokes `copilot` with `/review` as the slash command and feeds
#      the full diff inline so the reviewer sees the PR diff the same
#      way Cloud Code Review will.
#   3. Greps the response for `SUMMARY: N must-fix, M nit`; exits non-
#      zero if `N > 0` so callers can use the script as a Git hook.
#
# Usage:
#   ./scripts/local-critic-loop.sh               # base = feature/v8.0
#   ./scripts/local-critic-loop.sh feature/v7.1  # explicit base
#   BASE_BRANCH=main ./scripts/local-critic-loop.sh
#
# Requires:
#   - `copilot` CLI on PATH (GitHub Copilot CLI ≥ 1.0.49).
#   - `gh` CLI authenticated (optional — used to fetch PR title/body).
#   - `git` with the branch checked out and a clean staging area.
#
# This wrapper is the canonical R40 entry point. The full rule is in
# CLAUDE.md §R40 and the path-scoped reviewer instructions live in
# `.github/instructions/r-rules.instructions.md`.

set -euo pipefail

BASE_BRANCH="${1:-${BASE_BRANCH:-feature/v8.0}}"
CWD="$(pwd)"
DIFF_FILE="/tmp/local-critic-diff-$$.patch"
META_FILE="/tmp/local-critic-meta-$$.txt"
RESPONSE_FILE="/tmp/local-critic-response-$$.txt"

cleanup() { rm -f "$DIFF_FILE" "$META_FILE" "$RESPONSE_FILE"; }
trap cleanup EXIT

current_branch="$(git rev-parse --abbrev-ref HEAD)"
echo "→ local-critic-loop on '$current_branch' vs base '$BASE_BRANCH'"

# 1. Capture diff vs base (3-dot — diff vs merge-base).
if ! git diff --quiet "origin/$BASE_BRANCH...HEAD" -- 2>/dev/null; then
  git diff "origin/$BASE_BRANCH...HEAD" >"$DIFF_FILE"
else
  echo "  (no diff vs origin/$BASE_BRANCH — falling back to HEAD~1..HEAD)"
  git diff HEAD~1..HEAD >"$DIFF_FILE"
fi

diff_size_bytes=$(wc -c <"$DIFF_FILE" | tr -d ' ')
diff_lines=$(wc -l <"$DIFF_FILE" | tr -d ' ')
echo "  diff: ${diff_lines} lines / ${diff_size_bytes} bytes"

# 2. Capture PR metadata if there is an open PR.
{
  echo "Branch: $current_branch"
  echo "Base:   $BASE_BRANCH"
  if command -v gh >/dev/null 2>&1; then
    pr_json="$(gh pr view --json number,title,body 2>/dev/null || true)"
    if [[ -n "$pr_json" ]]; then
      echo "PR Number: $(echo "$pr_json" | jq -r .number)"
      echo "PR Title:  $(echo "$pr_json" | jq -r .title)"
      echo "PR Body (first 100 lines):"
      echo "$pr_json" | jq -r .body | head -100
    else
      echo "(no open PR found via gh — running pre-push review)"
    fi
  fi
} >"$META_FILE"

# 3. Build prompt. The first line is the slash-command invocation; the
#    rest is context the agent reads via the file paths (we pass them
#    inline to avoid ARG_MAX on large diffs — copilot-cli ingests them
#    via the Read tool when `--add-dir` whitelists the parent).
PROMPT=$(cat <<EOF
/review

Context: I'm running the R40 local critic loop on branch '$current_branch'
against base '$BASE_BRANCH'. Review the diff for **must-fix issues** only —
violations of the AskMyDocs R-rules (see .github/instructions/r-rules.instructions.md):

- R21 — atomic single-use / lock invariants (lockForUpdate + write in
        same DB::transaction)
- R30 — every tenant-aware query scopes by tenant_id
- R31 — every new tenant-aware model uses BelongsToTenant + has
        tenant_id in \$fillable; every migration starts composite
        uniques with tenant_id
- R14 — never 200 with empty/null/NaN; map failure to the correct
        HTTP status; choose status by exception TYPE not MESSAGE
- R18 — derive options from the DB, never from a literal subset
- R12 — every FE change ships a Playwright spec covering one happy +
        one failure path; selectors use testid/role, waits use
        data-state
- R13 — E2E mocks ONLY external services; intercepting internal
        routes is a violation unless marked `R13: failure injection`

Read these files for the review:
- Diff to review:     $DIFF_FILE
- Branch metadata:    $META_FILE
- R-rule reference:   .github/instructions/r-rules.instructions.md
- Full R-rule list:   .github/copilot-instructions.md
- Project context:    CLAUDE.md

Report findings as bullet list. For each finding state:
  - File + line
  - R-rule violated
  - Why it's a violation (one sentence)
  - Suggested fix (one sentence)

End your review with EXACTLY one line:
  SUMMARY: <N> must-fix, <M> nit

Where <N> is the count of must-fix items and <M> is the count of nits.
Skip stylistic / formatting nits unless they intersect an R-rule —
the cloud loop catches the rest.
EOF
)

# 4. Invoke copilot-cli non-interactively.
echo "→ Invoking copilot --autopilot --yolo --add-dir '$CWD' -p ..."
copilot --autopilot --yolo \
  --add-dir "$CWD" \
  --add-dir /tmp \
  -p "$PROMPT" \
  2>&1 | tee "$RESPONSE_FILE"

# 5. Parse SUMMARY line.
SUMMARY_LINE="$(grep -E '^SUMMARY: [0-9]+ must-fix' "$RESPONSE_FILE" | tail -1 || true)"

if [[ -z "$SUMMARY_LINE" ]]; then
  echo
  echo "✗ Could not find 'SUMMARY: <N> must-fix' line in response."
  echo "  Treating as critic-loop failure — review output manually."
  exit 2
fi

MUST_FIX="$(echo "$SUMMARY_LINE" | sed -E 's/^SUMMARY: ([0-9]+) must-fix.*/\1/')"
echo
echo "$SUMMARY_LINE"

if [[ "$MUST_FIX" -gt 0 ]]; then
  echo "✗ $MUST_FIX must-fix finding(s). Fix locally, re-run, then push."
  exit 1
fi

echo "✓ 0 must-fix. R40 local critic loop clean — safe to push."
exit 0
