#!/usr/bin/env bash
#
# scripts/verify-e2e-real-data.sh
#
# Enforces R13 (CLAUDE.md): Playwright E2E scenarios run against the
# real Laravel app and real DB. Route interception — whether via
# `page.route(...)`, `context.route(...)`, or `page.context().route(...)`
# — is only allowed against external-service boundaries (AI providers,
# email, payment, OCR, remote object storage) or against internal
# routes as a DELIBERATE failure injection flagged with a
# `R13: failure injection` marker comment.
#
# Exit code:
#   0 — no offending routes; suite is honest E2E.
#   1 — at least one interception targets an internal path without
#       the failure-injection marker on the preceding lines, or the
#       default target path is missing (which would silently disable
#       the CI gate).
#
# Usage:
#   bash scripts/verify-e2e-real-data.sh
#   bash scripts/verify-e2e-real-data.sh frontend/e2e/admin-dashboard.spec.ts
#
# CI integration: run after `npm ci` and before `npm run e2e`.

set -euo pipefail

DEFAULT_TARGET="frontend/e2e"
TARGET_GLOB="${1:-${DEFAULT_TARGET}}"
EXPLICIT_TARGET=0
if [[ $# -gt 0 ]]; then
  EXPLICIT_TARGET=1
fi

# Patterns considered "internal" — intercepting any of these on a
# happy path defeats the purpose of E2E. Extend conservatively;
# over-flagging is better than under-flagging. Literal substring match.
INTERNAL_PATTERNS=(
  '/api/admin/'
  '/api/kb/'
  '/api/auth/'
  '/sanctum/csrf-cookie'
  '/login'
  '/logout'
  '/conversations'
  '/testing/'
)

# Patterns considered "external boundaries" — always allowed. These
# are LITERAL host substrings; matched with grep -F (fixed string) so
# unescaped `.` doesn't accidentally widen the allowlist.
#
# NOTE: keep this list in sync with CLAUDE.md R13 and the
# playwright-e2e SKILL.md. SES is intentionally absent — add
# `email-smtp.us-east-1.amazonaws.com` (or the concrete host you use)
# if you actually stub SES in a test.
EXTERNAL_PATTERNS=(
  'api.openrouter.ai'
  'api.openai.com'
  'api.anthropic.com'
  'generativelanguage.googleapis.com'
  'regolo.ai'
  'mailgun.net'
  'sendgrid.com'
  'api.mailersend.com'
  's3.amazonaws.com'
  'storage.googleapis.com'
  'r2.cloudflarestorage.com'
  'api.stripe.com'
)

# Controller endpoints that themselves invoke an external provider.
# Allowed even though they look "internal", because the external
# call is the reason for stubbing. These ARE regexes (matched with
# -E) so Playwright glob patterns like `**/conversations/*/messages`
# can be captured without over-escaping.
EXTERNAL_PROXY_PATTERNS=(
  '/conversations/[^"]*/messages'   # POST triggers AI provider
  '/api/kb/promotion/promote'       # dispatches ingestion → embeddings
  '/api/kb/ingest'                  # embeddings via provider
)

# R13 enforcement must never be silently skipped. When the DEFAULT
# target is missing, fail loudly — a renamed directory is how the
# gate disappears without anyone noticing. An explicitly passed
# target that doesn't exist is still a hard error.
if [[ ! -d "${TARGET_GLOB}" && ! -f "${TARGET_GLOB}" ]]; then
  if [[ "${EXPLICIT_TARGET}" -eq 0 ]]; then
    echo "verify-e2e-real-data: default target '${DEFAULT_TARGET}' not found."
    echo "R13 gate requires this path to exist. Either create '${DEFAULT_TARGET}/'"
    echo "with your E2E specs, or pass an explicit path argument."
    exit 1
  fi
  echo "verify-e2e-real-data: target '${TARGET_GLOB}' not found."
  exit 1
fi

offenders=()

# Grep every route interception call with file + line info. Covers
# all three Playwright forms:
#   page.route(...)
#   context.route(...)
#   page.context().route(...)
#
# We look at the argument string on the same line; a newline between
# the call and its pattern is unusual in the codebase.
INTERCEPT_REGEX='(page|context|page\.context\(\))\.route\('

matches="$(grep -R --include='*.ts' --include='*.tsx' -nE "${INTERCEPT_REGEX}" "${TARGET_GLOB}" || true)"

if [[ -z "${matches}" ]]; then
  echo "verify-e2e-real-data: no page.route()/context.route() calls in '${TARGET_GLOB}'. OK."
  exit 0
fi

while IFS= read -r hit; do
  # hit looks like: frontend/e2e/chat.spec.ts:74: await page.route('...')
  file="$(printf '%s' "$hit" | cut -d: -f1)"
  line="$(printf '%s' "$hit" | cut -d: -f2)"
  content="$(printf '%s' "$hit" | cut -d: -f3-)"

  # EXTERNAL_PATTERNS are literal host substrings — use grep -F so
  # the `.` in `api.openai.com` stays a literal dot, not "any char".
  external=0
  for p in "${EXTERNAL_PATTERNS[@]}"; do
    if printf '%s' "$content" | grep -Fq "${p}"; then
      external=1
      break
    fi
  done

  # EXTERNAL_PROXY_PATTERNS are regexes by design — keep grep -E.
  if [[ "${external}" -eq 0 ]]; then
    for p in "${EXTERNAL_PROXY_PATTERNS[@]}"; do
      if printf '%s' "$content" | grep -Eq "${p}"; then
        external=1
        break
      fi
    done
  fi

  [[ "${external}" -eq 1 ]] && continue

  # Is the interception against an internal pattern?
  internal=0
  for p in "${INTERNAL_PATTERNS[@]}"; do
    if printf '%s' "$content" | grep -Fq "${p}"; then
      internal=1
      break
    fi
  done

  # Not obviously internal and not external-allowed — flag as
  # unknown so reviewers can extend the allowlists explicitly.
  if [[ "${internal}" -eq 0 ]]; then
    offenders+=("${file}:${line} — route() target not on any allowlist (extend EXTERNAL_PATTERNS / EXTERNAL_PROXY_PATTERNS in $(basename "$0") if external, or re-write to use real data).")
    continue
  fi

  # Internal route stubbed — only OK if flagged as failure injection
  # in a comment on one of the five preceding lines.
  start=$(( line > 5 ? line - 5 : 1 ))
  if sed -n "${start},${line}p" "$file" 2>/dev/null | grep -Eq 'R13:?[[:space:]]*failure injection'; then
    continue
  fi

  offenders+=("${file}:${line} — intercepts an internal route without 'R13: failure injection' marker on the preceding 5 lines. Either replace with real seeded data or add the marker comment.")
done <<<"${matches}"

if [[ "${#offenders[@]}" -eq 0 ]]; then
  echo "verify-e2e-real-data: all route() interceptions in '${TARGET_GLOB}' are honest (external or marked failure injections). OK."
  exit 0
fi

echo "verify-e2e-real-data: ${#offenders[@]} finding(s):"
printf '  - %s\n' "${offenders[@]}"
echo
echo "R13 says: E2E runs against the real stack. page.route()/context.route() is only for external-service boundaries or flagged failure injections."
echo "See .claude/skills/playwright-e2e/SKILL.md and .claude/skills/playwright-e2e-templates/SKILL.md for correct patterns."
exit 1
