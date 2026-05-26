# Enterprise Completeness Roadmap

Backlog of **incomplete / stubbed / parked** surfaces discovered during the
2026-05 completeness audit. Each item below was **verified against the
current code** (not just a comment reference). Two reported items were
overstated and are recorded as "corrected" so the record is honest.

Effort key: **S** ≈ ≤1 day · **M** ≈ 2–4 days · **L** ≈ ≥1 week.
Status: ⏳ planned · 🔧 partially mitigated · ✅ shipped.

> Audit method: two read-only agents cross-checked each claim against the
> real files (controllers, services, routes, migrations, frontend). 13
> confirmed, 2 corrected.

---

## 🔴 High priority (user-visible "dead" or misleading)

| # | Item | Current state | Scope to reach enterprise-grade | Effort |
|---|------|---------------|----------------------------------|--------|
| R1 | **Command palette wiring** | `CommandPalette.tsx` is a static `ITEMS` array (incl. sample docs); every result `onClick` only calls `setOpen(false)` — no navigation, no search | Wire real TanStack-router navigation per item + debounced live KB/doc search (`/api/kb/documents/search`) + recent-command persistence | M |
| R2 | **Admin invite resend email** | `UserController::resendInvite` no longer lies (v8.0.3: logs warning + `email_sent:false`) but still sends no mail | Queued Mailable/Notification with signed accept-invite link + rate-limit + audit row | S/M · 🔧 |
| R3 | **Compliance tenant input (now dead)** | `ComplianceReportsView.tsx` seeds `tenantId='tenant-acme'` and posts it; v8.0.3 made the BE derive tenant server-side, so this input is now **inert + misleading** | Remove the input, drop `tenant_id` from the generate payload, key the list query off the resolved session tenant | S |
| R4 | **Admin rail orphan routes** | `KbHealth`, `PiiRedactor`, `Flows`, `EvalHarness` routes exist but have **no nav-rail entry** — reachable only by typing the URL | Add the four RBAC-gated `RailEntry` rows + a `section` id per page in `AdminShell.tsx` | S |

## 🟠 Medium priority (feature works partially / silently degraded)

| # | Item | Current state | Scope | Effort |
|---|------|---------------|-------|--------|
| R5 | **Google Slides chunking** | `OfficeDocChunker::chunkSlidesDeferred()` emits ONE placeholder chunk (`skip_reason=gslide-deferred`); `drive_gslide` docs are indexed but not usefully searchable | Slide-render → text/OCR pipeline producing per-slide chunks + speaker notes | M/L |
| R6 | **Weekly notification digest** | `notification_digests` table + model + tests exist but **nothing writes or reads them**; no `notifications:digest-weekly` command/job/schedule (channels Slack/Teams/Discord/Webhook already work) | Incremental dispatcher upsert + scheduled weekly render+send job (fan-out to per-user enabled channels, stamp `sent_at`/`recipients_count`) + register in `TierOneSchedulerRegistrar` | M |
| R7 | **PII strategy switching** | `PiiStrategyController` exposes only `GET`; switching strategy requires an env/config change | DB-backed per-tenant strategy override + `PATCH` endpoint + Gate + audit, validated against the factory's available names | M |
| R8 | **Tabular Review progressive-paint** | BE SSE `/tabular-reviews/{id}/generate-stream` shipped, but FE only calls the synchronous `/generate` | POST-SSE fetch client + per-cell live status painting on the show page | M |
| R9 | **Two-Factor Authentication (TOTP)** | `TwoFactorController` enable/verify/disable all return `501`; `AUTH_2FA_ENABLED` default false | TOTP enrollment + recovery codes + challenge/verify + Sanctum step-up, secrets encrypted at rest, FE enroll/verify screens | L |
| R10 | **Chat related-graph panel** | `chat.store` has `showGraph`/`toggleGraph()` but no panel is mounted and the toggle is never invoked | Right-rail graph panel (reuse admin KB graph viewer) seeded from response-citation `kb_nodes`, with a header toggle | L |

## 🟡 Low priority (polish / bounded today)

| # | Item | Current state | Scope | Effort |
|---|------|---------------|-------|--------|
| R11 | **Activity-log write side** | `spatie/laravel-activitylog` **is installed** and the read tab works when the table exists, but **no model uses `LogsActivity`** and nothing records events — the tab is always empty | Add `LogsActivity` to key admin-mutated models (users, roles, KB docs, commands) + record domain events; wire `activitylog:clean` into the scheduler | S/M |
| R12 | **Evernote ENEX import UI** | BE OAuth connector + `.enex` bulk-import endpoint **exist and work**; only the admin SPA upload affordance is missing | Upload form in the connector admin screen wired to the import endpoint with progress/result display | S |
| R13 | **Tabular Reviews + Workflows pagination** | Both hardcode `per_page=100` with no Prev/Next; fine until 100 rows | Thread `current_page` through the api clients + a shared Prev/Next pager bound to `meta.last_page` | S |

## 🖥️ Frontend shell completeness (audit round 2)

The chat + app-shell still lean on dev-only seed data and local-only state.
All verified CONFIRMED against current code.

| # | Item | Current state | Scope | Effort |
|---|------|---------------|-------|--------|
| R14 | **Dev-only seed in production runtime** | `frontend/src/lib/seed.ts` ("Dev-only seed data") is imported at runtime by `AppShell.tsx` + `ChatView.tsx` (`PROJECTS`, `USERS`) | Replace with the backend project list + auth-store user; remove `seed.ts` from the runtime path | M |
| R15 | **Chat pinned to first seeded project** | `ChatView.tsx` `const project = PROJECTS[0]` drives conversation create + sidebar + thread + composer — ignores the Topbar switcher | Consume a shared active-project context as `projectKey` across the chat surface | M |
| R16 | **Project switcher is cosmetic** | Selection lives only in `AppShell` local `projectIndex` state; no shared store; features ignore it | Lift active project to a context/store consumed by chat + admin filters | M |
| R17 | **Hardcoded chat model label** | `ChatView.tsx` `useState('claude-sonnet-4.5')` shown in header + composer; not derived from backend | Derive from config or per-turn response metadata | S |
| R18 | **Conversation list not project-scoped** | `ConversationList.tsx` uses `projectKey` only on create; `listConversations` is global | Add a `project_key` filter param + query-key dimension | S/M |
| R19 | **Users drawer hardcoded project keys** | `UsersView.tsx` `DEFAULT_PROJECT_KEYS = ['hr-portal','engineering']` feeds the membership editor (R18-rule violation) | Fetch real project keys from a distinct-keys endpoint | S |
| R20 | **Users role filter hardcoded** | `UsersView.tsx` filter uses a 4-item literal while `useRoles()` data is fetched but unused for it | Derive filter options from the roles query | S |
| R21 | **Seed-user fallback in shell** | `AppShell.tsx` backs the sidebar identity with `USERS[0]` ("Elena Ricci"); role/color always seeded | Render only the real auth-store user; skeleton/empty when absent | S |
| R22 | **Static shell indicators** | Topbar "All systems operational", Sidebar insights badge `5`, palette version `v2.4.0` all hardcoded | Wire to `/api/admin/health`, unread-insights count, build version | S |

> Round-2 overlaps already tracked above: command palette → R1, compliance
> tenant input → R3, KB Health orphan route → R4, Evernote ENEX UI → R12.

## ⚙️ Backend cross-tenant (Audit #3) — status

The third audit's cross-tenant findings were folded into the **v8.0.3**
security PR, not this roadmap (same R30 theme):
- **Fixed in v8.0.3:** all 10 MCP tools `forTenant`-scoped; `AiInsightsService`
  scoped + N+1 batched; `InsightsComputeCommand` now writes one snapshot
  per tenant (new `(tenant_id, snapshot_date)` unique); `ProvenanceChain`
  scoped; `Conversation` route-binding tenant-scoped; `KbValidateCanonical`
  scoped. The architecture test now also scans `app/Mcp` + `app/Console` +
  `app/Compliance`.
- **Already fixed earlier in the same PR:** ComplianceReport index/store,
  KbTree, AdminInsights, GraphExpander/RejectedApproachInjector (the audit
  was run against `main`).

---

## 🔎 Retrieval & search quality (audit round 4)

Round-4 surfaced search-engine depth gaps. The **correctness/crash/security**
items were fixed in v8.0.3 (embedding-cache batch dedupe + `firstOrCreate`;
`KbChatRequest` filter `max:N` caps; compliance `promoted` now derived from
the `kb_canonical_audit` `promoted` event, not `updated_at`; stale
notification-event docblocks refreshed). The items below are larger
search-quality / perf investments for a future cycle.

| # | Item | Current state | Scope | Effort |
|---|------|---------------|-------|--------|
| R23 | **`connector_types` retrieval filter** | accepted + validated in `KbChatRequest` but `KbSearchService::applyFilters` applies NO clause (silent no-op; deferred "until a `connector_type` column exists" — currently in `metadata.connector` JSON) | Add a `connector_type` column + backfill, then wire the filter; until then reject or surface "unsupported" in `meta` | M |
| R24 | **Per-document FTS language** | `KbSearchService` feeds a single `config('kb.hybrid_search.fts_language')` (italian default) to `to_tsvector`/`plainto_tsquery`; the `knowledge_documents.language` column is ignored → wrong stemming on mixed IT/EN corpora | Per-row tsvector language (generated column) + per-query language detection | L |
| R25 | **Hybrid score normalisation before rerank** | after RRF merge, `vector_score` is set to the RRF magnitude (~0.016) for FTS-only hits, then re-enters the reranker with cosine-tuned weights (`0.6·vec`) → FTS-only hits suppressed | Min-max / rank-normalise scores into 0–1 before the reranker fusion | M |
| R26 | **Runner-up demotion reason granularity** | every runner-up gets `reason='not_in_top_k'` (documented MVP); no `below_rerank_threshold` / `demoted_by_status_penalty` | Compute the real demotion cause per runner-up for search-auditing UX | M |
| R27 | **MarkdownChunker oversize-paragraph split** | a single paragraph over the hard cap is emitted whole; the embedding provider then silently truncates it | Sub-split on sentence/char boundary when a paragraph exceeds the cap | S |
| R28 | **DocumentIngestor batched chunk upsert** | `updateOrCreate` per chunk in a loop → N×2 round-trips on large docs (correct but slow) | Batched upsert keyed on `(knowledge_document_id, chunk_hash)` | M |
| R29 | **Compliance Reports FE hardening** | `runVerify` has no try/catch (R14 silent failure); the tenant/quarter/year controls lack `<label>`/`aria-label` (R15) | try/catch → error state per row; accessible labels; bundle with the R3 compliance-FE rework | S |

> Round-4 already-fixed-in-v8.0.3: embedding-cache batch dedupe (crash),
> filter `max:N` caps (DoS), compliance `promoted` semantics, notification
> docblock drift. Already-fixed-earlier-in-PR: chat-filter-preset +
> Conversation tenant scoping, GraphExpander/RejectedApproachInjector.
> New tests added: duplicate-text embedding batch; compliance promoted-delta.

---

## Corrections (reported but overstated)

- **"ActivityLog package not installed"** — FALSE. `spatie/laravel-activitylog`
  IS in `composer.json`; the read path is real. The genuine residual gap is
  the missing write-side (R11 above).
- **"Evernote: no OAuth / no connector"** — FALSE. A full OAuth connector is
  configured in `config/connectors.php`, the package is required, and the
  `.enex` import endpoint works. The only gap is the FE upload UI (R12).

---

*Generated 2026-05-26 from the completeness audit. Items are independent;
sequence them into future `vX.Y` cycles per business priority. R3 is the
most time-sensitive — it was made inert by the v8.0.3 security hotfix and
should ship in the same release window or immediately after.*
