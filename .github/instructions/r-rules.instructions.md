---
applyTo: "**/*.{php,ts,tsx,js,jsx,yml,yaml}"
description: "AskMyDocs critical R-rules — auto-loaded by Copilot CLI + GitHub Copilot Code Review (path-scoped)"
---

# AskMyDocs critical R-rules (path-scoped, auto-loaded)

This file is loaded automatically by both:

- **GitHub Copilot CLI** (local critic loop per R40) — via the
  `.github/instructions/` directory convention. `applyTo:` constrains
  the file to the relevant source extensions so feedback is on-topic.
- **GitHub Copilot Code Review** (cloud loop per R36) — same path
  scoping; the instructions surface in the bot's review context.

The full R-rule catalogue (R1..R40) lives in
[CLAUDE.md](../../CLAUDE.md) and
[.github/copilot-instructions.md](../copilot-instructions.md). This
file is the **path-scoped critical subset** the two Copilot surfaces
must enforce on every diff, distilled from the rules that show up
most often in real reviews.

When reviewing code, weight findings against THESE rules first; everything
else is nitpick territory.

---

## R21 — Security invariants are atomic or absent

Every single-use / rate-limit / auth / nonce check that crosses a
concurrency boundary MUST hold the lock until the invariant is
RECORDED, otherwise the invariant does not exist.

- `DB::transaction(function () { $row = …->lockForUpdate()->first(); $row->update(…); })`
  — read + write in the SAME closure.
- Concurrency-sensitive state gets a DB-level `UNIQUE` constraint
  where the business rule demands it (not just code-path discipline).
- `lockForUpdate()` outside `DB::transaction()` is broken.
- Updating `used_at` / `consumed_at` AFTER the closure returns is
  broken — the lock is gone.

**Blast radius is RCE-class** (single-use bypass, TOCTOU, signed-URL
replay). One occurrence = one rule. Flag aggressively.

## R30 — Cross-tenant isolation on every tenant-aware query

Every Eloquent query against a tenant-aware table MUST be scoped to
the active tenant:

- `Model::forTenant($ctx->current())` (via `BelongsToTenant` trait),
  or
- explicit `->where('tenant_id', $tenantId)`.

Tenant-aware tables include: `knowledge_documents`,
`knowledge_chunks`, `chat_logs`, `conversations`, `messages`,
`kb_nodes`, `kb_edges`, `kb_canonical_audit`,
`project_memberships`, `kb_tags`, `knowledge_document_tags`,
`knowledge_document_acl`, `admin_command_audits`,
`admin_command_nonces`, `admin_insights_snapshots`,
`chat_filter_presets`, `notification_events`,
`notification_preferences`, `notification_digests`.

**Intentionally NOT tenant-scoped** (do NOT add a `tenant_id` query
to these — by design, see model docblocks + CLAUDE.md §4):
`embedding_cache` (text-hash keyed, shared across projects by
design — same paragraph in two projects reuses the embedding).

A bare `Model::where(...)` that does NOT include `tenant_id` (or a
scope that adds it) is a cross-tenant leak — GDPR catastrophe.
Composite indexes MUST start with `tenant_id`.

## R31 — `tenant_id` mandatory on tenant-aware models + migrations

Every Eloquent model under `app/Models/` representing a tenant-scoped
domain entity MUST:

- `use BelongsToTenant;` (auto-fills `tenant_id` from
  `TenantContext` on `creating`)
- list `'tenant_id'` in `$fillable` (or `$guarded = ['id']`).

Every new migration creating a tenant-aware table MUST:

- add `string('tenant_id', 50)->default('default')->index()`
- start every composite UNIQUE with `tenant_id`.

Architecture test `tests/Architecture/TenantIdMandatoryTest.php` is
the gate. New tenant-aware models that DON'T appear in that test's
enumeration are an R31 violation.

## R14 — Surface failures loudly; never 200 with empty/null/NaN

Every HTTP endpoint, renderer, log reader, and preview path MUST map
failure → the correct status code (404 missing, 500 unreadable, 503
downstream outage). FAILURE PATTERNS to flag:

- `return response()->json([…], 200)` in an error branch.
- `return ''`, `return '[]'`, `return null` from any service /
  controller a caller treats as success.
- FE `try { … } catch { return null }` that masks a 5xx as
  `isError=false`.
- `Math.max(...arr)` / `Math.min(...arr)` without an
  `arr.length === 0` guard (returns ±Infinity → NaN coordinates).
- Choosing HTTP status by **matching an exception MESSAGE** instead
  of exception TYPE.

Empty body on 200, zero-byte PDF on 200, `null` JSON on a 500 from a
dependency — all the same bug: the caller cannot distinguish success
from silent failure.

## R18 — Derive options from the DB, not from a literal subset

UI dropdowns / filters / CLI args / cache keys that map to a domain
MUST fetch the real domain (an API endpoint, a `distinct` query,
the same enum the BE uses).

- No literal hard-coded arrays of `project_key` / `event_type` /
  `channel` / `tenant_id` in FE.
- Backend filters MUST accept the same parameter surface the cache
  key encodes (no "7 days silently fixed" while the cache key encodes
  `(project, days)` generically).
- File-extension handling MUST cover every extension the pipeline
  accepts (`.md` AND `.markdown`).

A literal subset shipped today is a regression tomorrow when the
domain grows.

## R12 — Every user-visible UI change ships Playwright E2E coverage

Any PR that touches `frontend/src/**` or a controller rendering into
the SPA MUST include at least one `*.spec.ts` under `frontend/e2e/`
covering:

- one happy path
- one failure path (validation / 422 / 429 / network error / empty
  state) for the changed feature.

Scenarios use `getByTestId` / `getByRole` + accessible name — NEVER
CSS selectors. They wait on `data-state`, NEVER on
`waitForTimeout(N)`. Authed tests reuse
`playwright/.auth/admin.json`.

## R13 — E2E runs against real data; mock ONLY external services

`page.route(...)` is allowed ONLY for calls that LEAVE the
application boundary:

- AI provider (OpenRouter / OpenAI / Anthropic / Gemini / Regolo)
- email sending (Mailgun / SES / Mailersend)
- remote object storage, payment rails, OCR APIs.

Intercepting `/api/admin/*`, `/api/kb/*`, `/api/auth/*`,
`/sanctum/csrf-cookie`, `/conversations`, or any other internal
route turns the E2E into a glorified unit test — REJECT.

**Exception**: failure-mode injection against an internal route is
permitted ONLY when the same `.spec.ts` already covers the
real-data happy path, AND the injection block carries an
`R13: failure injection` marker comment.

`scripts/verify-e2e-real-data.sh` gates this in CI.

---

## Reviewer protocol

For each diff hunk:

1. Identify which R-rule(s) the hunk touches (controllers/services →
   R30/R31/R21/R14; FE → R12/R14/R18; migrations → R30/R31; YAML →
   GitHub Action hygiene R5).
2. Verify compliance line-by-line.
3. Report findings as `must-fix` (rule violation) or `nit`
   (formatting / comment style — let the cloud bot catch nits).
4. End the review with `SUMMARY: N must-fix, M nit` so the local
   critic loop can grep for `must-fix` count and decide whether to
   loop.

Findings that align with these R-rules are **non-optional**. The
local-critic-loop will not push until `0 must-fix` reported.
