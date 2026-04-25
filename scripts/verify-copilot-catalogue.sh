#!/usr/bin/env bash
#
# scripts/verify-copilot-catalogue.sh
#
# Enforces the COPILOT-FINDINGS.md protocol: every commit of the form
# `fix(enh-*): address Copilot review on PR #N` MUST be accompanied
# by at least one row under a `### PR #N` heading in
# `docs/enhancement-plan/COPILOT-FINDINGS.md`.
#
# This is the post-merge integrity gate. Without it, fix commits can
# silently drop out of the catalogue, and PR16's distillation pass
# loses its input.
#
# Exit codes:
#   0 — every fix commit has at least one catalogue row for its PR.
#   1 — at least one fix commit has no corresponding catalogue entry.
#
# Usage:
#   bash scripts/verify-copilot-catalogue.sh
#   bash scripts/verify-copilot-catalogue.sh origin/main..HEAD
#
# CI integration: runs on every PR, alongside verify-e2e-real-data.sh.
# A red exit is a merge block.

set -euo pipefail

RANGE="${1:-}"
CATALOGUE="docs/enhancement-plan/COPILOT-FINDINGS.md"

if [[ ! -f "${CATALOGUE}" ]]; then
  # A missing catalogue is itself a protocol violation (someone renamed
  # or deleted the file). Failing here keeps the gate from silently
  # passing in CI when the catalogue is gone.
  echo "verify-copilot-catalogue: required catalogue ${CATALOGUE} not found." >&2
  exit 1
fi

# Resolve log range. When no arg provided, scan the full history — it's
# O(few hundred commits) and takes <200ms. That assumption only holds
# for non-shallow clones; in CI a shallow checkout would silently turn
# this gate into a partial-history scan (often only the merge commit
# is visible), so refuse to run unless the caller has either fetched
# full history or passed an explicit range.
if [[ -n "${RANGE}" ]]; then
  LOG_RANGE=("${RANGE}")
else
  if [[ "$(git rev-parse --is-shallow-repository 2>/dev/null)" == "true" ]]; then
    echo "verify-copilot-catalogue: refusing to scan full history in a shallow clone." >&2
    echo "verify-copilot-catalogue: fetch full history (e.g. 'fetch-depth: 0' on actions/checkout) or pass an explicit range, e.g. 'origin/main..HEAD'." >&2
    exit 1
  fi
  LOG_RANGE=()
fi

# Grep the log for fix commits mentioning a PR number. The expected
# subject shape is `fix(enh-<slug>): address Copilot review on PR #N`
# — codified in LESSONS.md + past commits. Non-matching commits are
# skipped.
fix_prs=()
while IFS= read -r line; do
  # Expect shape: "<sha> fix(enh-<slug>): address Copilot review on PR #<N> ..."
  # Extract N.
  if [[ "${line}" =~ fix\(enh-[a-z0-9-]+\):[[:space:]]*address[[:space:]]+Copilot[[:space:]]+review[[:space:]]+on[[:space:]]+PR[[:space:]]*#([0-9]+) ]]; then
    fix_prs+=("${BASH_REMATCH[1]}")
  fi
done < <(git log --oneline "${LOG_RANGE[@]}")

if [[ "${#fix_prs[@]}" -eq 0 ]]; then
  echo "verify-copilot-catalogue: no 'fix(enh-*): address Copilot review on PR #N' commits found. OK."
  exit 0
fi

# Deduplicate PR numbers — one fix commit per PR is the norm but a
# rebase / follow-up may produce more. We only need to verify each
# distinct PR number once.
#
# `readarray`/`mapfile` are bash 4+ builtins and missing on the default
# macOS bash (3.2). Loop via `while read` instead so contributors can
# run this locally without installing a newer bash.
unique_prs=()
while IFS= read -r pr; do
  [[ -n "${pr}" ]] || continue
  unique_prs+=("${pr}")
done < <(printf '%s\n' "${fix_prs[@]}" | sort -un)

missing=()
for pr in "${unique_prs[@]}"; do
  # A valid catalogue entry is a `### PR #<N>` heading followed by at
  # least one non-separator row below it. The simpler (and
  # equivalent) check: does the file contain a heading exactly
  # matching the PR number?
  if ! grep -Eq "^###[[:space:]]+PR[[:space:]]*#${pr}([[:space:]]|$)" "${CATALOGUE}"; then
    missing+=("PR #${pr} — no '### PR #${pr}' heading in ${CATALOGUE}")
    continue
  fi

  # Also verify the heading has at least one data row (a pipe-
  # delimited line that is NOT a separator `|---|...`). We extract
  # the block between `### PR #N` and the next `###` heading.
  block="$(awk -v pr="${pr}" '
    BEGIN { cap=0 }
    /^### PR #/ {
      if (cap) exit
      if ($0 ~ "^### PR #" pr "([[:space:]]|$)") { cap=1; next }
    }
    cap { print }
  ' "${CATALOGUE}")"

  # Look for a table row that is NOT the header or separator.
  # Header is "| Path | Category | ...", separator is "|---|...".
  data_row_count="$(printf '%s\n' "${block}" | awk '
    /^\|[^|]*\|[^|]*\|/ && !/\|---/ && !/\| Path \| Category/ { n++ }
    END { print (n+0) }
  ')"

  if [[ "${data_row_count:-0}" -eq 0 ]]; then
    missing+=("PR #${pr} — '### PR #${pr}' heading present but no data rows under it")
  fi
done

if [[ "${#missing[@]}" -eq 0 ]]; then
  echo "verify-copilot-catalogue: ${#unique_prs[@]} fix commit(s) checked; every PR has catalogue entries. OK."
  exit 0
fi

echo "verify-copilot-catalogue: ${#missing[@]} finding(s):"
printf '  - %s\n' "${missing[@]}"
echo
echo "Protocol (COPILOT-FINDINGS.md §Appendix): every 'fix(enh-*): address Copilot review on PR #N'"
echo "commit MUST touch docs/enhancement-plan/COPILOT-FINDINGS.md with at least one row under the"
echo "matching '### PR #N' heading. This is how PR16's distillation pass knows what to distill."
exit 1
