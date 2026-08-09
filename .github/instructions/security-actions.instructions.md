---
applyTo: "**/*.{php,ts,tsx,js,jsx}"
description: "AI/MCP/widget action contract (SEC-AI-ACT-001)"
---

# SEC-AI-ACT-001 — models propose; code authorizes; humans confirm effects

- Registry membership and accepted fields are closed/server-owned. Unknown tool,
  action, field or selector policy denies; strip undeclared fields.
- One mandatory choke point performs authorization before validation/access and
  writes audit for allowed, denied, failed and replayed calls. No direct `execute()` bypass.
- Read tools use current permission plus tenant/ownership scope and bounded output.
- Mutating effects require a server-generated preview and explicit per-operation
  confirmation bound to validated arguments. The model cannot confirm itself.
- Bind a server-generated idempotency token to tenant, initiator, session, operation
  and canonical arguments. Reserve it atomically with DB uniqueness; concurrency
  produces one effect and stable replay.
- Re-authorize immediately before effects; queued work reloads the initiator and
  current permissions. Use transactions and hard blast-radius caps.
- Audit failure blocks mutating effects. Audit fields are masked/bounded and include
  real initiator, operation, outcome, correlation and replay status.
- Browser actions have explicit type/field/selector policies matched in both
  directions with the executor. Model values never become HTML/script/navigation.

Required negative tests: anonymous/mismatched identity, permission, cross-tenant,
unknown/stale registry, undeclared field, invalid selector, missing confirmation,
real concurrent replay, audit failure, rollback and safe error response.

Canonical details: `.claude/rules/rule-security-ai-actions.md`.
