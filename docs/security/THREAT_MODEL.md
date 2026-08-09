# AskMyDocs — Threat Model

Status: living document. Baseline established 2026-08-09 at commit `7844e9ee`
(branch `develop`). Companion documents:
[`SECURITY_CHECKLIST.md`](SECURITY_CHECKLIST.md) (control-by-control evidence),
[`AUDIT-FINDINGS-2026-08.md`](AUDIT-FINDINGS-2026-08.md) (findings log),
[`LORENZO_SECURITY_EXPERIENCE.md`](LORENZO_SECURITY_EXPERIENCE.md) (source-rule
disposition matrix).

Method note (SEC-INVENTORY-001 / SEC-COVERAGE-001): every population below is
**measured from the repository at the baseline commit**, not inferred from
conventions. Counts that can only be proven by booting the app (the resolved
routing table) are marked accordingly; PR 7 of the audit plan turns the resolved
route inventory into a blocking architecture test so the HTTP numbers stop being
a snapshot.

---

## 1. Entrypoint inventory (attack surface)

| Surface | Population (measured 2026-08-09 @ 7844e9ee) | Auth boundary |
|---|---|---|
| HTTP API + SPA routes | ~261 host routes + 13 vendor-mounted route groups (exploration report; resolved-router CI gate lands in PR 7) | Sanctum + `role:`/`can:` groups + `tenant.authorize`; public subset enumerated in §3 |
| Artisan CLI | 70 command classes in `app/Console/Commands/` | operator shell access; destructive commands need DB-backed single-use confirm tokens (R21) |
| Scheduler | 29 `TierOneSchedulerRegistrar::SLOTS` + `eval_nightly` + `ai_act_regulatory_poll` (both config-gated) + dynamic per-installation connector sync (`SerializedSyncScheduler`) | server-side only; `onOneServer()->withoutOverlapping()` |
| Queue jobs | 9 job classes in `app/Jobs/` + `App\Connectors\SerializedConnectorSyncJob` + package-owned jobs | payloads are server-minted; initiating identity re-authorized at effect time (SEC-AI-ACT-001 §4) |
| MCP tools | 46 tools on `KnowledgeBaseServer` (43 host + 3 vendor `Invite*`); **12 write-capable** (lack `#[IsReadOnly]`); count locked by `KnowledgeBaseServerRegistrationTest` | `EnforceMcpScope` + `McpToolAuthorizer`; bidirectional write-tool coverage test lands in PR 3 |
| SSE streams | 2 (chat stream, tabular-review `generate-stream`) | Sanctum; tabular-review stream tenant scoping verified in PR 4 |
| AI providers (egress) | 5 production (openai, anthropic, gemini, openrouter, regolo) + `FakeProvider` (E2E only) | SEC-LLM-001 gates; provider/model allow-list choke point lands in PR 8 |
| Public widget | KITT embeddable widget — public route group (11 routes per exploration report), session-throttled | anonymous; widget PII masker + tool validator + snapshot validator |
| Desktop (Tauri) | `desktop/` webview app | native HTTP client — browser CORS does not apply (see rule-security-runtime-browser §applicability); capabilities hardening in PR 11 |
| Uploads | KB ingest API (batch ≤ 100), UI staging buffer, connector-fetched documents (IMAP/cloud) | magic-byte/MIME hardening lands in PR 5 |
| Exports | PDF (`Dompdf`/`Browsershot` renderers); **no CSV/spreadsheet export shipped today** | formula-injection rule dormant until a spreadsheet export exists (SANO §in findings log) |
| Inbound webhooks | **0** — no inbound webhook route exists | `SEC-WEBHOOK-001` dormant; rule applies the day one is added |
| Outbound webhooks | notification channels (`AbstractWebhookChannel` family), digest webhooks (`SendDigestWebhookJob`), external notification job | SSRF guard lands in PR 2 |

## 2. Trust boundaries

1. **Anonymous → authenticated.** Public perimeter: widget routes, guest SPA
   shell pages, `POST /api/auth/register` (invite-code ALWAYS required,
   throttled 6/min/IP), onboarding company flow. Everything else is Sanctum.
2. **Role ladder (per tenant).** viewer → editor → dpo → admin → super-admin;
   `system-admin` is the only global role (`platform.admin` gate) and still
   requires membership for tenant routes. R32 matrix is the regression gate.
3. **Tenant boundary.** `TenantContext` resolved from `X-Tenant-Id` +
   membership check (`tenant.authorize`); R30 `forTenant()` scoping on every
   tenant-aware table. The `kb_edges` FK is project-scoped, NOT tenant-scoped —
   isolation is application-layer by design. Known deliberate exception: IMAP
   connection serialization lock is cross-tenant (physical mailbox resource).
   `embedding_cache` is deliberately cross-tenant (documented exclusion).
4. **Model boundary.** The LLM is untrusted (SEC-LLM-001). Retrieved chunks,
   tool results, widget snapshots stay `user`-role data; output is data, never
   code; renderer allow-lists on the widget/SPA side.
5. **MCP agent boundary.** Tools are the AI channel's API; they must never
   exceed the equivalent UI/API capability. Write tools (12) require the
   authorization choke point (PR 3 proves coverage bidirectionally).
6. **Provider/egress boundary.** Every AI call is a data-subprocessor decision;
   FinOps meters all paths (SDK lifecycle hook + `AiCallMeter` for the residual
   raw-Http with-tools turn). PII redaction gates text-bearing egress.
7. **Connector boundary (ingress).** IMAP/cloud connectors pull third-party
   content into the KB; content is untrusted input to chunkers/parsers and,
   transitively, to prompts (injection containment per SEC-LLM-001 gate 4).
8. **Desktop webview.** Tauri app talks to the API as a native client; CORS is
   not a boundary there — TLS + token auth + origin-independent authorization
   are (PR 11 hardens capabilities/CSP).

## 3. Public (unauthenticated) perimeter

Measured set (to be locked by the PR 7 route-exposure gate): widget public
routes, `POST /api/auth/register` (+ CSRF cookie), guest SPA shell pages served
by `SpaController`, `/healthz`. `/testing/*` routes exist ONLY under
`APP_ENV=testing` (fail-closed env gate — verified in the findings log).
Every other route group requires `auth:sanctum` at minimum.

## 4. Key data flows (source → sink)

| Flow | Untrusted source | Sinks to protect |
|---|---|---|
| Chat turn | user question, retrieved chunks, conversation history | prompt (injection containment), provider egress (PII), chat_logs (retention), SSE to browser (safe render) |
| Ingestion | markdown bytes (API/CLI/connector), YAML frontmatter | KB disk path (`KbPath`), DB rows, `CanonicalParser` (symfony/yaml safe mode), embeddings egress |
| Widget | page snapshot, anonymous input | PII masker → provider; tool proposals → `WidgetToolValidator` closed contract → DOM executor allow-list |
| MCP turn | tool arguments from the model | `McpToolAuthorizer` (identity+tenant+permission) → core services → audit |
| Connector sync | mailbox/cloud content, OAuth tokens | credential vault, chunkers, ingestion pipeline, per-mailbox serialized IMAP socket |
| Admin ops | operator input | whitelisted Artisan runner (`config/admin.php allowed_commands` + R21 confirm tokens), audit tables |
| Promotion | LLM-drafted markdown | human-gated `CanonicalWriter` (ADR 0003 — suggest/candidates write NOTHING) |
| Outbound notifications | tenant-configured webhook URLs | SSRF guard (PR 2), bounded responses |

## 5. Assets

KB corpus (per-tenant), PII vault + detokenization keys, Sanctum tokens +
sessions, connector credentials (IMAP passwords / OAuth refresh tokens),
`APP_KEY`, provider API keys, audit trails (`kb_canonical_audit`,
`admin_command_audit` — immutable/forensic), FinOps ledger, invite codes.

## 6. What this repository cannot prove (infrastructure residuals)

Edge/proxy header emission, DNS + SPF/DKIM/DMARC state, cloud IAM, Redis
TLS/auth posture, DB grants, real log retention on hosts, branch-protection
and commit-signature platform settings. These live in
`SECURITY_CHECKLIST.md` §6 and remain OPEN until runtime evidence is attached
— repository documentation never claims to prove external state
(SEC-DNS-001 / rule-security-control-coverage §7).
