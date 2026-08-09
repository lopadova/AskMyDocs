# Rule: AI/MCP/widget actions

## Identifier

`SEC-AI-ACT-001`

## Scope

Any model-proposed tool or browser action that reads protected data or produces
an effect through AI, MCP, the public widget, a queue, HTTP, filesystem or a
third party.

## Read actions

- Registry membership is server-owned and closed; unknown tool/action/field is
  rejected, never ignored or forwarded.
- Authorization runs before argument validation and data access, with the
  immutable initiating identity, current role/permission and tenant scope.
- Results are bounded, PII-filtered, audited and returned to the model as
  untrusted user data.
- No implementation may call a lower-level `execute()` method to skip the common
  authorization/audit choke point.

## Mutating actions

The model may propose an action; it never confirms itself. Before any effect:

1. Show a server-generated preview of the real effect, scope, record count,
   values and reversibility. Confirmation is explicit and bound to that single
   operation and its validated arguments.
2. Enforce a code-level blast-radius cap. Arbitrary SQL/shell/path, deletion,
   payments, permissions/tokens and external communications are prohibited unless
   a dedicated security design explicitly authorizes them.
3. Bind an opaque server-generated idempotency key to tenant, initiating user,
   session, tool/action and canonical argument fingerprint. Reserve atomically
   with a database uniqueness constraint; concurrent retries return the recorded
   result and never execute twice.
4. Re-check authorization and ownership immediately before the effect. Async jobs
   reload the initiator and current permissions; context proves attribution but
   does not freeze revoked privileges.
5. Keep correlated multi-record writes in one transaction. Audit accepted,
   denied, failed and replayed attempts at the common choke point with masked and
   bounded arguments. Audit failure is fail-closed for mutating actions.

## Browser/host actions

- `WidgetAiToolRegistry`, `WidgetToolValidator` and the frontend executor form one
  contract. CI/tests compare allowed types and consumed fields in both directions.
- Strip undeclared fields before transport. A future executor `case` must not
  become available automatically.
- Selector policy is explicit per action: required and constrained to the allowed
  page/widget target, or forbidden. Missing config/registry denies every action.
- Model data never becomes HTML, selector code, script, navigation target or an
  event handler. Fixed SVG/style constants are not a precedent for untrusted data.

## Required tests

Test denial for missing/mismatched identity, permission and tenant; unknown and
stale registry entries; undeclared fields; invalid target/selector; no human
confirmation; replay and real concurrency; audit failure; rollback; and safe
diagnostics. When the first new mutating tool is introduced, its confirmation,
idempotency, transaction and audit tests land in the same PR.
