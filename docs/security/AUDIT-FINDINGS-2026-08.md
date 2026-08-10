# AskMyDocs — Security Audit Findings (2026-08)

Baseline: commit `7844e9ee` on `develop`, audit opened 2026-08-09. Format per the
`security-audit` skill: each finding carries ID/rule, severity, affected
path+population, concrete impact, source→sink evidence, proof/test, focused
remediation and residual infrastructure step. Conditional severity is labelled.
A **SANO** section records reviewed false positives and sound boundaries so
future audits do not repeat the work. **No real PII, tokens or secrets appear in
this document.**

Severity scale: Critical (public/unauth data exposure or RCE-class) · High
(cross-tenant / privilege / injection with practical path) · Medium
(defense-in-depth gap, exploitable under a precondition) · Low (hardening).

---

## Open findings

### F-01 — No security response headers (CSP / HSTS / XFO / nosniff) — Medium
- **Rule/ID:** `SEC-CSP-001`, `SEC-TLS-001`, response-headers, csp-nonce-cache.
- **Population:** every HTML response from `SpaController` + `resources/views/app.blade.php` + the widget bootstrap; measured — no middleware emits CSP/HSTS/X-Frame-Options/X-Content-Type-Options today.
- **Impact:** without CSP a single reflected/stored script (or a compromised dependency in the SPA bundle) executes unconstrained; without HSTS a first-hit downgrade is possible at the edge; without `nosniff`/`X-Frame-Options` MIME-sniffing and clickjacking are open.
- **Source→sink:** untrusted content (KB text, chat answers) → React render → browser, with no CSP backstop if an escaping bug or a dependency is subverted.
- **Remediation:** PR 1 — `app/Http/Middleware/SecurityHeaders.php`, nonce-based CSP compatible with Vite/SPA + widget, HSTS gated to production posture, exact-value feature tests.
- **Residual (infra):** headers actually emitted at the edge — §6 items 8/9.

### F-02 — Outbound requests have no SSRF guard — High (conditional)
- **Rule/ID:** `SEC-SSRF-001`, http-client-service, circuit-breaker.
- **Population:** `app/Notifications/Channels/AbstractWebhookChannel.php` (+ Discord/Slack/Teams/Webhook subclasses), `app/Jobs/SendDigestWebhookJob.php`, `app/Jobs/SendExternalNotificationJob.php`, and host fetches in widget theme/intro services. All use raw `Http::` with a tenant/operator-supplied URL and no scheme/IP validation.
- **Impact (conditional on who can set the webhook URL):** an operator or tenant-admin who can configure a notification/digest webhook can point it at `http://169.254.169.254/…` (cloud metadata), `http://127.0.0.1:…` or an internal service, turning the app server into an SSRF pivot. Severity is High where tenant-admins configure webhooks; Medium if restricted to platform operators.
- **Source→sink:** configured webhook URL (DB) → `Http::post($url)` in a queued job → arbitrary internal network egress.
- **Remediation:** PR 2 — single guard (https-only, DNS→IP reject of private/loopback/link-local/`169.254.169.254`, redirect re-validation, timeout + response-size cap) reused by all four sinks; negative tests for internal IP / metadata / DNS-rebind / internal-redirect.
- **Residual:** none once the guard covers every sink (verify no other raw `Http::` egress to a user-controlled host).

### F-03 — MCP write-tool authorization not proven across the population — High
- **Rule/ID:** `SEC-AI-ACT-001`, AISVS C5/C9/C10, multi-surface-gates (R44).
- **Population:** 12 write-capable MCP tools lacking `#[IsReadOnly]`: `KbApplySuggestion`, `KbBuildWikiIndex`, `KbDetokenize`, `KbRebuildWikiLinks`, `KbSetEvidenceTier`, `KbSynthesizeConcepts`, `KbWikiHub`, `KbWikiLint`, `KbWikiMaintain`, `KbWikiNavigate`, `KbWikiPromote`, `KbWikiReview` — measured via `grep -L IsReadOnly app/Mcp/Tools/*.php`.
- **Impact:** `EnforceMcpScope`/`McpToolAuthorizer` exist, but there is no bidirectional test proving **every** write tool routes through the authorization choke point with initiating identity + permission + tenant scope **before** validation/data access. A future write tool that forgets the gate ships green (same blast-radius argument as R32).
- **Source→sink:** model-proposed tool call → tool `handle()` → core service mutation; the gap is a tool that reaches the mutation without the authorizer.
- **Remediation:** PR 3 — architecture test enumerating write-capable tools (no `IsReadOnly`) and asserting authorizer coverage; negative test (write tool without permission → deny, audited). `KbDetokenize` (reverses PII redaction) is the highest-value target and gets an explicit cross-tenant + permission negative test.
- **Residual:** none once the enumeration test is the gate.

### F-04 — Cross-tenant IDOR on the tabular-review SSE stream — **High (confirmed live leak)** — remediation in review (PR 4 #414)
- **Rule/ID:** `SEC-IDOR-001`, security-boundaries (R30).
- **Population:** `app/Http/Controllers/Api/Admin/TabularReviewStreamController.php`, route `POST /api/admin/tabular-reviews/{id}/generate-stream` — middleware was `auth.sse:sanctum` + `can:viewTabularReviews`, **no** `tenant.authorize`.
- **Impact — CORRECTED FROM THE INITIAL FILING:** the initial audit judged this a defense-in-depth gap because the controller scopes with `forTenant(TenantContext::current())`. **Empirical verification proved that wrong.** `ResolveTenant` (global) sets `TenantContext` from the inbound `X-Tenant-Id` header **without any membership check**, and the route lacked `tenant.authorize`. So an admin holding the *global* `viewTabularReviews` permission could send `X-Tenant-Id: victim` → `TenantContext` becomes `victim` → `forTenant('victim')` finds the review → **HTTP 200 streaming another tenant's tabular-review data.** Confirmed in a Testbench harness with `ResolveTenant` active: status **200** with the victim review streamed. This is a live cross-tenant data leak (High).
- **Source→sink:** attacker `X-Tenant-Id: victim` header → `ResolveTenant` (no membership check) → `TenantContext='victim'` → controller `forTenant('victim')` → 200 + victim data.
- **Remediation:** PR 4 — add `tenant.authorize` (`AuthorizeTenantHeader`) to the route; it requires the authenticated user to have a membership in the resolved tenant, so a spoofed header now **403s before the controller runs**. `TabularReviewStreamTenantIsolationTest` prepends `ResolveTenant` (mirroring production) and locks both layers: spoofed header → 403, in-tenant cross-review id → 404. The existing `TabularReviewStreamControllerTest` moved off the reserved `default` tenant (which `tenant.authorize` forbids) onto a real `acme` tenant.
- **Lesson:** this is the audit's headline result — a finding the paper analysis under-rated until it was executed. Every "the controller already scopes it" claim on a route lacking `tenant.authorize` must be verified with `ResolveTenant` active and a spoofed header.
- **Residual:** none for this route. Follow-up: PR 7's route-exposure gate should flag every other mutating/streaming route missing `tenant.authorize`.

### F-05 — No decoded-type (magic-byte) validation on ingested binaries — Medium
- **Rule/ID:** `SEC-UPLOAD-001`, resource-limits.
- **Population:** KB ingest API (batch ≤ 100), UI staging buffer, and connector-fetched documents (IMAP attachments / cloud files) that flow into per-source chunkers.
- **Impact:** ingestion trusts the declared source-type/extension; a polyglot or mismatched-MIME payload could be routed to the wrong chunker/parser or persisted with a misleading type. Text-markdown path is normalized (`KbPath`) but the binary connector path lacks a decoded-type vs `SourceType` allow-list gate.
- **Source→sink:** connector/upload bytes + declared type → chunker/parser dispatch → DB/embeddings.
- **Remediation:** PR 5 — magic-byte/MIME check vs the `SourceType` allow-list at the ingest boundary (decoded type must match declared); reject polyglot/mismatch/oversize; negative tests. Also verify `Browsershot` PDF export uses an argument array, not string interpolation (`SEC-SHELL-001`).
- **Residual:** none for host; connector-package binary handling covered by package CI.

### F-06 — Public chat/search throttle is not identity+tenant aware — Medium
- **Rule/ID:** `SEC-THROTTLE-001`, resource-limits.
- **Population:** `POST /api/kb/chat`, `POST /api/kb/search`, anonymous chat. The widget path is already session-throttled; these are not keyed to identity+tenant.
- **Impact:** a single tenant or anonymous caller can exhaust AI spend / DB search capacity for others (cost-DoS + noisy-neighbor); IP-only throttling is trivially bypassed and punishes shared-NAT tenants.
- **Source→sink:** unbounded request rate → `KbSearchService` + `AiManager::chat()` → provider spend.
- **Remediation:** PR 6 — named rate limiter keyed by identity+tenant (IP fallback for anonymous) on `/api/kb/chat` + `/api/kb/search`; tests: 429 over threshold, independent buckets per tenant.
- **Residual:** daily cost cap is a separate FinOps control (already metered); this closes the rate dimension.

### F-07 — No resolved-router exposure regression gate — Medium
- **Rule/ID:** `SEC-COVERAGE-001`, route-exposure-regression-gate, http-surface-inventory.
- **Population:** ~261 host routes + 13 vendor-mounted route groups. The R32 matrix covers a representative endpoint per group, but a new mutating route that forgets its gate — or a vendor package mounting routes with a permissive `['api']` default — can ship green (the exact class of bug R32's first run caught for `ai-act-compliance`).
- **Impact:** an ungated mutating route is a potential unauth data-exposure or write. Two suspects to confirm in-PR: `eval-harness.api.middleware` reported empty, and `pii-redactor-admin` middleware without an auth fallback.
- **Source→sink:** route registration (host or vendor) → resolved middleware stack → controller.
- **Remediation:** PR 7 — architecture test that loads the **resolved** routing table, asserts every `POST/PUT/PATCH/DELETE` route is in an authenticated group or an explicitly-declared public allow-list, and that every gated group has an R32 matrix row. Fix the two suspects in-PR or spin off.
- **Residual:** none — this becomes the standing gate.

### F-08 — AI provider/model/base-URL not constrained by a code-owned allow-list — Medium (conditional)
- **Rule/ID:** `SEC-LLM-001` gate #2, ai-provider-supply-chain.
- **Population:** `app/Ai/AiManager.php` + provider construction; runtime-settable inputs (`app_settings`, `widget.tool_calling_providers`).
- **Impact (conditional on a runtime-settable path reaching client construction):** if a tenant/admin setting can select provider/model/base-URL without a code-owned allow-list, a mis-set or malicious value could exfiltrate prompts to an unapproved endpoint (a data-subprocessor decision made at runtime). Severity depends on whether the setting actually flows to the client URL — to be confirmed by trace before implementation.
- **Source→sink:** setting → provider factory → client base-URL/model.
- **Remediation:** PR 8 — after the trace: exact code-owned allow-list at the construction choke point (provider ∈ known set, base-URL host ∈ validated list at boot, model ∈ configured list; fail-closed on unknown); negative tests. If the trace shows no runtime-settable path reaches the URL, downgrade to `implemented` + add the guard test as a regression lock.
- **Residual:** none.

### F-09 — No dependency-audit / SAST CI gate — Medium
- **Rule/ID:** `SEC-DEPS-001`, `SEC-SUPPLY-001`, sast-regression-gate, `BUILD-VERIFY-001`.
- **Population:** `composer.lock` + `package-lock.json` are committed (good), but CI runs no `composer audit` / `npm audit` / SAST; GitHub Actions third-party pinning to unverified.
- **Impact:** a newly-disclosed advisory in a transitive dependency ships silently; no fail-closed gate on new SAST findings (Top-10 2025 A03).
- **Remediation:** PR 9 — `composer audit` + `npm audit` with an expiring advisory baseline, `.github/dependabot.yml`, PHPStan/Larastan baseline, optional CodeQL; verify actions pinned to commit SHAs.
- **Residual (infra):** CodeQL/SAST at org level may need org setup — that portion stays §6 until a green run exists.

### F-10 — Sanctum token lifecycle + CSRF negatives incomplete — Low
- **Rule/ID:** `SEC-TOKEN-001`, auth-hardening, dormant-access.
- **Population:** `config/sanctum.php` (`expiration => null`), password-change flow, stateful SPA routes.
- **Impact:** non-expiring tokens widen the window on a leaked token; no explicit test that a password change revokes tokens; CSRF negative coverage on stateful routes is thin.
- **Remediation:** PR 10 — bounded, env-configurable token expiry default; token-revocation-on-password-change test; CSRF negative tests; crafted-recaller-cookie rejection test (backoffice-no-remember).
- **Residual:** none.

### F-11 — Tauri desktop capabilities are broad; CSP is null — Low
- **Rule/ID:** `SEC-TLS-001` (client), postmessage-origin (Tauri).
- **Population:** `desktop/src-tauri/capabilities/default.json` (LAN wildcards `192.168.*.*`/`10.*.*.*`, `acceptInvalidCerts`), `desktop/src-tauri/tauri.conf.json` (CSP null).
- **Impact:** a production desktop build accepting invalid certs on LAN wildcards weakens transport auth; a null CSP in the webview removes a defense layer. Low because it needs a local network attacker + a shipped desktop build.
- **Remediation:** PR 11 — remove LAN wildcards + `acceptInvalidCerts` from the production capability (dev-only capability), set a non-null CSP in `tauri.conf.json`. Mostly config; hard to regression-test in the PHP/JS CI → residual note.
- **Residual:** desktop build verification is manual (documented).

---

## SANO — reviewed false positives & sound boundaries

- **No inbound webhooks.** `SEC-WEBHOOK-001` has no live target — there is no
  inbound webhook route. The rule activates the day one is added; not a gap now.
- **No CSV/spreadsheet export.** `SEC-CSV-001` (formula injection) is dormant —
  the only export path is PDF (`Dompdf`/`Browsershot`). Not a gap now.
- **Widget renders same-page.** No cross-origin `postMessage`; `postmessage-origin`
  is N/A for the widget today (still tracked for the Tauri webview in PR 11).
- **`DB::raw`/`whereRaw` are safe.** Prescreen 2026-08-09: every occurrence is
  aggregate-only or uses bound `?` parameters + `LikeEscaper`. Not injection.
- **`unserialize` sites are allow-listed.** Both occurrences pass
  `allowed_classes`. Not an insecure-deserialization sink.
- **Frontend `innerHTML` is constant.** The `innerHTML`/`dangerouslySetInnerHTML`
  sites are compile-time SVG/style constants, not untrusted data.
- **No `shell_exec`/`exec` in `app/`.** OS-command-injection surface is empty in
  application code (Browsershot arg-array to confirm in PR 5, low risk).
- **`embedding_cache` cross-tenant is by design.** `UNIQUE(text_hash, provider,
  model)` reuse layer; documented exclusion in `TenantIdMandatoryTest`. Not an
  R30 violation.
- **IMAP lock is cross-tenant by design.** `MailboxLockKey` omits `tenant_id`
  because a mailbox is a shared physical resource; documented in CLAUDE.md §6.
  Not an R30 violation.
- **Tabular-review SSE already tenant-scopes.** `forTenant()` + 404 on
  cross-tenant id (verified). F-04 is a regression-test gap, not a live leak.
- **Promotion is human-gated.** `suggest`/`candidates` endpoints write nothing
  (ADR 0003). No autonomous-promotion path exists. Sound boundary.
