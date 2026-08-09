# Rule: security controls must cover the effective population

## Identifiers

`SEC-FAILCLOSED-001`, `SEC-COVERAGE-001`, `SEC-INVENTORY-001`,
`SEC-RACE-001`, `SEC-RAWSQL-001`, `SEC-OWASP-001`, `SEC-CHECKLIST-001`.

## Rule

A security control is valid only when it covers every applicable object and path,
including legacy, fallback, queue, stream, CLI, MCP, widget, retry and error paths.

For each new or changed control:

1. Inventory the effective population from code and runtime registrations; do not
   rely only on filenames, conventions or one known instance.
2. Compare policy and implementation in both directions. Undeclared executable
   behavior and stale policy entries both fail.
3. Make unknown/missing/malformed state fail closed. If availability requires a
   fallback, choose the privacy/authorization-preserving behavior and document it.
4. Protect check-and-record invariants atomically with transactions/locks and DB
   uniqueness where required. Race tests must use genuinely concurrent workers.
5. Inventory raw SQL and dynamic query fragments; bind values and allow-list any
   identifier/order fragment that cannot be parameterized.
6. Add a negative regression test and, when absence/drift cannot be unit-tested,
   a deterministic architecture/contract analyzer in CI.
7. Record what was inspected and any external/infrastructure-only residual. Do not
   claim repository evidence proves Cloudflare, DNS, IAM, Redis or database grants.

Generate the HTTP inventory from Laravel's resolved routing table, including group
middleware and aliases. Archive release snapshots and diff them; public mutating
routes require an explicit, reasoned declaration and stale declarations fail. A
bootstrap failure or empty/partial router is “not executed”, never a clean result.

Security documentation and checklist state must distinguish implemented code,
tested coverage, deployment configuration and infrastructure verification.
