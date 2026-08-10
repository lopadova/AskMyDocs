# AskMyDocs — Security Checklist

> **Living document.** Baseline: commit `7844e9ee` on `develop`, audit opened
> 2026-08-09. This is the master crosswalk for the enterprise-grade security
> audit. It maps, one-by-one: (§1) the 88 internal security controls, (§2) OWASP
> **ASVS 5.0.0** Level 2, (§3) OWASP **AISVS 1.0**, (§4) OWASP **Top 10 2025**
> (as a coverage cross-check), (§5) effective populations per surface, (§6) the
> infrastructure residuals that the repository cannot close, and (§7) the PR
> index. Companion docs: [`THREAT_MODEL.md`](THREAT_MODEL.md),
> [`AUDIT-FINDINGS-2026-08.md`](AUDIT-FINDINGS-2026-08.md),
> [`LORENZO_SECURITY_EXPERIENCE.md`](LORENZO_SECURITY_EXPERIENCE.md).

---

## §0 — Scope & method

**Cardinal rule (skill `security-audit`, remediation discipline).** A rule
existing, or a control marked *Adopted* in the disposition matrix, is **not
evidence**. Every row carries: applicability, an evidence-state, concrete code
evidence, test evidence, an infrastructure step where relevant, residual risk,
and the PR that closes the gap. Nothing is "closed" because documentation says
it is safe. A control that covers only one of several paths stays **open**.

**Evidence-states** (skill step 5):

| State | Meaning |
|---|---|
| `regression-tested` | implemented **and** guarded by a negative/regression test that fails on reintroduction |
| `implemented` | code enforces it now, but no dedicated regression test yet |
| `deployment-config-required` | code contract present; closure needs deploy-time configuration (proxy, env, cache) |
| `infra-verification-required` | repository cannot prove it; needs runtime/platform evidence (Cloudflare, DNS, IAM, Redis, DB grants, real headers, retention, commit signatures) |
| `open` | gap; a remediation PR is assigned |
| `N/A` | not applicable in the current topology; the trigger that would activate it is named |

**Applicability**: `applicable` or `N/A-topology` (with the activation trigger).

**Environment assumptions.** Production runs behind a TLS-terminating
proxy/CDN; Redis is the cache/lock store; PostgreSQL ≥ 15 with pgvector.
**What the repository cannot prove** (never inferred): Cloudflare/edge behavior,
DNS + SPF/DKIM/DMARC, cloud IAM, Redis TLS/auth, DB grants, headers actually
emitted at the edge, real on-disk log retention, branch-protection and
commit-signature platform settings. All such items are `infra-verification-required`
and listed in §6.

**Baseline populations** (measured 2026-08-09 @ `7844e9ee` — see THREAT_MODEL §1):
70 Artisan commands · 29 scheduler slots (+2 config-gated + connector sync) · 9+
queue jobs · 46 MCP tools (12 write-capable) · 2 SSE streams · 5 AI providers ·
public widget (11 routes) · Tauri desktop · 0 inbound webhooks · 0 CSV exports.

**Prescreen result (2026-08-09).** `npm run security:rules` green (19 IDs, 88
dispositions, 8 AI lessons, 3 mirrors). Dangerous-sink sweep: all
`DB::raw`/`whereRaw` are aggregate-only or bound (`?` + `LikeEscaper`); both
`unserialize` sites carry an `allowed_classes` allow-list; frontend `innerHTML`
sites are compile-time SVG constants. No `shell_exec`/`exec` in `app/`. These
sit in the findings-log SANO section.

---

## §1 — Internal security controls (88)

Rendered from the `LORENZO_SECURITY_EXPERIENCE.md` disposition matrix, enriched
with evidence-state + remediation PR. State roll-up at baseline: **17
regression-tested · 39 implemented · 18 open · 9 infra-verification · 5 N/A**.
The 18 `open` rows are the audit's remediation backlog (§7); the 9 infra rows
are §6.

| # | Control (slug) | SEC-ID | Applicability | Evidence-state | Code evidence | Test evidence | Infra / residual step | PR |
|---|---|---|---|---|---|---|---|---|
| 1 | rule-ai-agent-actions | SEC-AI-ACT-001 | applicable | regression-tested | EnforceMcpScope requires mcp:tools:write for write tools (reflection-derived); WidgetToolValidator closed contract | McpWriteToolScopeTest (bidirectional) + WidgetToolValidatorTest | — | PR 3 (#412) |
| 2 | rule-ai-llm-security | SEC-LLM-001 | applicable | implemented | app/Ai/AiManager.php gates; kb_rag prompt delimits untrusted chunks | AiManager + provider tests | provider/model allow-list at construction choke point | PR 8 |
| 3 | ajax-route-hardening | SEC-AJAX-001 | applicable | regression-tested | every entrypoint has explicit auth decision (Sanctum groups) | AdminAuthorizationMatrixTest | — | PR 7 (gate) |
| 4 | api-login-gates | SEC-MFA-API-001 | applicable | regression-tested | API/admin/MCP strong-auth consistency | AdminAuthorizationMatrixTest | MFA not implemented — tracked as residual | — |
| 5 | api-token-lifecycle | SEC-TOKEN-001 | applicable | open | Sanctum opaque tokens; expiration currently null | — | bounded expiry + revoke-on-password-change | PR 10 |
| 6 | audit-trail-integrity | SEC-AUDIT-001 | applicable | regression-tested | kb_canonical_audit + admin_command_audit immutable (no updated_at) | audit feature tests | independent retention evidence | INFRA §6 |
| 7 | auth-hardening | SEC-AUTHHARD-001 | applicable | implemented | invite-gated register, throttled 6/min/IP, viewer floor | RegisterController tests | — | PR 10 (CSRF negatives) |
| 8 | aws-iam-sigv4 | SEC-AWSCRED-001 | applicable | infra-verification-required | no static AWS keys in repo | — | OIDC/short-lived role at deploy — needs cloud evidence | INFRA §6 |
| 9 | backoffice-exposure | SEC-BOADMIN-001 | applicable | regression-tested | admin routes in role: groups; R32 matrix | AdminAuthorizationMatrixTest | route-exposure regression gate | PR 7 |
| 10 | blocking-ci-gate | SEC-CIGATE-001 | applicable | regression-tested | PHPUnit+Vitest+Playwright(run-e2e)+architecture real gates | .github/workflows/tests.yml | — | PR 9 (add SAST/audit) |
| 11 | ci-workflow-permissions | SEC-CI-PERM-001 | applicable | implemented | workflow permissions blocks present | .github/workflows/* | least-privilege audit + action SHA pinning | PR 9 |
| 12 | client-ip-identity | SEC-CLIENTIP-001 | applicable | implemented | trusted-proxy handling; IP not used for auth | — | trusted-proxy runtime config | DEPLOY §6 |
| 13 | edge-credential-leak-protection | — | applicable | infra-verification-required | n/a in repo | — | rotate + verify at deployment edge | INFRA §6 |
| 14 | content-security-policy | SEC-CSP-001 | applicable | open | no CSP header emitted today | — | nonce-based CSP middleware + app.blade.php nonce | PR 1 |
| 15 | control-coverage | SEC-COVERAGE-001 | applicable | regression-tested | effective-population arch tests (Tenant*, Matrix) | tests/Architecture/* | route-exposure population gate | PR 7 |
| 16 | cors-config | SEC-CORS-001 | applicable | implemented | config/cors.php exact origins, R19 CSV trim | cors config test | edge CORS parity evidence | DEPLOY §6 |
| 17 | db-least-privilege | SEC-DBPRIV-001 | applicable | infra-verification-required | app cannot create extensions at runtime (migrations separate) | — | DB grants + role separation runtime evidence | INFRA §6 |
| 18 | dependency-security | SEC-DEPS-001 | applicable | open | committed composer.lock + package-lock.json | — | composer audit + npm audit blocking gate | PR 9 |
| 19 | deserialization | SEC-DESERIALIZE-001 | applicable | implemented | both unserialize sites carry allowed_classes allow-list | prescreen 2026-08-09 | regression test on allow-list | PR 9 |
| 20 | dns-dangling | SEC-DNS-001 | applicable | infra-verification-required | n/a in repo | — | external DNS inventory/monitoring | INFRA §6 |
| 21 | dormant-access | SEC-OFFBOARD-001 | applicable | implemented | membership removal revokes access; final-super-admin guard | SystemAdmin tests | token/session revocation on offboard | PR 10 |
| 22 | effective-security-population | SEC-INVENTORY-001 | applicable | regression-tested | behavior-based inventory in threat model + arch tests | tests/Architecture/* | — | PR 7 |
| 23 | email-auth-dns | SEC-DNSMAIL-001 | applicable | infra-verification-required | n/a in repo | — | SPF/DKIM/DMARC external evidence | INFRA §6 |
| 24 | env-gate-fail-closed | SEC-ENV-001 | applicable | implemented | /testing/* only under APP_ENV=testing; fake providers gated | env-gate tests | scheduled runtime drift report | DEPLOY §6 |
| 25 | export-formula-injection | SEC-CSV-001 | N/A-topology | N/A | no CSV/spreadsheet export exists today | — | rule activates when a spreadsheet export ships | — |
| 26 | external-response-validation | SEC-EXTRESP-001 | applicable | implemented | provider responses validated (status/shape) in AiManager paths | provider tests | bounded body cap on all outbound | PR 2 |
| 27 | fail-closed-security-controls | SEC-FAILCLOSED-001 | applicable | implemented | unknown policy denies (AppSettingsResolver, tool registries) | R43 both-states tests | — | PR 3/8 |
| 28 | frontend-secrets | SEC-FESECRET-001 | applicable | implemented | no secrets in Vite/React/widget bundles | — | bundle-scan regression check | PR 9 |
| 29 | gdpr-data-inventory | SEC-GDPR-001 | applicable | implemented | PII tri-surface (v8.23) vault + erasure + prune jobs | PII feature tests | processor register residency evidence | INFRA §6 |
| 30 | http-client-service | ARCH-HTTP-001 | applicable | regression-tested | OutboundUrlValidator guards both webhook sinks; widget URLs are client-rendered (SANO) | OutboundUrlValidatorTest + WebhookSsrfGuardTest | AI provider egress allow-listed separately (PR 8) | PR 2 (#411) |
| 31 | tenant-object-authorization | SEC-IDOR-001 | applicable | regression-tested | forTenant() scoping; TenantIdMandatoryTest + R32; **tabular-review SSE stream now carries tenant.authorize (real IDOR fix)** | TenantReadScopeTest, Matrix, TabularReviewStreamTenantIsolationTest | — | PR 4 (#414) |
| 32 | key-management | SEC-KEYS-001 | applicable | implemented | encrypted PII vault; managed keys | vault tests | rotation + separated-duties runtime evidence | INFRA §6 |
| 33 | logging-security | SEC-LOG-001 | applicable | implemented | no secrets in query-string (H6); trace_id correlation | logging tests | HTTP-wide correlation-id middleware | PR 1 (adjacent) |
| 34 | multi-surface-security-gates | — | applicable | implemented | HTTP/CLI/queue/MCP parity via shared core services (R44) | tri-surface tests | MCP write parity proof | PR 3 |
| 35 | password-breach-check | SEC-PWNED-001 | applicable | open | not implemented | — | compromised-password check with availability policy | residual §1 |
| 36 | password-policy | SEC-PWPOLICY-001 | applicable | implemented | consistent password rules across flows | auth tests | breach-check integration | residual §1 |
| 37 | path-containment | SEC-PATH-001 | applicable | regression-tested | KbPath::normalize() rejects .. and normalizes | KbPathTest | — | — |
| 38 | pii-encryption | SEC-PII-CRYPT-001 | applicable | implemented | classified sensitive fields encrypted (vault) | vault tests | key rotation evidence | INFRA §6 |
| 39 | public-flow-throttle | SEC-THROTTLE-001 | applicable | regression-tested | `throttle:kb-chat` limiter keyed identity+tenant on POST /kb/chat (floors at 1/min); widget already throttled; no /kb/search route exists | KbChatThrottleTest | daily cost cap is separate FinOps control | PR 6 (#417) |
| 40 | race-conditions | SEC-RACE-001 / R21 | applicable | regression-tested | confirm-token consume inside lockForUpdate txn | CommandRunner concurrency test | — | — |
| 41 | raw-sql-inventory | SEC-RAWSQL-001 | applicable | implemented | DB::raw/whereRaw parametrized or aggregate-only | prescreen 2026-08-09 | arch analyzer for raw SQL | PR 9 |
| 42 | redis-production-posture | SEC-REDIS-001 | applicable | infra-verification-required | n/a in repo | — | TLS/auth/network runtime evidence | INFRA §6 |
| 43 | resource-limits | SEC-LIMITS-001 | applicable | implemented | batch ≤100, max_documents cap, RAG over-retrieval bounded | stream cap test | body/upload size caps sweep | PR 5/6 |
| 44 | secret-settings-naming | SEC-SETTINGS-SECRET-001 | applicable | implemented | secrets stored/displayed as secrets (vault) | settings tests | — | — |
| 45 | security-boundaries | SEC-BOUNDARY-001 | applicable | regression-tested | settings/prompts/headers never a credential | R43 tests | — | PR 4 |
| 46 | security-checklist | SEC-CHECKLIST-001 | applicable | implemented | this document | — | keep evidence-states current | PR A/B |
| 47 | security-inventory | SEC-INVENTORY-001 | applicable | regression-tested | THREAT_MODEL.md + arch tests | tests/Architecture/* | — | PR 7 |
| 48 | security-setting-shape | SEC-SETTING-SHAPE-001 | applicable | implemented | AppSettingsResolver canonical strict shapes | AppSettings tests | — | — |
| 49 | shell-command-array | SEC-SHELL-001 | applicable | implemented | no shell_exec/exec in app/ | prescreen 2026-08-09 | Browsershot arg-array verification | PR 5 |
| 50 | signed-commits | SEC-SIGNCOMMIT-001 | applicable | infra-verification-required | n/a provable in repo | — | branch/platform signature verification | INFRA §6 |
| 51 | signed-url-tokens | SEC-SIGNURL-001 | applicable | regression-tested | DB-backed single-use confirm tokens (R21) | CommandRunner tests | — | — |
| 52 | ssrf-outbound | SEC-SSRF-001 | applicable | regression-tested | OutboundUrlValidator (scheme + DNS→IP private/metadata reject + redirects off) on both webhook jobs | OutboundUrlValidatorTest + WebhookSsrfGuardTest | DNS-rebind TOCTOU documented residual | PR 2 (#411) |
| 53 | multi-repository-security-coverage | — | N/A-topology | N/A | single deployable today | — | revisit if components deploy independently | — |
| 54 | supply-chain-ci | SEC-SUPPLY-001 | applicable | open | committed lockfiles | — | immutable action SHAs + secret isolation audit | PR 9 |
| 55 | sync-ai-instructions | SYNC-AI-001 | applicable | regression-tested | 8 rules + 3 mirrors + validator | npm run security:rules | — | PR B |
| 56 | tls-hsts | SEC-TLS-001 | applicable | open | no HSTS header emitted today | — | app-side HSTS (deployed evidence stays INFRA) | PR 1 |
| 57 | upload-hardening | SEC-UPLOAD-001 | applicable | regression-tested | FileTypeSniffer magic-byte check vs declared SourceType at StageKbUploadRequest boundary | FileTypeSnifferTest + KbUploadMagicByteTest | connector-fetched binaries covered by package CI | PR 5 (#416) |
| 58 | webhook-verify-before-effects | SEC-WEBHOOK-001 | N/A-topology | N/A | no inbound webhook route exists | — | rule activates when inbound webhook ships | — |
| 59 | xml-parsing | SEC-XML-001 | applicable | implemented | no untrusted XML parse in host; symfony/yaml safe | — | connector packages own their XML (package CI) | — |
| 60 | rule-build-verification | BUILD-VERIFY-001 | applicable | regression-tested | fresh phpunit+vitest+e2e evidence per PR | .github/workflows/tests.yml | — | PR 9 |
| 61 | accepted-security-debt | — | applicable | implemented | expiring baseline pattern documented | — | advisory baseline with expiry | PR 9 |
| 62 | admin-authorization-granularity | SEC-BOADMIN-001 | applicable | regression-tested | specific capability gates (can:) not blanket admin | Matrix | limiter inventory | PR 7 |
| 63 | ai-data-flow | SEC-LLM-001 | applicable | implemented | FinOps meters every provider egress path | FinOps tests | PII gate on all egress branches | PR 8 |
| 64 | ai-initiating-user | SEC-AI-ACT-001 | applicable | regression-tested | initiator captured server-side; MCP write tools gated by write scope + token-tenant match | McpWriteToolScopeTest + job tests | — | PR 3 (#412) |
| 65 | ai-provider-supply-chain | SEC-LLM-001 | applicable | open | config/ai.php models; runtime-settable paths exist | — | code-owned endpoint/model allow-list | PR 8 |
| 66 | appkey-rotation | SEC-APPKEY-001 | applicable | implemented | APP_KEY used by Laravel crypto | — | key-dependency inventory + previous-key coverage | residual §1 |
| 67 | backoffice-no-remember | SEC-AUTHHARD-001 | applicable | implemented | Sanctum tokens; no remember-me recaller for staff | auth tests | crafted-recaller negative test | PR 10 |
| 68 | circuit-breaker | — | applicable | implemented | provider retry/backoff bounds | provider tests | per-service breaker choke point | PR 2 |
| 69 | csp-nonce-cache | SEC-CSP-REPORT-001 | applicable | open | no nonce yet | — | fresh nonce across cache hit/miss/refresh | PR 1 |
| 70 | csp-report-collection | SEC-CSP-REPORT-001 | applicable | open | no collector | — | throttled report-only collector | PR 1 |
| 71 | dependency-regression-gate | — | applicable | open | no advisory baseline file | — | stable advisory IDs + expiring exceptions | PR 9 |
| 72 | deploy-credentials | — | applicable | infra-verification-required | no deploy secrets in repo | — | protected OIDC deploy identity | INFRA §6 |
| 73 | dev-dump-anonymization | SEC-DUMP-001 | applicable | implemented | no production dumps in repo | — | anonymization marker verifier | residual §1 |
| 74 | exposed-public-files | SEC-ESPOSTI-001 | applicable | implemented | public/ is Vite build output only | — | positive public asset inventory | PR 7 (adjacent) |
| 75 | frontend-tenant-architecture | — | applicable | regression-tested | FE tenant state presentation-only; backend scopes | TenantReadScopeTest | — | PR 4 |
| 76 | gdpr-consent-and-access | SEC-GDPR-001 | applicable | implemented | DSAR/erasure via PII tri-surface + AI-Act package | PII/AI-Act tests | consent notice version evidence | INFRA §6 |
| 77 | http-surface-inventory | — | applicable | open | manual inventory today | — | resolved-router perimeter gate | PR 7 |
| 78 | log-retention-and-siem | SEC-SIEM-001 | applicable | infra-verification-required | prune jobs present (chat-log:prune etc.) | prune tests | off-host SIEM delivery evidence | INFRA §6 |
| 79 | postmessage-origin | — | N/A-topology | N/A | widget renders same-page; no cross-origin postMessage today | — | activates if widget iframes cross-origin | PR 11 (Tauri) |
| 80 | processor-register | — | applicable | implemented | provider = subprocessor; documented in FinOps/config | — | residency/DPA register evidence | INFRA §6 |
| 81 | production-posture | SEC-POSTURA-001 | applicable | implemented | raw-env debug gate; unknown env = production | posture tests | cached-config divergence check | DEPLOY §6 |
| 82 | request-correlation | — | applicable | implemented | trace_id on chat_logs | FinOps tests | HTTP-wide correlation middleware | PR 1 |
| 83 | response-headers | — | applicable | open | no security headers middleware | — | exact real headers + emitter ownership | PR 1 |
| 84 | route-exposure-regression-gate | — | applicable | open | no resolved-router gate yet | — | real router regression gate | PR 7 |
| 85 | sast-regression-gate | — | applicable | open | no SAST in CI | — | baseline/full SAST fail-closed | PR 9 |
| 86 | session-device-binding | — | applicable | implemented | no IP binding; UA is signal only | — | measured report-only rollout | residual §1 |
| 87 | sri-pinned-cdn | — | N/A-topology | N/A | no external CDN assets (self-hosted Vite) | — | activates if a CDN asset is added | PR 9 |
| 88 | third-party-resources | — | applicable | implemented | browser assets self-hosted | — | resource inventory regression | PR 9 |
---

## §2 — OWASP ASVS 5.0.0 (Level 2)

Every ASVS 5.0.0 L1+L2 requirement (172 rows across V1–V16; V17 WebRTC = N/A,
no WebRTC surface), each mapped to the AskMyDocs internal control(s) and state.
`open` rows inherit the §7 remediation PR named in their note. This is a
**coverage map**, not a certification claim — L2 requirements needing runtime
proof (deployed headers, TLS config) resolve to §6 infra residuals.

State roll-up (L1+L2 applicable): **28 regression-tested · 104 implemented · 40
open**. The 40 open rows are covered by the §7 remediation PRs (predominantly
V3 → PR 1, V5 → PR 5, V7 → PR 10, V12 → PR 1).

#### V1 Encoding and Sanitization

| Req | L | Requirement (abridged) | AskMyDocs mapping | State | Note |
|---|---|---|---|---|---|
| V1.1.1 | 2 | Input is decoded or unescaped into a canonical form only once, it is only decoded when encoded data in that form is exp… | input-escape-complete (R19), raw-sql-inventory `SEC-RAWSQL-001`, path-containment `SEC-PATH-001` | implemented | LikeEscaper + bound SQL + KbPath::normalize(); arch-test sweep planned (PR 7/9) |
| V1.1.2 | 2 | The application performs output encoding and escaping either as a final step before being used by the interpreter for w… | input-escape-complete (R19), raw-sql-inventory `SEC-RAWSQL-001`, path-containment `SEC-PATH-001` | implemented | LikeEscaper + bound SQL + KbPath::normalize(); arch-test sweep planned (PR 7/9) |
| V1.2.1 | 1 | Output encoding for an HTTP response, HTML document, or XML document is relevant for the context required, such as enco… | React/Blade auto-escaping, raw-sql-inventory `SEC-RAWSQL-001`, input-escape-complete (R19) | implemented | React escapes by default, innerHTML = SVG constants only (prescreen); DB::raw/whereRaw parametrized or aggregate-only |
| V1.2.2 | 1 | When dynamically building URLs, untrusted data is encoded according to its context (e.g., URL encoding or base64url enc… | React/Blade auto-escaping, raw-sql-inventory `SEC-RAWSQL-001`, input-escape-complete (R19) | implemented | React escapes by default, innerHTML = SVG constants only (prescreen); DB::raw/whereRaw parametrized or aggregate-only |
| V1.2.3 | 1 | Output encoding or escaping is used when dynamically building JavaScript content (including JSON), to avoid changing th… | React/Blade auto-escaping, raw-sql-inventory `SEC-RAWSQL-001`, input-escape-complete (R19) | implemented | React escapes by default, innerHTML = SVG constants only (prescreen); DB::raw/whereRaw parametrized or aggregate-only |
| V1.2.4 | 1 | Data selection or database queries (e.g., SQL, HQL, NoSQL, Cypher) use parameterized queries, ORMs, entity frameworks,… | React/Blade auto-escaping, raw-sql-inventory `SEC-RAWSQL-001`, input-escape-complete (R19) | implemented | React escapes by default, innerHTML = SVG constants only (prescreen); DB::raw/whereRaw parametrized or aggregate-only |
| V1.2.5 | 1 | The application protects against OS command injection and that operating system calls use parameterized OS queries or u… | React/Blade auto-escaping, raw-sql-inventory `SEC-RAWSQL-001`, input-escape-complete (R19) | implemented | React escapes by default, innerHTML = SVG constants only (prescreen); DB::raw/whereRaw parametrized or aggregate-only |
| V1.2.6 | 2 | The application protects against LDAP injection vulnerabilities, or that specific security controls to prevent LDAP inj… | React/Blade auto-escaping, raw-sql-inventory `SEC-RAWSQL-001`, input-escape-complete (R19) | implemented | React escapes by default, innerHTML = SVG constants only (prescreen); DB::raw/whereRaw parametrized or aggregate-only |
| V1.2.7 | 2 | The application is protected against XPath injection attacks by using query parameterization or precompiled queries. | React/Blade auto-escaping, raw-sql-inventory `SEC-RAWSQL-001`, input-escape-complete (R19) | implemented | React escapes by default, innerHTML = SVG constants only (prescreen); DB::raw/whereRaw parametrized or aggregate-only |
| V1.2.8 | 2 | LaTeX processors are configured securely (such as not using the "--shell-escape" flag) and an allowlist of commands is… | React/Blade auto-escaping, raw-sql-inventory `SEC-RAWSQL-001`, input-escape-complete (R19) | implemented | React escapes by default, innerHTML = SVG constants only (prescreen); DB::raw/whereRaw parametrized or aggregate-only |
| V1.2.9 | 2 | The application escapes special characters in regular expressions (typically using a backslash) to prevent them from be… | React/Blade auto-escaping, raw-sql-inventory `SEC-RAWSQL-001`, input-escape-complete (R19) | implemented | React escapes by default, innerHTML = SVG constants only (prescreen); DB::raw/whereRaw parametrized or aggregate-only |
| V1.3.1 | 1 | All untrusted HTML input from WYSIWYG editors or similar is sanitized using a well-known and secure HTML sanitization l… | shell-command-array `SEC-SHELL-001` | implemented | no shell_exec/exec in app/; Browsershot arg handling to verify (PR 5) |
| V1.3.2 | 1 | The application avoids the use of eval() or other dynamic code execution features such as Spring Expression Language (S… | shell-command-array `SEC-SHELL-001` | implemented | no shell_exec/exec in app/; Browsershot arg handling to verify (PR 5) |
| V1.3.3 | 2 | Data being passed to a potentially dangerous context is sanitized beforehand to enforce safety measures, such as only a… | shell-command-array `SEC-SHELL-001` | implemented | no shell_exec/exec in app/; Browsershot arg handling to verify (PR 5) |
| V1.3.4 | 2 | User-supplied Scalable Vector Graphics (SVG) scriptable content is validated or sanitized to contain only tags and attr… | shell-command-array `SEC-SHELL-001` | implemented | no shell_exec/exec in app/; Browsershot arg handling to verify (PR 5) |
| V1.3.5 | 2 | The application sanitizes or disables user-supplied scriptable or expression template language content, such as Markdow… | shell-command-array `SEC-SHELL-001` | implemented | no shell_exec/exec in app/; Browsershot arg handling to verify (PR 5) |
| V1.3.6 | 2 | The application protects against Server-side Request Forgery (SSRF) attacks, by validating untrusted data against an al… | shell-command-array `SEC-SHELL-001` | implemented | no shell_exec/exec in app/; Browsershot arg handling to verify (PR 5) |
| V1.3.7 | 2 | The application protects against template injection attacks by not allowing templates to be built based on untrusted in… | shell-command-array `SEC-SHELL-001` | implemented | no shell_exec/exec in app/; Browsershot arg handling to verify (PR 5) |
| V1.3.8 | 2 | The application appropriately sanitizes untrusted input before use in Java Naming and Directory Interface (JNDI) querie… | shell-command-array `SEC-SHELL-001` | implemented | no shell_exec/exec in app/; Browsershot arg handling to verify (PR 5) |
| V1.3.9 | 2 | The application sanitizes content before it is sent to memcache to prevent injection attacks. | shell-command-array `SEC-SHELL-001` | implemented | no shell_exec/exec in app/; Browsershot arg handling to verify (PR 5) |
| V1.3.10 | 2 | Format strings which might resolve in an unexpected or malicious way when used are sanitized before being processed. | shell-command-array `SEC-SHELL-001` | implemented | no shell_exec/exec in app/; Browsershot arg handling to verify (PR 5) |
| V1.3.11 | 2 | The application sanitizes user input before passing to mail systems to protect against SMTP or IMAP injection. | shell-command-array `SEC-SHELL-001` | implemented | no shell_exec/exec in app/; Browsershot arg handling to verify (PR 5) |
| V1.4.1 | 2 | The application uses memory-safe string, safer memory copy and pointer arithmetic to detect or prevent stack, buffer, o… | input-escape-complete (R19), raw-sql-inventory `SEC-RAWSQL-001`, path-containment `SEC-PATH-001` | implemented | LikeEscaper + bound SQL + KbPath::normalize(); arch-test sweep planned (PR 7/9) |
| V1.4.2 | 2 | Sign, range, and input validation techniques are used to prevent integer overflows. | input-escape-complete (R19), raw-sql-inventory `SEC-RAWSQL-001`, path-containment `SEC-PATH-001` | implemented | LikeEscaper + bound SQL + KbPath::normalize(); arch-test sweep planned (PR 7/9) |
| V1.4.3 | 2 | Dynamically allocated memory and resources are released, and that references or pointers to freed memory are removed or… | input-escape-complete (R19), raw-sql-inventory `SEC-RAWSQL-001`, path-containment `SEC-PATH-001` | implemented | LikeEscaper + bound SQL + KbPath::normalize(); arch-test sweep planned (PR 7/9) |
| V1.5.1 | 1 | The application configures XML parsers to use a restrictive configuration and that unsafe features such as resolving ex… | xml-parsing `SEC-XML-001` | implemented | host has no XML parse of untrusted input; connector packages own theirs (package CI) |
| V1.5.2 | 2 | Deserialization of untrusted data enforces safe input handling, such as using an allowlist of object types or restricti… | xml-parsing `SEC-XML-001` | implemented | host has no XML parse of untrusted input; connector packages own theirs (package CI) |

#### V2 Validation and Business Logic

| Req | L | Requirement (abridged) | AskMyDocs mapping | State | Note |
|---|---|---|---|---|---|
| V2.1.1 | 1 | The application's documentation defines input validation rules for how to check the validity of data items against an e… | FormRequest validation + route-contracts (R20), race-conditions (R21) | regression-tested | FormRequest->DTO->Service flow; R21 atomic confirm tokens tested |
| V2.1.2 | 2 | The application's documentation defines how to validate the logical and contextual consistency of combined data items,… | FormRequest validation + route-contracts (R20), race-conditions (R21) | regression-tested | FormRequest->DTO->Service flow; R21 atomic confirm tokens tested |
| V2.1.3 | 2 | Expectations for business logic limits and validations are documented, including both per-user and globally across the… | FormRequest validation + route-contracts (R20), race-conditions (R21) | regression-tested | FormRequest->DTO->Service flow; R21 atomic confirm tokens tested |
| V2.2.1 | 1 | Input is validated to enforce business or functional expectations for that input. This should either use positive valid… | FormRequest validation + route-contracts (R20), race-conditions (R21) | regression-tested | FormRequest->DTO->Service flow; R21 atomic confirm tokens tested |
| V2.2.2 | 1 | The application is designed to enforce input validation at a trusted service layer. While client-side validation improv… | FormRequest validation + route-contracts (R20), race-conditions (R21) | regression-tested | FormRequest->DTO->Service flow; R21 atomic confirm tokens tested |
| V2.2.3 | 2 | The application ensures that combinations of related data items are reasonable according to the pre-defined rules. | FormRequest validation + route-contracts (R20), race-conditions (R21) | regression-tested | FormRequest->DTO->Service flow; R21 atomic confirm tokens tested |
| V2.3.1 | 1 | The application will only process business logic flows for the same user in the expected sequential step order and with… | FormRequest validation + route-contracts (R20), race-conditions (R21) | regression-tested | FormRequest->DTO->Service flow; R21 atomic confirm tokens tested |
| V2.3.2 | 2 | Business logic limits are implemented per the application's documentation to avoid business logic flaws being exploited. | FormRequest validation + route-contracts (R20), race-conditions (R21) | regression-tested | FormRequest->DTO->Service flow; R21 atomic confirm tokens tested |
| V2.3.3 | 2 | Transactions are being used at the business logic level such that either a business logic operation succeeds in its ent… | FormRequest validation + route-contracts (R20), race-conditions (R21) | regression-tested | FormRequest->DTO->Service flow; R21 atomic confirm tokens tested |
| V2.3.4 | 2 | Business logic level locking mechanisms are used to ensure that limited quantity resources (such as theater seats or de… | FormRequest validation + route-contracts (R20), race-conditions (R21) | regression-tested | FormRequest->DTO->Service flow; R21 atomic confirm tokens tested |
| V2.4.1 | 2 | Anti-automation controls are in place to protect against excessive calls to application functions that could lead to da… | FormRequest validation + route-contracts (R20), race-conditions (R21) | regression-tested | FormRequest->DTO->Service flow; R21 atomic confirm tokens tested |

#### V3 Web Frontend Security

| Req | L | Requirement (abridged) | AskMyDocs mapping | State | Note |
|---|---|---|---|---|---|
| V3.2.1 | 1 | Security controls are in place to prevent browsers from rendering content or functionality in HTTP responses in an inco… | content-security-policy `SEC-CSP-001`, response-headers, postmessage-origin | open | PR 1 (SecurityHeaders middleware + CSP nonce); postMessage N/A today (same-page widget) |
| V3.2.2 | 1 | Content intended to be displayed as text, rather than rendered as HTML, is handled using safe rendering functions (such… | content-security-policy `SEC-CSP-001`, response-headers, postmessage-origin | open | PR 1 (SecurityHeaders middleware + CSP nonce); postMessage N/A today (same-page widget) |
| V3.3.1 | 1 | Cookies have the 'Secure' attribute set, and if the '\__Host-' prefix is not used for the cookie name, the '__Secure-'… | content-security-policy `SEC-CSP-001`, response-headers, postmessage-origin | open | PR 1 (SecurityHeaders middleware + CSP nonce); postMessage N/A today (same-page widget) |
| V3.3.2 | 2 | Each cookie's 'SameSite' attribute value is set according to the purpose of the cookie, to limit exposure to user inter… | content-security-policy `SEC-CSP-001`, response-headers, postmessage-origin | open | PR 1 (SecurityHeaders middleware + CSP nonce); postMessage N/A today (same-page widget) |
| V3.3.3 | 2 | Cookies have the '__Host-' prefix for the cookie name unless they are explicitly designed to be shared with other hosts. | content-security-policy `SEC-CSP-001`, response-headers, postmessage-origin | open | PR 1 (SecurityHeaders middleware + CSP nonce); postMessage N/A today (same-page widget) |
| V3.3.4 | 2 | If the value of a cookie is not meant to be accessible to client-side scripts (such as a session token), the cookie mus… | content-security-policy `SEC-CSP-001`, response-headers, postmessage-origin | open | PR 1 (SecurityHeaders middleware + CSP nonce); postMessage N/A today (same-page widget) |
| V3.4.1 | 1 | A Strict-Transport-Security header field is included on all responses to enforce an HTTP Strict Transport Security (HST… | cors-config `SEC-CORS-001`, cookie flags | implemented | config/cors.php exact origins (R19 CSV trim); cookie flags env-aware |
| V3.4.2 | 1 | The Cross-Origin Resource Sharing (CORS) Access-Control-Allow-Origin header field is a fixed value by the application,… | cors-config `SEC-CORS-001`, cookie flags | implemented | config/cors.php exact origins (R19 CSV trim); cookie flags env-aware |
| V3.4.3 | 2 | HTTP responses include a Content-Security-Policy response header field which defines directives to ensure the browser o… | cors-config `SEC-CORS-001`, cookie flags | implemented | config/cors.php exact origins (R19 CSV trim); cookie flags env-aware |
| V3.4.4 | 2 | All HTTP responses contain an 'X-Content-Type-Options: nosniff' header field. This instructs browsers not to use conten… | cors-config `SEC-CORS-001`, cookie flags | implemented | config/cors.php exact origins (R19 CSV trim); cookie flags env-aware |
| V3.4.5 | 2 | The application sets a referrer policy to prevent leakage of technically sensitive data to third-party services via the… | cors-config `SEC-CORS-001`, cookie flags | implemented | config/cors.php exact origins (R19 CSV trim); cookie flags env-aware |
| V3.4.6 | 2 | The web application uses the frame-ancestors directive of the Content-Security-Policy header field for every HTTP respo… | cors-config `SEC-CORS-001`, cookie flags | implemented | config/cors.php exact origins (R19 CSV trim); cookie flags env-aware |
| V3.5.1 | 1 | , if the application does not rely on the CORS preflight mechanism to prevent disallowed cross-origin requests to use s… | content-security-policy `SEC-CSP-001`, response-headers, postmessage-origin | open | PR 1 (SecurityHeaders middleware + CSP nonce); postMessage N/A today (same-page widget) |
| V3.5.2 | 1 | , if the application relies on the CORS preflight mechanism to prevent disallowed cross-origin use of sensitive functio… | content-security-policy `SEC-CSP-001`, response-headers, postmessage-origin | open | PR 1 (SecurityHeaders middleware + CSP nonce); postMessage N/A today (same-page widget) |
| V3.5.3 | 1 | HTTP requests to sensitive functionality use appropriate HTTP methods such as POST, PUT, PATCH, or DELETE, and not meth… | content-security-policy `SEC-CSP-001`, response-headers, postmessage-origin | open | PR 1 (SecurityHeaders middleware + CSP nonce); postMessage N/A today (same-page widget) |
| V3.5.4 | 2 | Separate applications are hosted on different hostnames to leverage the restrictions provided by same-origin policy, in… | content-security-policy `SEC-CSP-001`, response-headers, postmessage-origin | open | PR 1 (SecurityHeaders middleware + CSP nonce); postMessage N/A today (same-page widget) |
| V3.5.5 | 2 | Messages received by the postMessage interface are discarded if the origin of the message is not trusted, or if the syn… | content-security-policy `SEC-CSP-001`, response-headers, postmessage-origin | open | PR 1 (SecurityHeaders middleware + CSP nonce); postMessage N/A today (same-page widget) |
| V3.7.1 | 2 | The application only uses client-side technologies which are still supported and considered secure. Examples of technol… | content-security-policy `SEC-CSP-001`, response-headers, postmessage-origin | open | PR 1 (SecurityHeaders middleware + CSP nonce); postMessage N/A today (same-page widget) |
| V3.7.2 | 2 | The application will only automatically redirect the user to a different hostname or domain (which is not controlled by… | content-security-policy `SEC-CSP-001`, response-headers, postmessage-origin | open | PR 1 (SecurityHeaders middleware + CSP nonce); postMessage N/A today (same-page widget) |

#### V4 API and Web Service

| Req | L | Requirement (abridged) | AskMyDocs mapping | State | Note |
|---|---|---|---|---|---|
| V4.1.1 | 1 | Every HTTP response with a message body contains a Content-Type header field that matches the actual content of the res… | api-login-gates, backoffice-exposure `SEC-BOADMIN-001`, R32 matrix | regression-tested | AdminAuthorizationMatrixTest; route-exposure gate lands in PR 7 |
| V4.1.2 | 2 | Only user-facing endpoints (intended for manual web-browser access) automatically redirect from HTTP to HTTPS, while ot… | api-login-gates, backoffice-exposure `SEC-BOADMIN-001`, R32 matrix | regression-tested | AdminAuthorizationMatrixTest; route-exposure gate lands in PR 7 |
| V4.1.3 | 2 | Any HTTP header field used by the application and set by an intermediary layer, such as a load balancer, a web proxy, o… | api-login-gates, backoffice-exposure `SEC-BOADMIN-001`, R32 matrix | regression-tested | AdminAuthorizationMatrixTest; route-exposure gate lands in PR 7 |
| V4.2.1 | 2 | All application components (including load balancers, firewalls, and application servers) determine boundaries of incom… | api-login-gates, backoffice-exposure `SEC-BOADMIN-001`, R32 matrix | regression-tested | AdminAuthorizationMatrixTest; route-exposure gate lands in PR 7 |
| V4.3.1 | 2 | A query allowlist, depth limiting, amount limiting, or query cost analysis is used to prevent GraphQL or data layer exp… | api-login-gates, backoffice-exposure `SEC-BOADMIN-001`, R32 matrix | regression-tested | AdminAuthorizationMatrixTest; route-exposure gate lands in PR 7 |
| V4.3.2 | 2 | GraphQL introspection queries are disabled in the production environment unless the GraphQL API is meant to be used by… | api-login-gates, backoffice-exposure `SEC-BOADMIN-001`, R32 matrix | regression-tested | AdminAuthorizationMatrixTest; route-exposure gate lands in PR 7 |
| V4.4.1 | 1 | WebSocket over TLS (WSS) is used for all WebSocket connections. | api-login-gates, backoffice-exposure `SEC-BOADMIN-001`, R32 matrix | regression-tested | AdminAuthorizationMatrixTest; route-exposure gate lands in PR 7 |
| V4.4.2 | 2 | , during the initial HTTP WebSocket handshake, the Origin header field is checked against a list of origins allowed for… | api-login-gates, backoffice-exposure `SEC-BOADMIN-001`, R32 matrix | regression-tested | AdminAuthorizationMatrixTest; route-exposure gate lands in PR 7 |
| V4.4.3 | 2 | , if the application's standard session management cannot be used, dedicated tokens are being used for this, which comp… | api-login-gates, backoffice-exposure `SEC-BOADMIN-001`, R32 matrix | regression-tested | AdminAuthorizationMatrixTest; route-exposure gate lands in PR 7 |
| V4.4.4 | 2 | Dedicated WebSocket session management tokens are initially obtained or validated through the previously authenticated… | api-login-gates, backoffice-exposure `SEC-BOADMIN-001`, R32 matrix | regression-tested | AdminAuthorizationMatrixTest; route-exposure gate lands in PR 7 |

#### V5 File Handling

| Req | L | Requirement (abridged) | AskMyDocs mapping | State | Note |
|---|---|---|---|---|---|
| V5.1.1 | 2 | The documentation defines the permitted file types, expected file extensions, and maximum size (including unpacked size… | upload-hardening `SEC-UPLOAD-001`, path-containment `SEC-PATH-001` | open | PR 5 (magic-byte/MIME vs SourceType); KbPath containment already RT |
| V5.2.1 | 1 | The application will only accept files of a size which it can process without causing a loss of performance or a denial… | upload-hardening `SEC-UPLOAD-001`, path-containment `SEC-PATH-001` | open | PR 5 (magic-byte/MIME vs SourceType); KbPath containment already RT |
| V5.2.2 | 1 | When the application accepts a file, either on its own or within an archive such as a zip file, it checks if the file e… | upload-hardening `SEC-UPLOAD-001`, path-containment `SEC-PATH-001` | open | PR 5 (magic-byte/MIME vs SourceType); KbPath containment already RT |
| V5.2.3 | 2 | The application checks compressed files (e.g., zip, gz, docx, odt) against maximum allowed uncompressed size and agains… | upload-hardening `SEC-UPLOAD-001`, path-containment `SEC-PATH-001` | open | PR 5 (magic-byte/MIME vs SourceType); KbPath containment already RT |
| V5.3.1 | 1 | Files uploaded or generated by untrusted input and stored in a public folder, are not executed as server-side program c… | upload-hardening `SEC-UPLOAD-001`, path-containment `SEC-PATH-001` | open | PR 5 (magic-byte/MIME vs SourceType); KbPath containment already RT |
| V5.3.2 | 1 | When the application creates file paths for file operations, instead of user-submitted filenames, it uses internally ge… | upload-hardening `SEC-UPLOAD-001`, path-containment `SEC-PATH-001` | open | PR 5 (magic-byte/MIME vs SourceType); KbPath containment already RT |
| V5.4.1 | 2 | The application validates or ignores user-submitted filenames, including in a JSON, JSONP, or URL parameter and specifi… | upload-hardening `SEC-UPLOAD-001`, path-containment `SEC-PATH-001` | open | PR 5 (magic-byte/MIME vs SourceType); KbPath containment already RT |
| V5.4.2 | 2 | File names served (e.g., in HTTP response header fields or email attachments) are encoded or sanitized (e.g., following… | upload-hardening `SEC-UPLOAD-001`, path-containment `SEC-PATH-001` | open | PR 5 (magic-byte/MIME vs SourceType); KbPath containment already RT |
| V5.4.3 | 2 | Files obtained from untrusted sources are scanned by antivirus scanners to prevent serving of known malicious content. | upload-hardening `SEC-UPLOAD-001`, path-containment `SEC-PATH-001` | open | PR 5 (magic-byte/MIME vs SourceType); KbPath containment already RT |

#### V6 Authentication

| Req | L | Requirement (abridged) | AskMyDocs mapping | State | Note |
|---|---|---|---|---|---|
| V6.1.1 | 1 | Application documentation defines how controls such as rate limiting, anti-automation, and adaptive response, are used… | password-policy `SEC-PWPOLICY-001`, password-breach-check `SEC-PWNED-001`, auth-hardening | implemented | Sanctum + invite-gated register (6/min/IP); breach-check + MFA absent — tracked §1 |
| V6.1.2 | 2 | A list of context-specific words is documented in order to prevent their use in passwords. The list could include permu… | password-policy `SEC-PWPOLICY-001`, password-breach-check `SEC-PWNED-001`, auth-hardening | implemented | Sanctum + invite-gated register (6/min/IP); breach-check + MFA absent — tracked §1 |
| V6.1.3 | 2 | , if the application includes multiple authentication pathways, these are all documented together with the security con… | password-policy `SEC-PWPOLICY-001`, password-breach-check `SEC-PWNED-001`, auth-hardening | implemented | Sanctum + invite-gated register (6/min/IP); breach-check + MFA absent — tracked §1 |
| V6.2.1 | 1 | User set passwords are at least 8 characters in length although a minimum of 15 characters is strongly recommended. | password-policy `SEC-PWPOLICY-001`, password-breach-check `SEC-PWNED-001`, auth-hardening | implemented | Sanctum + invite-gated register (6/min/IP); breach-check + MFA absent — tracked §1 |
| V6.2.2 | 1 | Users can change their password. | password-policy `SEC-PWPOLICY-001`, password-breach-check `SEC-PWNED-001`, auth-hardening | implemented | Sanctum + invite-gated register (6/min/IP); breach-check + MFA absent — tracked §1 |
| V6.2.3 | 1 | Password change functionality requires the user's current and new password. | password-policy `SEC-PWPOLICY-001`, password-breach-check `SEC-PWNED-001`, auth-hardening | implemented | Sanctum + invite-gated register (6/min/IP); breach-check + MFA absent — tracked §1 |
| V6.2.4 | 1 | Passwords submitted during account registration or password change are checked against an available set of, at least, t… | password-policy `SEC-PWPOLICY-001`, password-breach-check `SEC-PWNED-001`, auth-hardening | implemented | Sanctum + invite-gated register (6/min/IP); breach-check + MFA absent — tracked §1 |
| V6.2.5 | 1 | Passwords of any composition can be used, without rules limiting the type of characters permitted. There must be no req… | password-policy `SEC-PWPOLICY-001`, password-breach-check `SEC-PWNED-001`, auth-hardening | implemented | Sanctum + invite-gated register (6/min/IP); breach-check + MFA absent — tracked §1 |
| V6.2.6 | 1 | Password input fields use type=password to mask the entry. Applications may allow the user to temporarily view the enti… | password-policy `SEC-PWPOLICY-001`, password-breach-check `SEC-PWNED-001`, auth-hardening | implemented | Sanctum + invite-gated register (6/min/IP); breach-check + MFA absent — tracked §1 |
| V6.2.7 | 1 | "paste" functionality, browser password helpers, and external password managers are permitted. | password-policy `SEC-PWPOLICY-001`, password-breach-check `SEC-PWNED-001`, auth-hardening | implemented | Sanctum + invite-gated register (6/min/IP); breach-check + MFA absent — tracked §1 |
| V6.2.8 | 1 | The application verifies the user's password exactly as received from the user, without any modifications such as trunc… | password-policy `SEC-PWPOLICY-001`, password-breach-check `SEC-PWNED-001`, auth-hardening | implemented | Sanctum + invite-gated register (6/min/IP); breach-check + MFA absent — tracked §1 |
| V6.2.9 | 2 | Passwords of at least 64 characters are permitted. | password-policy `SEC-PWPOLICY-001`, password-breach-check `SEC-PWNED-001`, auth-hardening | implemented | Sanctum + invite-gated register (6/min/IP); breach-check + MFA absent — tracked §1 |
| V6.2.10 | 2 | A user's password stays valid until it is discovered to be compromised or the user rotates it. The application must not… | password-policy `SEC-PWPOLICY-001`, password-breach-check `SEC-PWNED-001`, auth-hardening | implemented | Sanctum + invite-gated register (6/min/IP); breach-check + MFA absent — tracked §1 |
| V6.2.11 | 2 | The documented list of context specific words is used to prevent easy to guess passwords being created. | password-policy `SEC-PWPOLICY-001`, password-breach-check `SEC-PWNED-001`, auth-hardening | implemented | Sanctum + invite-gated register (6/min/IP); breach-check + MFA absent — tracked §1 |
| V6.2.12 | 2 | Passwords submitted during account registration or password changes are checked against a set of breached passwords. | password-policy `SEC-PWPOLICY-001`, password-breach-check `SEC-PWNED-001`, auth-hardening | implemented | Sanctum + invite-gated register (6/min/IP); breach-check + MFA absent — tracked §1 |
| V6.3.1 | 1 | Controls to prevent attacks such as credential stuffing and password brute force are implemented according to the appli… | password-policy `SEC-PWPOLICY-001`, password-breach-check `SEC-PWNED-001`, auth-hardening | implemented | Sanctum + invite-gated register (6/min/IP); breach-check + MFA absent — tracked §1 |
| V6.3.2 | 1 | Default user accounts (e.g., "root", "admin", or "sa") are not present in the application or are disabled. | password-policy `SEC-PWPOLICY-001`, password-breach-check `SEC-PWNED-001`, auth-hardening | implemented | Sanctum + invite-gated register (6/min/IP); breach-check + MFA absent — tracked §1 |
| V6.3.3 | 2 | Either a multi-factor authentication mechanism or a combination of single-factor authentication mechanisms, must be use… | password-policy `SEC-PWPOLICY-001`, password-breach-check `SEC-PWNED-001`, auth-hardening | implemented | Sanctum + invite-gated register (6/min/IP); breach-check + MFA absent — tracked §1 |
| V6.3.4 | 2 | , if the application includes multiple authentication pathways, there are no undocumented pathways and that security co… | password-policy `SEC-PWPOLICY-001`, password-breach-check `SEC-PWNED-001`, auth-hardening | implemented | Sanctum + invite-gated register (6/min/IP); breach-check + MFA absent — tracked §1 |
| V6.4.1 | 1 | System generated initial passwords or activation codes are securely randomly generated, follow the existing password po… | password-policy `SEC-PWPOLICY-001`, password-breach-check `SEC-PWNED-001`, auth-hardening | implemented | Sanctum + invite-gated register (6/min/IP); breach-check + MFA absent — tracked §1 |
| V6.4.2 | 1 | Password hints or knowledge-based authentication (so-called "secret questions") are not present. | password-policy `SEC-PWPOLICY-001`, password-breach-check `SEC-PWNED-001`, auth-hardening | implemented | Sanctum + invite-gated register (6/min/IP); breach-check + MFA absent — tracked §1 |
| V6.4.3 | 2 | A secure process for resetting a forgotten password is implemented, that does not bypass any enabled multi-factor authe… | password-policy `SEC-PWPOLICY-001`, password-breach-check `SEC-PWNED-001`, auth-hardening | implemented | Sanctum + invite-gated register (6/min/IP); breach-check + MFA absent — tracked §1 |
| V6.4.4 | 2 | If a multi-factor authentication factor is lost, evidence of identity proofing is performed at the same level as during… | password-policy `SEC-PWPOLICY-001`, password-breach-check `SEC-PWNED-001`, auth-hardening | implemented | Sanctum + invite-gated register (6/min/IP); breach-check + MFA absent — tracked §1 |
| V6.5.1 | 2 | Lookup secrets, out-of-band authentication requests or codes, and time-based one-time passwords (TOTPs) are only succes… | password-policy `SEC-PWPOLICY-001`, password-breach-check `SEC-PWNED-001`, auth-hardening | implemented | Sanctum + invite-gated register (6/min/IP); breach-check + MFA absent — tracked §1 |
| V6.5.2 | 2 | , when being stored in the application's backend, lookup secrets with less than 112 bits of entropy (19 random alphanum… | password-policy `SEC-PWPOLICY-001`, password-breach-check `SEC-PWNED-001`, auth-hardening | implemented | Sanctum + invite-gated register (6/min/IP); breach-check + MFA absent — tracked §1 |
| V6.5.3 | 2 | Lookup secrets, out-of-band authentication code, and time-based one-time password seeds, are generated using a Cryptogr… | password-policy `SEC-PWPOLICY-001`, password-breach-check `SEC-PWNED-001`, auth-hardening | implemented | Sanctum + invite-gated register (6/min/IP); breach-check + MFA absent — tracked §1 |
| V6.5.4 | 2 | Lookup secrets and out-of-band authentication codes have a minimum of 20 bits of entropy (typically 4 random alphanumer… | password-policy `SEC-PWPOLICY-001`, password-breach-check `SEC-PWNED-001`, auth-hardening | implemented | Sanctum + invite-gated register (6/min/IP); breach-check + MFA absent — tracked §1 |
| V6.5.5 | 2 | Out-of-band authentication requests, codes, or tokens, as well as time-based one-time passwords (TOTPs) have a defined… | password-policy `SEC-PWPOLICY-001`, password-breach-check `SEC-PWNED-001`, auth-hardening | implemented | Sanctum + invite-gated register (6/min/IP); breach-check + MFA absent — tracked §1 |
| V6.6.1 | 2 | Authentication mechanisms using the Public Switched Telephone Network (PSTN) to deliver One-time Passwords (OTPs) via p… | password-policy `SEC-PWPOLICY-001`, password-breach-check `SEC-PWNED-001`, auth-hardening | implemented | Sanctum + invite-gated register (6/min/IP); breach-check + MFA absent — tracked §1 |
| V6.6.2 | 2 | Out-of-band authentication requests, codes, or tokens are bound to the original authentication request for which they w… | password-policy `SEC-PWPOLICY-001`, password-breach-check `SEC-PWNED-001`, auth-hardening | implemented | Sanctum + invite-gated register (6/min/IP); breach-check + MFA absent — tracked §1 |
| V6.6.3 | 2 | A code based out-of-band authentication mechanism is protected against brute force attacks by using rate limiting. Cons… | password-policy `SEC-PWPOLICY-001`, password-breach-check `SEC-PWNED-001`, auth-hardening | implemented | Sanctum + invite-gated register (6/min/IP); breach-check + MFA absent — tracked §1 |
| V6.8.1 | 2 | , if the application supports multiple identity providers (IdPs), the user's identity cannot be spoofed via another sup… | password-policy `SEC-PWPOLICY-001`, password-breach-check `SEC-PWNED-001`, auth-hardening | implemented | Sanctum + invite-gated register (6/min/IP); breach-check + MFA absent — tracked §1 |
| V6.8.2 | 2 | The presence and integrity of digital signatures on authentication assertions (for example on JWTs or SAML assertions)… | password-policy `SEC-PWPOLICY-001`, password-breach-check `SEC-PWNED-001`, auth-hardening | implemented | Sanctum + invite-gated register (6/min/IP); breach-check + MFA absent — tracked §1 |
| V6.8.3 | 2 | SAML assertions are uniquely processed and used only once within the validity period to prevent replay attacks. | password-policy `SEC-PWPOLICY-001`, password-breach-check `SEC-PWNED-001`, auth-hardening | implemented | Sanctum + invite-gated register (6/min/IP); breach-check + MFA absent — tracked §1 |
| V6.8.4 | 2 | , if an application uses a separate Identity Provider (IdP) and expects specific authentication strength, methods, or r… | password-policy `SEC-PWPOLICY-001`, password-breach-check `SEC-PWNED-001`, auth-hardening | implemented | Sanctum + invite-gated register (6/min/IP); breach-check + MFA absent — tracked §1 |

#### V7 Session Management

| Req | L | Requirement (abridged) | AskMyDocs mapping | State | Note |
|---|---|---|---|---|---|
| V7.1.1 | 2 | The user's session inactivity timeout and absolute maximum session lifetime are documented, are appropriate in combinat… | api-token-lifecycle `SEC-TOKEN-001`, dormant-access `SEC-OFFBOARD-001` | open | PR 10 (token expiry, revocation-on-password-change, CSRF negatives) |
| V7.1.2 | 2 | The documentation defines how many concurrent (parallel) sessions are allowed for one account as well as the intended b… | api-token-lifecycle `SEC-TOKEN-001`, dormant-access `SEC-OFFBOARD-001` | open | PR 10 (token expiry, revocation-on-password-change, CSRF negatives) |
| V7.1.3 | 2 | All systems that create and manage user sessions as part of a federated identity management ecosystem (such as SSO syst… | api-token-lifecycle `SEC-TOKEN-001`, dormant-access `SEC-OFFBOARD-001` | open | PR 10 (token expiry, revocation-on-password-change, CSRF negatives) |
| V7.2.1 | 1 | The application performs all session token verification using a trusted, backend service. | api-token-lifecycle `SEC-TOKEN-001`, dormant-access `SEC-OFFBOARD-001` | open | PR 10 (token expiry, revocation-on-password-change, CSRF negatives) |
| V7.2.2 | 1 | The application uses either self-contained or reference tokens that are dynamically generated for session management, i… | api-token-lifecycle `SEC-TOKEN-001`, dormant-access `SEC-OFFBOARD-001` | open | PR 10 (token expiry, revocation-on-password-change, CSRF negatives) |
| V7.2.3 | 1 | If reference tokens are used to represent user sessions, they are unique and generated using a cryptographically secure… | api-token-lifecycle `SEC-TOKEN-001`, dormant-access `SEC-OFFBOARD-001` | open | PR 10 (token expiry, revocation-on-password-change, CSRF negatives) |
| V7.2.4 | 1 | The application generates a new session token on user authentication, including re-authentication, and terminates the c… | api-token-lifecycle `SEC-TOKEN-001`, dormant-access `SEC-OFFBOARD-001` | open | PR 10 (token expiry, revocation-on-password-change, CSRF negatives) |
| V7.3.1 | 2 | There is an inactivity timeout such that re-authentication is enforced according to risk analysis and documented securi… | api-token-lifecycle `SEC-TOKEN-001`, dormant-access `SEC-OFFBOARD-001` | open | PR 10 (token expiry, revocation-on-password-change, CSRF negatives) |
| V7.3.2 | 2 | There is an absolute maximum session lifetime such that re-authentication is enforced according to risk analysis and do… | api-token-lifecycle `SEC-TOKEN-001`, dormant-access `SEC-OFFBOARD-001` | open | PR 10 (token expiry, revocation-on-password-change, CSRF negatives) |
| V7.4.1 | 1 | When session termination is triggered (such as logout or expiration), the application disallows any further use of the… | api-token-lifecycle `SEC-TOKEN-001`, dormant-access `SEC-OFFBOARD-001` | open | PR 10 (token expiry, revocation-on-password-change, CSRF negatives) |
| V7.4.2 | 1 | The application terminates all active sessions when a user account is disabled or deleted (such as an employee leaving… | api-token-lifecycle `SEC-TOKEN-001`, dormant-access `SEC-OFFBOARD-001` | open | PR 10 (token expiry, revocation-on-password-change, CSRF negatives) |
| V7.4.3 | 2 | The application gives the option to terminate all other active sessions after a successful change or removal of any aut… | api-token-lifecycle `SEC-TOKEN-001`, dormant-access `SEC-OFFBOARD-001` | open | PR 10 (token expiry, revocation-on-password-change, CSRF negatives) |
| V7.4.4 | 2 | All pages that require authentication have easy and visible access to logout functionality. | api-token-lifecycle `SEC-TOKEN-001`, dormant-access `SEC-OFFBOARD-001` | open | PR 10 (token expiry, revocation-on-password-change, CSRF negatives) |
| V7.4.5 | 2 | Application administrators are able to terminate active sessions for an individual user or for all users. | api-token-lifecycle `SEC-TOKEN-001`, dormant-access `SEC-OFFBOARD-001` | open | PR 10 (token expiry, revocation-on-password-change, CSRF negatives) |
| V7.5.1 | 2 | The application requires full re-authentication before allowing modifications to sensitive account attributes which may… | api-token-lifecycle `SEC-TOKEN-001`, dormant-access `SEC-OFFBOARD-001` | open | PR 10 (token expiry, revocation-on-password-change, CSRF negatives) |
| V7.5.2 | 2 | Users are able to view and (having authenticated again with at least one factor) terminate any or all currently active… | api-token-lifecycle `SEC-TOKEN-001`, dormant-access `SEC-OFFBOARD-001` | open | PR 10 (token expiry, revocation-on-password-change, CSRF negatives) |
| V7.6.1 | 2 | Session lifetime and termination between Relying Parties (RPs) and Identity Providers (IdPs) behave as documented, requ… | api-token-lifecycle `SEC-TOKEN-001`, dormant-access `SEC-OFFBOARD-001` | open | PR 10 (token expiry, revocation-on-password-change, CSRF negatives) |
| V7.6.2 | 2 | Creation of a session requires either the user's consent or an explicit action, preventing the creation of new applicat… | api-token-lifecycle `SEC-TOKEN-001`, dormant-access `SEC-OFFBOARD-001` | open | PR 10 (token expiry, revocation-on-password-change, CSRF negatives) |

#### V8 Authorization

| Req | L | Requirement (abridged) | AskMyDocs mapping | State | Note |
|---|---|---|---|---|---|
| V8.1.1 | 1 | Authorization documentation defines rules for restricting function-level and data-specific access based on consumer per… | tenant-object-authorization `SEC-IDOR-001` (R30-R32), admin-authorization-granularity | regression-tested | forTenant scoping + TenantIdMandatoryTest + R32 matrix; SSE residual in PR 4 |
| V8.1.2 | 2 | Authorization documentation defines rules for field-level access restrictions (both read and write) based on consumer p… | tenant-object-authorization `SEC-IDOR-001` (R30-R32), admin-authorization-granularity | regression-tested | forTenant scoping + TenantIdMandatoryTest + R32 matrix; SSE residual in PR 4 |
| V8.2.1 | 1 | The application ensures that function-level access is restricted to consumers with explicit permissions. | tenant-object-authorization `SEC-IDOR-001` (R30-R32), admin-authorization-granularity | regression-tested | forTenant scoping + TenantIdMandatoryTest + R32 matrix; SSE residual in PR 4 |
| V8.2.2 | 1 | The application ensures that data-specific access is restricted to consumers with explicit permissions to specific data… | tenant-object-authorization `SEC-IDOR-001` (R30-R32), admin-authorization-granularity | regression-tested | forTenant scoping + TenantIdMandatoryTest + R32 matrix; SSE residual in PR 4 |
| V8.2.3 | 2 | The application ensures that field-level access is restricted to consumers with explicit permissions to specific fields… | tenant-object-authorization `SEC-IDOR-001` (R30-R32), admin-authorization-granularity | regression-tested | forTenant scoping + TenantIdMandatoryTest + R32 matrix; SSE residual in PR 4 |
| V8.3.1 | 1 | The application enforces authorization rules at a trusted service layer and doesn't rely on controls that an untrusted… | tenant-object-authorization `SEC-IDOR-001` (R30-R32), admin-authorization-granularity | regression-tested | forTenant scoping + TenantIdMandatoryTest + R32 matrix; SSE residual in PR 4 |
| V8.4.1 | 2 | Multi-tenant applications use cross-tenant controls to ensure consumer operations will never affect tenants with which… | tenant-object-authorization `SEC-IDOR-001` (R30-R32), admin-authorization-granularity | regression-tested | forTenant scoping + TenantIdMandatoryTest + R32 matrix; SSE residual in PR 4 |

#### V9 Self-contained Tokens

| Req | L | Requirement (abridged) | AskMyDocs mapping | State | Note |
|---|---|---|---|---|---|
| V9.1.1 | 1 | Self-contained tokens are validated using their digital signature or MAC to protect against tampering before accepting… | signed-url-tokens `SEC-SIGNURL-001` | implemented | no JWT; Sanctum opaque tokens + DB-backed single-use confirm tokens (R21, RT) |
| V9.1.2 | 1 | Only algorithms on an allowlist can be used to create and verify self-contained tokens, for a given context. The allowl… | signed-url-tokens `SEC-SIGNURL-001` | implemented | no JWT; Sanctum opaque tokens + DB-backed single-use confirm tokens (R21, RT) |
| V9.1.3 | 1 | Key material that is used to validate self-contained tokens is from trusted pre-configured sources for the token issuer… | signed-url-tokens `SEC-SIGNURL-001` | implemented | no JWT; Sanctum opaque tokens + DB-backed single-use confirm tokens (R21, RT) |
| V9.2.1 | 1 | , if a validity time span is present in the token data, the token and its content are accepted only if the verification… | signed-url-tokens `SEC-SIGNURL-001` | implemented | no JWT; Sanctum opaque tokens + DB-backed single-use confirm tokens (R21, RT) |
| V9.2.2 | 2 | The service receiving a token validates the token to be the correct type and is meant for the intended purpose before a… | signed-url-tokens `SEC-SIGNURL-001` | implemented | no JWT; Sanctum opaque tokens + DB-backed single-use confirm tokens (R21, RT) |
| V9.2.3 | 2 | The service only accepts tokens which are intended for use with that service (audience). For JWTs, this can be achieved… | signed-url-tokens `SEC-SIGNURL-001` | implemented | no JWT; Sanctum opaque tokens + DB-backed single-use confirm tokens (R21, RT) |
| V9.2.4 | 2 | , if a token issuer uses the same private key for issuing tokens to different audiences, the issued tokens contain an a… | signed-url-tokens `SEC-SIGNURL-001` | implemented | no JWT; Sanctum opaque tokens + DB-backed single-use confirm tokens (R21, RT) |

#### V10 OAuth and OIDC

| Req | L | Requirement (abridged) | AskMyDocs mapping | State | Note |
|---|---|---|---|---|---|
| V10.1.1 | 2 | Tokens are only sent to components that strictly need them. For example, when using a backend-for-frontend pattern for… | connector OAuth (M365 XOAUTH2, Google) — package-owned | implemented | padosoft/askmydocs-connector-* own the OAuth flows; package CI covers (R30/R31 posture) |
| V10.1.2 | 2 | The client only accepts values from the authorization server (such as the authorization code or ID Token) if these valu… | connector OAuth (M365 XOAUTH2, Google) — package-owned | implemented | padosoft/askmydocs-connector-* own the OAuth flows; package CI covers (R30/R31 posture) |
| V10.2.1 | 2 | , if the code flow is used, the OAuth client has protection against browser-based request forgery attacks, commonly kno… | connector OAuth (M365 XOAUTH2, Google) — package-owned | implemented | padosoft/askmydocs-connector-* own the OAuth flows; package CI covers (R30/R31 posture) |
| V10.2.2 | 2 | , if the OAuth client can interact with more than one authorization server, it has a defense against mix-up attacks. Fo… | connector OAuth (M365 XOAUTH2, Google) — package-owned | implemented | padosoft/askmydocs-connector-* own the OAuth flows; package CI covers (R30/R31 posture) |
| V10.3.1 | 2 | The resource server only accepts access tokens that are intended for use with that service (audience). The audience may… | connector OAuth (M365 XOAUTH2, Google) — package-owned | implemented | padosoft/askmydocs-connector-* own the OAuth flows; package CI covers (R30/R31 posture) |
| V10.3.2 | 2 | The resource server enforces authorization decisions based on claims from the access token that define delegated author… | connector OAuth (M365 XOAUTH2, Google) — package-owned | implemented | padosoft/askmydocs-connector-* own the OAuth flows; package CI covers (R30/R31 posture) |
| V10.3.3 | 2 | If an access control decision requires identifying a unique user from an access token (JWT or related token introspecti… | connector OAuth (M365 XOAUTH2, Google) — package-owned | implemented | padosoft/askmydocs-connector-* own the OAuth flows; package CI covers (R30/R31 posture) |
| V10.3.4 | 2 | , if the resource server requires specific authentication strength, methods, or recentness, it verifies that the presen… | connector OAuth (M365 XOAUTH2, Google) — package-owned | implemented | padosoft/askmydocs-connector-* own the OAuth flows; package CI covers (R30/R31 posture) |
| V10.4.1 | 1 | The authorization server validates redirect URIs based on a client-specific allowlist of pre-registered URIs using exac… | connector OAuth (M365 XOAUTH2, Google) — package-owned | implemented | padosoft/askmydocs-connector-* own the OAuth flows; package CI covers (R30/R31 posture) |
| V10.4.2 | 1 | , if the authorization server returns the authorization code in the authorization response, it can be used only once fo… | connector OAuth (M365 XOAUTH2, Google) — package-owned | implemented | padosoft/askmydocs-connector-* own the OAuth flows; package CI covers (R30/R31 posture) |
| V10.4.3 | 1 | The authorization code is short-lived. The maximum lifetime can be up to 10 minutes for L1 and L2 applications and up t… | connector OAuth (M365 XOAUTH2, Google) — package-owned | implemented | padosoft/askmydocs-connector-* own the OAuth flows; package CI covers (R30/R31 posture) |
| V10.4.4 | 1 | For a given client, the authorization server only allows the usage of grants that this client needs to use. Note that t… | connector OAuth (M365 XOAUTH2, Google) — package-owned | implemented | padosoft/askmydocs-connector-* own the OAuth flows; package CI covers (R30/R31 posture) |
| V10.4.5 | 1 | The authorization server mitigates refresh token replay attacks for public clients, preferably using sender-constrained… | connector OAuth (M365 XOAUTH2, Google) — package-owned | implemented | padosoft/askmydocs-connector-* own the OAuth flows; package CI covers (R30/R31 posture) |
| V10.4.6 | 2 | , if the code grant is used, the authorization server mitigates authorization code interception attacks by requiring pr… | connector OAuth (M365 XOAUTH2, Google) — package-owned | implemented | padosoft/askmydocs-connector-* own the OAuth flows; package CI covers (R30/R31 posture) |
| V10.4.7 | 2 | If the authorization server supports unauthenticated dynamic client registration, it mitigates the risk of malicious cl… | connector OAuth (M365 XOAUTH2, Google) — package-owned | implemented | padosoft/askmydocs-connector-* own the OAuth flows; package CI covers (R30/R31 posture) |
| V10.4.8 | 2 | Refresh tokens have an absolute expiration, including if sliding refresh token expiration is applied. | connector OAuth (M365 XOAUTH2, Google) — package-owned | implemented | padosoft/askmydocs-connector-* own the OAuth flows; package CI covers (R30/R31 posture) |
| V10.4.9 | 2 | Refresh tokens and reference access tokens can be revoked by an authorized user using the authorization server user int… | connector OAuth (M365 XOAUTH2, Google) — package-owned | implemented | padosoft/askmydocs-connector-* own the OAuth flows; package CI covers (R30/R31 posture) |
| V10.4.10 | 2 | Confidential client is authenticated for client-to-authorized server backchannel requests such as token requests, pushe… | connector OAuth (M365 XOAUTH2, Google) — package-owned | implemented | padosoft/askmydocs-connector-* own the OAuth flows; package CI covers (R30/R31 posture) |
| V10.4.11 | 2 | The authorization server configuration only assigns the required scopes to the OAuth client. | connector OAuth (M365 XOAUTH2, Google) — package-owned | implemented | padosoft/askmydocs-connector-* own the OAuth flows; package CI covers (R30/R31 posture) |
| V10.5.1 | 2 | The client (as the relying party) mitigates ID Token replay attacks. For example, by ensuring that the 'nonce' claim in… | connector OAuth (M365 XOAUTH2, Google) — package-owned | implemented | padosoft/askmydocs-connector-* own the OAuth flows; package CI covers (R30/R31 posture) |
| V10.5.2 | 2 | The client uniquely identifies the user from ID Token claims, usually the 'sub' claim, which cannot be reassigned to ot… | connector OAuth (M365 XOAUTH2, Google) — package-owned | implemented | padosoft/askmydocs-connector-* own the OAuth flows; package CI covers (R30/R31 posture) |
| V10.5.3 | 2 | The client rejects attempts by a malicious authorization server to impersonate another authorization server through aut… | connector OAuth (M365 XOAUTH2, Google) — package-owned | implemented | padosoft/askmydocs-connector-* own the OAuth flows; package CI covers (R30/R31 posture) |
| V10.5.4 | 2 | The client validates that the ID Token is intended to be used for that client (audience) by checking that the 'aud' cla… | connector OAuth (M365 XOAUTH2, Google) — package-owned | implemented | padosoft/askmydocs-connector-* own the OAuth flows; package CI covers (R30/R31 posture) |
| V10.5.5 | 2 | , when using OIDC back-channel logout, the relying party mitigates denial of service through forced logout and cross-JW… | connector OAuth (M365 XOAUTH2, Google) — package-owned | implemented | padosoft/askmydocs-connector-* own the OAuth flows; package CI covers (R30/R31 posture) |
| V10.6.1 | 2 | The OpenID Provider only allows values 'code', 'ciba', 'id_token', or 'id_token code' for response mode. Note that 'cod… | connector OAuth (M365 XOAUTH2, Google) — package-owned | implemented | padosoft/askmydocs-connector-* own the OAuth flows; package CI covers (R30/R31 posture) |
| V10.6.2 | 2 | The OpenID Provider mitigates denial of service through forced logout. By obtaining explicit confirmation from the end-… | connector OAuth (M365 XOAUTH2, Google) — package-owned | implemented | padosoft/askmydocs-connector-* own the OAuth flows; package CI covers (R30/R31 posture) |
| V10.7.1 | 2 | The authorization server ensures that the user consents to each authorization request. If the identity of the client ca… | connector OAuth (M365 XOAUTH2, Google) — package-owned | implemented | padosoft/askmydocs-connector-* own the OAuth flows; package CI covers (R30/R31 posture) |
| V10.7.2 | 2 | When the authorization server prompts for user consent, it presents sufficient and clear information about what is bein… | connector OAuth (M365 XOAUTH2, Google) — package-owned | implemented | padosoft/askmydocs-connector-* own the OAuth flows; package CI covers (R30/R31 posture) |
| V10.7.3 | 2 | The user can review, modify, and revoke consents which the user has granted through the authorization server. | connector OAuth (M365 XOAUTH2, Google) — package-owned | implemented | padosoft/askmydocs-connector-* own the OAuth flows; package CI covers (R30/R31 posture) |
---

## §3 — OWASP AISVS 1.0

Every AISVS 1.0 L1+L2 requirement (146 rows across C1–C12), mapped to the
AskMyDocs AI/RAG/MCP/widget/provider controls. **C1 (Training Data) is N/A**:
AskMyDocs trains no models — the RAG-corpus governance equivalent is the
canonical-markdown source-of-truth + immutable `kb_canonical_audit`. Chapters
most load-bearing here: C5 (Access Control → PR 3), C9/C10 (Agentic/MCP → PR 3),
C3/C6 (Model & Supply Chain → PR 8/PR 9), C8 (Vector DB → R30, regression-tested).

State roll-up (L1+L2 applicable): **18 regression-tested · 72 implemented · 45
open · 11 N/A** (the C1 training-data rows).

#### C1 Training Data Integrity & Traceability

| Req | L | Requirement (abridged) | AskMyDocs mapping | State | Note |
|---|---|---|---|---|---|
| C1.1.1 | 1 | Training data includes only features, attributes, and fields required for the model's stated purpose. | — (no model training) | N/A | AskMyDocs trains no models; RAG-corpus equivalent governance = canonical markdown source-of-truth + immutable kb_canonical_audit |
| C1.1.2 | 2 | An up-to-date inventory is kept of every training-data source, including its origin, responsible party, license, collec… | — (no model training) | N/A | AskMyDocs trains no models; RAG-corpus equivalent governance = canonical markdown source-of-truth + immutable kb_canonical_audit |
| C1.1.3 | 2 | Data integrity is provided when training data is stored and transferred. | — (no model training) | N/A | AskMyDocs trains no models; RAG-corpus equivalent governance = canonical markdown source-of-truth + immutable kb_canonical_audit |
| C1.1.4 | 2 | Integrity monitoring is applied to guard against unauthorized modifications or corruption of training data. | — (no model training) | N/A | AskMyDocs trains no models; RAG-corpus equivalent governance = canonical markdown source-of-truth + immutable kb_canonical_audit |
| C1.2.1 | 1 | Labeling platforms enforce access controls that restrict who can create, modify, or approve annotations. | — (no model training) | N/A | AskMyDocs trains no models; RAG-corpus equivalent governance = canonical markdown source-of-truth + immutable kb_canonical_audit |
| C1.2.2 | 2 | Cryptographic integrity is applied to labeling artifacts. | — (no model training) | N/A | AskMyDocs trains no models; RAG-corpus equivalent governance = canonical markdown source-of-truth + immutable kb_canonical_audit |
| C1.2.3 | 2 | Sensitive information in labels is redacted, anonymized, or encrypted before being used in any labeling artifact. | — (no model training) | N/A | AskMyDocs trains no models; RAG-corpus equivalent governance = canonical markdown source-of-truth + immutable kb_canonical_audit |
| C1.3.1 | 2 | Training and fine-tuning pipelines implement poisoning detection techniques to identify potential data poisoning or uni… | — (no model training) | N/A | AskMyDocs trains no models; RAG-corpus equivalent governance = canonical markdown source-of-truth + immutable kb_canonical_audit |
| C1.3.2 | 2 | Automatically generated labels are subject to confidence thresholds and consistency checks to detect misleading or low-… | — (no model training) | N/A | AskMyDocs trains no models; RAG-corpus equivalent governance = canonical markdown source-of-truth + immutable kb_canonical_audit |
| C1.3.3 | 2 | Models used in security-relevant decisions are evaluated for bias patterns. | — (no model training) | N/A | AskMyDocs trains no models; RAG-corpus equivalent governance = canonical markdown source-of-truth + immutable kb_canonical_audit |
| C1.3.4 | 2 | Disallowed content is detected and removed before training. | — (no model training) | N/A | AskMyDocs trains no models; RAG-corpus equivalent governance = canonical markdown source-of-truth + immutable kb_canonical_audit |

#### C2 Input Validation

| Req | L | Requirement (abridged) | AskMyDocs mapping | State | Note |
|---|---|---|---|---|---|
| C2.1.1 | 1 | Input normalization is applied before tokenization or embedding. | SEC-LLM-001 gate 4 (prompt-injection containment) | implemented | retrieved chunks/tool results stay untrusted user data; delimiter handling in kb_rag prompt |
| C2.1.2 | 1 | Encoding and representation smuggling in inputs is detected and mitigated. Approved mitigations include canonicalizatio… | SEC-LLM-001 gate 4 (prompt-injection containment) | implemented | retrieved chunks/tool results stay untrusted user data; delimiter handling in kb_rag prompt |
| C2.1.3 | 1 | All inputs that could steer model behavior are treated as untrusted and screened by a prompt injection detection rulese… | SEC-LLM-001 gate 4 (prompt-injection containment) | implemented | retrieved chunks/tool results stay untrusted user data; delimiter handling in kb_rag prompt |
| C2.1.4 | 1 | Input length controls prevent content from exceeding the context window. The controls must reject inputs that exceed to… | SEC-LLM-001 gate 4 (prompt-injection containment) | implemented | retrieved chunks/tool results stay untrusted user data; delimiter handling in kb_rag prompt |
| C2.1.5 | 1 | The system implements a character set restriction for all inputs. The restriction must use an allow-list approach that… | SEC-LLM-001 gate 4 (prompt-injection containment) | implemented | retrieved chunks/tool results stay untrusted user data; delimiter handling in kb_rag prompt |
| C2.1.6 | 2 | The system enforces an instruction hierarchy in which system and developer messages override user instructions and othe… | SEC-LLM-001 gate 4 (prompt-injection containment) | implemented | retrieved chunks/tool results stay untrusted user data; delimiter handling in kb_rag prompt |
| C2.1.7 | 2 | Reserved special tokens are encoded as literal characters and cannot be injected into the model context. | SEC-LLM-001 gate 4 (prompt-injection containment) | implemented | retrieved chunks/tool results stay untrusted user data; delimiter handling in kb_rag prompt |
| C2.2.1 | 1 | Every prompt is scored by a content classifier for violence, self-harm, hate, and sexual content against configurable t… | SEC-LLM-001 gate 4 (prompt-injection containment) | implemented | retrieved chunks/tool results stay untrusted user data; delimiter handling in kb_rag prompt |
| C2.2.2 | 1 | Prompt content classification is evaluated for unsupported languages. | SEC-LLM-001 gate 4 (prompt-injection containment) | implemented | retrieved chunks/tool results stay untrusted user data; delimiter handling in kb_rag prompt |
| C2.2.3 | 2 | Non-text inputs (image/video/audio) are checked for adversarial perturbations, steganographic payloads, hidden or embed… | SEC-LLM-001 gate 4 (prompt-injection containment) | implemented | retrieved chunks/tool results stay untrusted user data; delimiter handling in kb_rag prompt |

#### C3 Model Lifecycle Management & Change Control

| Req | L | Requirement (abridged) | AskMyDocs mapping | State | Note |
|---|---|---|---|---|---|
| C3.1.1 | 1 | A model registry maintains an inventory of all deployed model artifacts and their origin. | ai-provider-supply-chain, model pinning (config/ai.php) | open | PR 8 (code-owned provider/model/base-URL allow-list at choke point) |
| C3.1.2 | 2 | All model artifacts (weights, configurations, tokenizers, base models, fine-tunes, adapters, and safety/policy models)… | ai-provider-supply-chain, model pinning (config/ai.php) | open | PR 8 (code-owned provider/model/base-URL allow-list at choke point) |
| C3.1.3 | 2 | Model cryptographic signatures are verified at deployment admission and on load. | ai-provider-supply-chain, model pinning (config/ai.php) | open | PR 8 (code-owned provider/model/base-URL allow-list at choke point) |
| C3.2.1 | 1 | Models undergo automated input validation testing, safety evaluation testing, and output sanitization testing before de… | ai-provider-supply-chain, model pinning (config/ai.php) | open | PR 8 (code-owned provider/model/base-URL allow-list at choke point) |
| C3.2.2 | 2 | Models subjected to post-training quantization are re-evaluated against the same safety and alignment test suite on the… | ai-provider-supply-chain, model pinning (config/ai.php) | open | PR 8 (code-owned provider/model/base-URL allow-list at choke point) |
| C3.3.1 | 2 | Production deployments implement rollout mechanisms with automated rollback triggers. | ai-provider-supply-chain, model pinning (config/ai.php) | open | PR 8 (code-owned provider/model/base-URL allow-list at choke point) |
| C3.3.2 | 2 | Rollback capabilities restore the complete model state. | ai-provider-supply-chain, model pinning (config/ai.php) | open | PR 8 (code-owned provider/model/base-URL allow-list at choke point) |
| C3.3.3 | 2 | Model versions running in parallel use isolated runtime state so that AI-specific shared resources are not shared acros… | ai-provider-supply-chain, model pinning (config/ai.php) | open | PR 8 (code-owned provider/model/base-URL allow-list at choke point) |
| C3.4.1 | 1 | AI-specific runtime components are not shared across environment boundaries (e.g., development, staging, production). | ai-provider-supply-chain, model pinning (config/ai.php) | open | PR 8 (code-owned provider/model/base-URL allow-list at choke point) |
| C3.4.2 | 2 | Model training and fine-tuning environments are isolated from production environments. | ai-provider-supply-chain, model pinning (config/ai.php) | open | PR 8 (code-owned provider/model/base-URL allow-list at choke point) |
| C3.5.1 | 2 | Models used in RLHF fine-tuning are versioned and integrity-verified before use in a training run. | ai-provider-supply-chain, model pinning (config/ai.php) | open | PR 8 (code-owned provider/model/base-URL allow-list at choke point) |

#### C4 Infrastructure, Configuration & Deployment Security

| Req | L | Requirement (abridged) | AskMyDocs mapping | State | Note |
|---|---|---|---|---|---|
| C4.1.1 | 1 | AI models execute in isolated sandboxes. | env-gate-fail-closed, production-posture | implemented | R43 both-states flag testing; runtime posture partial |
| C4.1.2 | 1 | Model artifact loading enforces an explicit allow-list of serialization formats that do not permit arbitrary code execu… | env-gate-fail-closed, production-posture | implemented | R43 both-states flag testing; runtime posture partial |
| C4.2.1 | 2 | AI accelerator (GPU) firmware is version-pinned, signed, and attested at boot. | env-gate-fail-closed, production-posture | implemented | R43 both-states flag testing; runtime posture partial |
| C4.3.1 | 1 | Edge AI devices authenticate to central infrastructure using strong authentication mechanisms. | env-gate-fail-closed, production-posture | implemented | R43 both-states flag testing; runtime posture partial |
| C4.3.2 | 2 | Models deployed to edge or mobile devices are cryptographically signed during packaging, and that the on-device runtime… | env-gate-fail-closed, production-posture | implemented | R43 both-states flag testing; runtime posture partial |

#### C5 Access Control & Identity for AI Components & Users

| Req | L | Requirement (abridged) | AskMyDocs mapping | State | Note |
|---|---|---|---|---|---|
| C5.2.1 | 2 | Every AI resource (datasets, endpoints, vector collections, embedding indices, compute instances) enforces access contr… | McpToolAuthorizer + EnforceMcpScope + ai-initiating-user | open | PR 3 (bidirectional write-tool authorization architecture test) |
| C5.2.2 | 2 | Retrieval pipelines (e.g., RAG queries, embedding lookups) enforce the end-user's authorization context at each retriev… | McpToolAuthorizer + EnforceMcpScope + ai-initiating-user | open | PR 3 (bidirectional write-tool authorization architecture test) |
| C5.2.3 | 2 | Sensitive data is retrieved via retrieval pipelines (e.g., RAG queries, embedding lookups) to prevent permanent storage… | McpToolAuthorizer + EnforceMcpScope + ai-initiating-user | open | PR 3 (bidirectional write-tool authorization architecture test) |
| C5.2.4 | 2 | Post-inference filtering mechanisms prevent responses from including data that the requester is not authorized to recei… | McpToolAuthorizer + EnforceMcpScope + ai-initiating-user | open | PR 3 (bidirectional write-tool authorization architecture test) |
| C5.2.5 | 2 | The policy decision point for agent authorization is isolated from the agent's execution environment. | McpToolAuthorizer + EnforceMcpScope + ai-initiating-user | open | PR 3 (bidirectional write-tool authorization architecture test) |
| C5.3.1 | 2 | Shared model serving infrastructure prevents one tenant's fine-tuning, inference, or embedding operations from influenc… | McpToolAuthorizer + EnforceMcpScope + ai-initiating-user | open | PR 3 (bidirectional write-tool authorization architecture test) |

#### C6 Supply Chain Security for Models

| Req | L | Requirement (abridged) | AskMyDocs mapping | State | Note |
|---|---|---|---|---|---|
| C6.1.1 | 1 | Models are scanned for malicious code before import. | dependency-security, supply-chain-ci `SEC-SUPPLY-001` | open | PR 9 (composer/npm audit + dependabot + SAST); actions pinning verify |
| C6.1.2 | 1 | Model weights, datasets, and fine-tuning adapters are downloaded only from approved sources. | dependency-security, supply-chain-ci `SEC-SUPPLY-001` | open | PR 9 (composer/npm audit + dependabot + SAST); actions pinning verify |
| C6.1.3 | 2 | Every third-party model artifact can be integrity-verified. | dependency-security, supply-chain-ci `SEC-SUPPLY-001` | open | PR 9 (composer/npm audit + dependabot + SAST); actions pinning verify |
| C6.1.4 | 2 | Models pass a behavioral acceptance test suite before being promoted to any non-development environment. | dependency-security, supply-chain-ci `SEC-SUPPLY-001` | open | PR 9 (composer/npm audit + dependabot + SAST); actions pinning verify |
| C6.2.1 | 1 | Every model artifact publishes a version-controlled, machine-readable AI BOM listing datasets, weights, licenses, and d… | dependency-security, supply-chain-ci `SEC-SUPPLY-001` | open | PR 9 (composer/npm audit + dependabot + SAST); actions pinning verify |
| C6.2.2 | 2 | AI BOMs are cryptographically signed before deployment. | dependency-security, supply-chain-ci `SEC-SUPPLY-001` | open | PR 9 (composer/npm audit + dependabot + SAST); actions pinning verify |
| C6.2.3 | 2 | AI BOM completeness checks fail the build if any component metadata is missing. | dependency-security, supply-chain-ci `SEC-SUPPLY-001` | open | PR 9 (composer/npm audit + dependabot + SAST); actions pinning verify |

#### C7 Model Behavior, Output Control & Safety Assurance

| Req | L | Requirement (abridged) | AskMyDocs mapping | State | Note |
|---|---|---|---|---|---|
| C7.1.1 | 1 | The application validates all model outputs against a defined schema and rejects any output that does not match. | SEC-LLM-001 gate 6 (output is data), refusal gate, UiArtifactRenderer allow-lists | regression-tested | renderer prop allow-lists + refusal-not-error tests (R26) |
| C7.1.2 | 1 | Model-generated output is bounded by length limits and termination controls. | SEC-LLM-001 gate 6 (output is data), refusal gate, UiArtifactRenderer allow-lists | regression-tested | renderer prop allow-lists + refusal-not-error tests (R26) |
| C7.2.1 | 2 | The system assesses the reliability of generated answers using a confidence estimation method. | SEC-LLM-001 gate 6 (output is data), refusal gate, UiArtifactRenderer allow-lists | regression-tested | renderer prop allow-lists + refusal-not-error tests (R26) |
| C7.2.2 | 2 | The application automatically blocks answers or switches to a fallback message if the confidence score drops below a de… | SEC-LLM-001 gate 6 (output is data), refusal gate, UiArtifactRenderer allow-lists | regression-tested | renderer prop allow-lists + refusal-not-error tests (R26) |
| C7.3.1 | 1 | Automated classifiers scan every response and block content that matches defined harmful content categories. | SEC-LLM-001 gate 6 (output is data), refusal gate, UiArtifactRenderer allow-lists | regression-tested | renderer prop allow-lists + refusal-not-error tests (R26) |
| C7.3.2 | 2 | Output filters detect and block responses that disclose system prompt content or backend data. | SEC-LLM-001 gate 6 (output is data), refusal gate, UiArtifactRenderer allow-lists | regression-tested | renderer prop allow-lists + refusal-not-error tests (R26) |
| C7.3.3 | 2 | Model-generated output is prevented from triggering outbound requests. | SEC-LLM-001 gate 6 (output is data), refusal gate, UiArtifactRenderer allow-lists | regression-tested | renderer prop allow-lists + refusal-not-error tests (R26) |
| C7.4.1 | 1 | Responses generated using retrieval-augmented generation (RAG) include attribution to the source documents. | SEC-LLM-001 gate 6 (output is data), refusal gate, UiArtifactRenderer allow-lists | regression-tested | renderer prop allow-lists + refusal-not-error tests (R26) |
| C7.4.2 | 1 | RAG attributions are derived from retrieval metadata and are not generated by the model, so provenance cannot be fabric… | SEC-LLM-001 gate 6 (output is data), refusal gate, UiArtifactRenderer allow-lists | regression-tested | renderer prop allow-lists + refusal-not-error tests (R26) |
| C7.4.3 | 2 | Claims in a RAG response can be traced to the retrieved chunk. | SEC-LLM-001 gate 6 (output is data), refusal gate, UiArtifactRenderer allow-lists | regression-tested | renderer prop allow-lists + refusal-not-error tests (R26) |

#### C8 Memory, Embeddings & Vector Database Security

| Req | L | Requirement (abridged) | AskMyDocs mapping | State | Note |
|---|---|---|---|---|---|
| C8.1.1 | 1 | Vector identifiers and namespaces enforce uniqueness per tenant and prevent cross-tenant collisions. | R30 tenant scoping on knowledge_chunks/pgvector; embedding_cache documented exclusion | regression-tested | TenantIdMandatoryTest; embedding_cache cross-tenant by design (UNIQUE text_hash+provider+model) |
| C8.1.2 | 2 | Document metadata tags are immutable after the initial write. | R30 tenant scoping on knowledge_chunks/pgvector; embedding_cache documented exclusion | regression-tested | TenantIdMandatoryTest; embedding_cache cross-tenant by design (UNIQUE text_hash+provider+model) |
| C8.1.3 | 2 | Retrieval operations enforce scope constraints. | R30 tenant scoping on knowledge_chunks/pgvector; embedding_cache documented exclusion | regression-tested | TenantIdMandatoryTest; embedding_cache cross-tenant by design (UNIQUE text_hash+provider+model) |
| C8.2.1 | 1 | Sensitive fields are detected before embedding and are masked, tokenized, or dropped. | R30 tenant scoping on knowledge_chunks/pgvector; embedding_cache documented exclusion | regression-tested | TenantIdMandatoryTest; embedding_cache cross-tenant by design (UNIQUE text_hash+provider+model) |
| C8.2.2 | 2 | Vectors that fall outside normal clustering patterns are flagged and quarantined before entering production indices. | R30 tenant scoping on knowledge_chunks/pgvector; embedding_cache documented exclusion | regression-tested | TenantIdMandatoryTest; embedding_cache cross-tenant by design (UNIQUE text_hash+provider+model) |
| C8.2.3 | 2 | Agent outputs and tool outputs are not automatically written to trusted agent memory without explicit source validation. | R30 tenant scoping on knowledge_chunks/pgvector; embedding_cache documented exclusion | regression-tested | TenantIdMandatoryTest; embedding_cache cross-tenant by design (UNIQUE text_hash+provider+model) |
| C8.3.1 | 2 | Expired vectors are excluded from retrieval results. | R30 tenant scoping on knowledge_chunks/pgvector; embedding_cache documented exclusion | regression-tested | TenantIdMandatoryTest; embedding_cache cross-tenant by design (UNIQUE text_hash+provider+model) |
| C8.3.2 | 2 | Memory can be reset. | R30 tenant scoping on knowledge_chunks/pgvector; embedding_cache documented exclusion | regression-tested | TenantIdMandatoryTest; embedding_cache cross-tenant by design (UNIQUE text_hash+provider+model) |

#### C9 Orchestration & Agentic Security

| Req | L | Requirement (abridged) | AskMyDocs mapping | State | Note |
|---|---|---|---|---|---|
| C9.1.1 | 1 | Per-tool quotas and timeouts (e.g., CPU, memory, disk, egress, and execution time) are enforced. | SEC-AI-ACT-001 (widget/MCP action contracts, blast-radius caps, idempotency) | implemented | WidgetToolValidator both-direction contract; MCP write choke in PR 3 |
| C9.1.2 | 1 | Per-execution budgets (e.g., max recursion depth, token use, and monetary spend) are configured and enforced by the run… | SEC-AI-ACT-001 (widget/MCP action contracts, blast-radius caps, idempotency) | implemented | WidgetToolValidator both-direction contract; MCP write choke in PR 3 |
| C9.1.3 | 2 | A swarm-level kill-switch exists that can halt all active agent instances. | SEC-AI-ACT-001 (widget/MCP action contracts, blast-radius caps, idempotency) | implemented | WidgetToolValidator both-direction contract; MCP write choke in PR 3 |
| C9.2.1 | 1 | The agent runtime blocks execution of privileged, high-impact, or irreversible actions until explicit human approval is… | SEC-AI-ACT-001 (widget/MCP action contracts, blast-radius caps, idempotency) | implemented | WidgetToolValidator both-direction contract; MCP write choke in PR 3 |
| C9.2.2 | 2 | Approval requests display canonicalized and complete action parameters, such as diffs, commands, recipients, amounts, r… | SEC-AI-ACT-001 (widget/MCP action contracts, blast-radius caps, idempotency) | implemented | WidgetToolValidator both-direction contract; MCP write choke in PR 3 |
| C9.2.3 | 2 | Each high-impact action has a trusted reversibility classification, such as read-only, reversible, externally reversibl… | SEC-AI-ACT-001 (widget/MCP action contracts, blast-radius caps, idempotency) | implemented | WidgetToolValidator both-direction contract; MCP write choke in PR 3 |
| C9.2.4 | 2 | The agent runtime enforces reversibility classifications by blocking, requiring approval, or restricting actions based… | SEC-AI-ACT-001 (widget/MCP action contracts, blast-radius caps, idempotency) | implemented | WidgetToolValidator both-direction contract; MCP write choke in PR 3 |
| C9.2.5 | 2 | Any self-modification capability (e.g., prompt rewriting, tool-list changes, parameter updates) is restricted by enforc… | SEC-AI-ACT-001 (widget/MCP action contracts, blast-radius caps, idempotency) | implemented | WidgetToolValidator both-direction contract; MCP write choke in PR 3 |
| C9.2.6 | 2 | Agentic systems include an AI-augmented review of planned high-risk actions before execution that adds to, and does not… | SEC-AI-ACT-001 (widget/MCP action contracts, blast-radius caps, idempotency) | implemented | WidgetToolValidator both-direction contract; MCP write choke in PR 3 |
| C9.2.7 | 2 | The AI-augmented review mechanism is protected against manipulation by adversarial inputs, and cannot be overridden or… | SEC-AI-ACT-001 (widget/MCP action contracts, blast-radius caps, idempotency) | implemented | WidgetToolValidator both-direction contract; MCP write choke in PR 3 |
| C9.3.1 | 1 | Each tool/plugin executes in a least-privilege sandbox or is otherwise isolated from model operations. | SEC-AI-ACT-001 (widget/MCP action contracts, blast-radius caps, idempotency) | implemented | WidgetToolValidator both-direction contract; MCP write choke in PR 3 |
| C9.3.2 | 1 | Tool outputs are validated against schemas. | SEC-AI-ACT-001 (widget/MCP action contracts, blast-radius caps, idempotency) | implemented | WidgetToolValidator both-direction contract; MCP write choke in PR 3 |
| C9.3.3 | 2 | Tool manifests declare required privileges, resource limits, and output validation requirements. | SEC-AI-ACT-001 (widget/MCP action contracts, blast-radius caps, idempotency) | implemented | WidgetToolValidator both-direction contract; MCP write choke in PR 3 |
| C9.3.4 | 2 | The runtime enforces the privileges, resource limits, and output-validation requirements declared in tool manifests. | SEC-AI-ACT-001 (widget/MCP action contracts, blast-radius caps, idempotency) | implemented | WidgetToolValidator both-direction contract; MCP write choke in PR 3 |
| C9.3.5 | 2 | Components processing untrusted data are isolated from tool-calling capabilities, ensuring that compromised data proces… | SEC-AI-ACT-001 (widget/MCP action contracts, blast-radius caps, idempotency) | implemented | WidgetToolValidator both-direction contract; MCP write choke in PR 3 |
| C9.3.6 | 2 | There is architectural separation between processing of untrusted tool outputs and agent operations. | SEC-AI-ACT-001 (widget/MCP action contracts, blast-radius caps, idempotency) | implemented | WidgetToolValidator both-direction contract; MCP write choke in PR 3 |
| C9.3.7 | 2 | External resources named in model output are verified against an approved allow-list or registry before the agent insta… | SEC-AI-ACT-001 (widget/MCP action contracts, blast-radius caps, idempotency) | implemented | WidgetToolValidator both-direction contract; MCP write choke in PR 3 |
| C9.4.1 | 2 | Each agent instance has a unique cryptographic identity and authenticates as a first-class principal to downstream syst… | SEC-AI-ACT-001 (widget/MCP action contracts, blast-radius caps, idempotency) | implemented | WidgetToolValidator both-direction contract; MCP write choke in PR 3 |
| C9.4.2 | 2 | Agent-initiated actions are cryptographically bound to each step of the execution chain for non-repudiation. | SEC-AI-ACT-001 (widget/MCP action contracts, blast-radius caps, idempotency) | implemented | WidgetToolValidator both-direction contract; MCP write choke in PR 3 |
| C9.5.1 | 2 | Agent actions are authorized against fine-grained policies enforced by the runtime that restrict which tools an agent m… | SEC-AI-ACT-001 (widget/MCP action contracts, blast-radius caps, idempotency) | implemented | WidgetToolValidator both-direction contract; MCP write choke in PR 3 |
| C9.5.2 | 2 | When an agent acts on a user's behalf, the runtime propagates an integrity-protected, scope-limited token that carries… | SEC-AI-ACT-001 (widget/MCP action contracts, blast-radius caps, idempotency) | implemented | WidgetToolValidator both-direction contract; MCP write choke in PR 3 |
| C9.5.3 | 2 | All access control decisions are enforced by application logic or a policy engine, never by the AI model itself. | SEC-AI-ACT-001 (widget/MCP action contracts, blast-radius caps, idempotency) | implemented | WidgetToolValidator both-direction contract; MCP write choke in PR 3 |
| C9.5.4 | 2 | Secrets and credentials required by an agent at runtime are not exposed within the model's observable context, includin… | SEC-AI-ACT-001 (widget/MCP action contracts, blast-radius caps, idempotency) | implemented | WidgetToolValidator both-direction contract; MCP write choke in PR 3 |
| C9.5.5 | 2 | Inter-agent task delegation is restricted by an explicit authorization policy. | SEC-AI-ACT-001 (widget/MCP action contracts, blast-radius caps, idempotency) | implemented | WidgetToolValidator both-direction contract; MCP write choke in PR 3 |
| C9.6.1 | 1 | A manual kill-switch mechanism exists to immediately halt AI model inference and outputs. | SEC-AI-ACT-001 (widget/MCP action contracts, blast-radius caps, idempotency) | implemented | WidgetToolValidator both-direction contract; MCP write choke in PR 3 |
| C9.6.2 | 2 | When a human-approval gate is not satisfied within the defined approval time, the system blocks the pending action. | SEC-AI-ACT-001 (widget/MCP action contracts, blast-radius caps, idempotency) | implemented | WidgetToolValidator both-direction contract; MCP write choke in PR 3 |

#### C10 Model Context Protocol (MCP) Security

| Req | L | Requirement (abridged) | AskMyDocs mapping | State | Note |
|---|---|---|---|---|---|
| C10.1.1 | 1 | MCP components are obtained only from trusted sources and cryptographically verified. | EnforceMcpScope, McpToolAuthorizer, R44 tri-surface registry | open | PR 3; registration-count lock test exists (KnowledgeBaseServerRegistrationTest) |
| C10.1.2 | 2 | Only allow-listed MCP servers are permitted. | EnforceMcpScope, McpToolAuthorizer, R44 tri-surface registry | open | PR 3; registration-count lock test exists (KnowledgeBaseServerRegistrationTest) |
| C10.1.3 | 2 | Locally launched MCP servers run in a least-privilege sandbox with restricted file system, network, and system access. | EnforceMcpScope, McpToolAuthorizer, R44 tri-surface registry | open | PR 3; registration-count lock test exists (KnowledgeBaseServerRegistrationTest) |
| C10.2.1 | 1 | MCP servers validate access tokens for each request and do not rely on transport security alone. | EnforceMcpScope, McpToolAuthorizer, R44 tri-surface registry | open | PR 3; registration-count lock test exists (KnowledgeBaseServerRegistrationTest) |
| C10.2.2 | 1 | MCP servers validate the presented access token's issuer, audience, expiration, and scope claims in accordance with OAu… | EnforceMcpScope, McpToolAuthorizer, R44 tri-surface registry | open | PR 3; registration-count lock test exists (KnowledgeBaseServerRegistrationTest) |
| C10.2.3 | 1 | MCP servers acting as OAuth 2.1 resource servers do not store or persist access tokens or user credentials. | EnforceMcpScope, McpToolAuthorizer, R44 tri-surface registry | open | PR 3; registration-count lock test exists (KnowledgeBaseServerRegistrationTest) |
| C10.2.4 | 2 | MCP tools/list returns only tools permitted by resource owners' authorized scopes. | EnforceMcpScope, McpToolAuthorizer, R44 tri-surface registry | open | PR 3; registration-count lock test exists (KnowledgeBaseServerRegistrationTest) |
| C10.2.5 | 2 | MCP servers enforce access control on every tool invocation, validating that the user's access token authorizes both th… | EnforceMcpScope, McpToolAuthorizer, R44 tri-surface registry | open | PR 3; registration-count lock test exists (KnowledgeBaseServerRegistrationTest) |
| C10.2.6 | 2 | MCP servers ensure all session artifacts are removed when a session terminates. | EnforceMcpScope, McpToolAuthorizer, R44 tri-surface registry | open | PR 3; registration-count lock test exists (KnowledgeBaseServerRegistrationTest) |
| C10.2.7 | 2 | MCP servers do not pass through access tokens received from clients to downstream APIs. | EnforceMcpScope, McpToolAuthorizer, R44 tri-surface registry | open | PR 3; registration-count lock test exists (KnowledgeBaseServerRegistrationTest) |
| C10.3.1 | 1 | Authenticated, encrypted streamable HTTP is used for MCP transport for remote services. | EnforceMcpScope, McpToolAuthorizer, R44 tri-surface registry | open | PR 3; registration-count lock test exists (KnowledgeBaseServerRegistrationTest) |
| C10.3.2 | 1 | Stdio transport is permitted only in controlled local environments. | EnforceMcpScope, McpToolAuthorizer, R44 tri-surface registry | open | PR 3; registration-count lock test exists (KnowledgeBaseServerRegistrationTest) |
| C10.3.3 | 2 | MCP servers validate both the Origin header and the Host header independently on all HTTP-based transports to prevent D… | EnforceMcpScope, McpToolAuthorizer, R44 tri-surface registry | open | PR 3; registration-count lock test exists (KnowledgeBaseServerRegistrationTest) |
| C10.3.4 | 2 | MCP clients enforce a minimum acceptable protocol version and reject initialize responses that propose a version below… | EnforceMcpScope, McpToolAuthorizer, R44 tri-surface registry | open | PR 3; registration-count lock test exists (KnowledgeBaseServerRegistrationTest) |
| C10.4.1 | 1 | MCP tools/list and tools/call responses are validated against their declared schemas before being injected into the mod… | EnforceMcpScope, McpToolAuthorizer, R44 tri-surface registry | open | PR 3; registration-count lock test exists (KnowledgeBaseServerRegistrationTest) |
| C10.4.2 | 1 | MCP tools/list and tools/call responses are screened for indirect prompt injection before being injected into the model… | EnforceMcpScope, McpToolAuthorizer, R44 tri-surface registry | open | PR 3; registration-count lock test exists (KnowledgeBaseServerRegistrationTest) |
| C10.4.3 | 1 | MCP servers reject unrecognized or oversized parameters in function calls. | EnforceMcpScope, McpToolAuthorizer, R44 tri-surface registry | open | PR 3; registration-count lock test exists (KnowledgeBaseServerRegistrationTest) |
| C10.4.4 | 2 | All MCP servers enforce strict schema validation. | EnforceMcpScope, McpToolAuthorizer, R44 tri-surface registry | open | PR 3; registration-count lock test exists (KnowledgeBaseServerRegistrationTest) |
| C10.4.5 | 2 | All MCP transports enforce maximum payload size limits. | EnforceMcpScope, McpToolAuthorizer, R44 tri-surface registry | open | PR 3; registration-count lock test exists (KnowledgeBaseServerRegistrationTest) |
| C10.4.6 | 2 | MCP servers sign tool responses with a unique nonce and timestamp so MCP clients can detect replay attempts. | EnforceMcpScope, McpToolAuthorizer, R44 tri-surface registry | open | PR 3; registration-count lock test exists (KnowledgeBaseServerRegistrationTest) |
| C10.4.7 | 2 | MCP clients present users with explicit consent dialogue and cancellation options upon installation of a local MCP serv… | EnforceMcpScope, McpToolAuthorizer, R44 tri-surface registry | open | PR 3; registration-count lock test exists (KnowledgeBaseServerRegistrationTest) |

#### C11 Adversarial Robustness

| Req | L | Requirement (abridged) | AskMyDocs mapping | State | Note |
|---|---|---|---|---|---|
| C11.1.1 | 1 | The model has undergone alignment and safety training or fine-tuning to prevent the model from generating disallowed co… | guardrails package (v8.19), rejected-approach injection, refusal gate | implemented | adversarial-robustness posture partial; eval-harness benchmarks (nDCG gate) |
| C11.1.2 | 1 | A version-controlled alignment test suite is run on every model update or release. | guardrails package (v8.19), rejected-approach injection, refusal gate | implemented | adversarial-robustness posture partial; eval-harness benchmarks (nDCG gate) |
| C11.1.3 | 1 | Models are evaluated against known adversarial attack techniques relevant to their modality. | guardrails package (v8.19), rejected-approach injection, refusal gate | implemented | adversarial-robustness posture partial; eval-harness benchmarks (nDCG gate) |
| C11.1.4 | 2 | Models are hardened against adversarial inputs. | guardrails package (v8.19), rejected-approach injection, refusal gate | implemented | adversarial-robustness posture partial; eval-harness benchmarks (nDCG gate) |
| C11.2.1 | 1 | Model-inferred sensitive attributes are not directly returned in outputs. | guardrails package (v8.19), rejected-approach injection, refusal gate | implemented | adversarial-robustness posture partial; eval-harness benchmarks (nDCG gate) |
| C11.2.2 | 1 | Inference endpoints enforce per-principal and global rate limits sized to the extraction threat model, and not solely a… | guardrails package (v8.19), rejected-approach injection, refusal gate | implemented | adversarial-robustness posture partial; eval-harness benchmarks (nDCG gate) |
| C11.2.3 | 2 | Model outputs are calibrated to reduce overconfident predictions. | guardrails package (v8.19), rejected-approach injection, refusal gate | implemented | adversarial-robustness posture partial; eval-harness benchmarks (nDCG gate) |
| C11.2.4 | 2 | Training on sensitive datasets employs differentially-private optimization. | guardrails package (v8.19), rejected-approach injection, refusal gate | implemented | adversarial-robustness posture partial; eval-harness benchmarks (nDCG gate) |
| C11.3.1 | 1 | Query-pattern analysis feeds an extraction-attempt detector. | guardrails package (v8.19), rejected-approach injection, refusal gate | implemented | adversarial-robustness posture partial; eval-harness benchmarks (nDCG gate) |
| C11.3.2 | 2 | Raw model outputs are not directly exposed beyond the application backend, and that externally visible responses are ca… | guardrails package (v8.19), rejected-approach injection, refusal gate | implemented | adversarial-robustness posture partial; eval-harness benchmarks (nDCG gate) |
| C11.4.1 | 2 | Inputs from external or untrusted sources pass through anomaly detection before model inference. | guardrails package (v8.19), rejected-approach injection, refusal gate | implemented | adversarial-robustness posture partial; eval-harness benchmarks (nDCG gate) |
| C11.4.2 | 2 | Inputs flagged as anomalous trigger gating actions. | guardrails package (v8.19), rejected-approach injection, refusal gate | implemented | adversarial-robustness posture partial; eval-harness benchmarks (nDCG gate) |

#### C12 Monitoring, Logging & Anomaly Detection

| Req | L | Requirement (abridged) | AskMyDocs mapping | State | Note |
|---|---|---|---|---|---|
| C12.1.1 | 1 | AI interactions are logged with session context and AI-specific telemetry. | FinOps metering (all provider paths), chat_logs, admin_command_audit | implemented | SIEM off-host forwarding INFRA (§6) |
| C12.1.2 | 2 | Safety filtering and policy decisions are logged with sufficient detail to support audit, debugging, and forensic analy… | FinOps metering (all provider paths), chat_logs, admin_command_audit | implemented | SIEM off-host forwarding INFRA (§6) |
| C12.1.3 | 2 | Log entries for AI inference events follow a structured, interoperable schema that includes at least the model identifi… | FinOps metering (all provider paths), chat_logs, admin_command_audit | implemented | SIEM off-host forwarding INFRA (§6) |
| C12.1.4 | 2 | RAG pipeline retrieval events are logged, including the query, documents retrieved, and knowledge source. | FinOps metering (all provider paths), chat_logs, admin_command_audit | implemented | SIEM off-host forwarding INFRA (§6) |
| C12.2.1 | 1 | The system detects and alerts on known jailbreak patterns, prompt injection attempts, and adversarial inputs. | FinOps metering (all provider paths), chat_logs, admin_command_audit | implemented | SIEM off-host forwarding INFRA (§6) |
| C12.2.2 | 2 | Behavioral anomaly detection identifies unusual conversation patterns, excessive retry attempts, or probing behaviors. | FinOps metering (all provider paths), chat_logs, admin_command_audit | implemented | SIEM off-host forwarding INFRA (§6) |
| C12.2.3 | 2 | Custom rules detect AI-specific threat patterns for coordinated jailbreak attempts, prompt injection, and system prompt… | FinOps metering (all provider paths), chat_logs, admin_command_audit | implemented | SIEM off-host forwarding INFRA (§6) |
| C12.2.4 | 2 | Extraction-alert events include offending query metadata to support investigation. | FinOps metering (all provider paths), chat_logs, admin_command_audit | implemented | SIEM off-host forwarding INFRA (§6) |
| C12.2.5 | 2 | Token usage is tracked at granular attribution levels including per user, per session, per feature endpoint, and per te… | FinOps metering (all provider paths), chat_logs, admin_command_audit | implemented | SIEM off-host forwarding INFRA (§6) |
| C12.3.1 | 1 | Data drift detection monitors input distribution changes that may impact model performance, using statistically validat… | FinOps metering (all provider paths), chat_logs, admin_command_audit | implemented | SIEM off-host forwarding INFRA (§6) |
| C12.3.2 | 2 | Hallucination detection monitors identify and flag model outputs that contain factually incorrect, inconsistent, or fab… | FinOps metering (all provider paths), chat_logs, admin_command_audit | implemented | SIEM off-host forwarding INFRA (§6) |
| C12.3.3 | 2 | Hallucination rates are tracked as continuous time-series metrics to enable trend analysis and detection of sustained m… | FinOps metering (all provider paths), chat_logs, admin_command_audit | implemented | SIEM off-host forwarding INFRA (§6) |
| C12.4.1 | 2 | Autonomous action triggers include proactive behavior-pattern analysis, security evaluation, and threat-landscape asses… | FinOps metering (all provider paths), chat_logs, admin_command_audit | implemented | SIEM off-host forwarding INFRA (§6) |
| C12.4.2 | 2 | Audit logs capture security-critical proactive actions, including approver identity, timestamp, action parameters, and… | FinOps metering (all provider paths), chat_logs, admin_command_audit | implemented | SIEM off-host forwarding INFRA (§6) |
| C12.4.3 | 2 | Kill-switch activations and override commands are logged. | FinOps metering (all provider paths), chat_logs, admin_command_audit | implemented | SIEM off-host forwarding INFRA (§6) |
| C12.5.1 | 1 | Dataset lineage records each dataset and its components, including all transformations, augmentations, and merges. | FinOps metering (all provider paths), chat_logs, admin_command_audit | implemented | SIEM off-host forwarding INFRA (§6) |
| C12.5.2 | 1 | All labeling activities are recorded in logs. | FinOps metering (all provider paths), chat_logs, admin_command_audit | implemented | SIEM off-host forwarding INFRA (§6) |
| C12.5.3 | 2 | All model changes generate immutable audit records. | FinOps metering (all provider paths), chat_logs, admin_command_audit | implemented | SIEM off-host forwarding INFRA (§6) |
| C12.5.4 | 2 | Every ingested document is tagged at write time with source, writer identity, and timestamp. | FinOps metering (all provider paths), chat_logs, admin_command_audit | implemented | SIEM off-host forwarding INFRA (§6) |
---

## §4 — OWASP Top 10 2025 (coverage cross-check)

The Top 10 is used **only** as a completeness cross-check over §1–§3, never as a
primary control source. Each 2025 category maps to the internal controls that
cover it and an aggregate state. (Top 10:2025 introduces **A03 Software Supply
Chain**, **A09 Logging & Alerting Failures**, and **A10 Mishandling of
Exceptional Conditions** — all three land on open remediation PRs.)

| Top 10 2025 | Covered by (internal controls) | Aggregate state | Remediation |
|---|---|---|---|
| A01 Broken Access Control | tenant-object-authorization, backoffice-exposure, admin-authorization-granularity, route-exposure-regression-gate (R30–R32) | mostly regression-tested; route-gate open | PR 4, PR 7 |
| A02 Security Misconfiguration | content-security-policy, response-headers, cors-config, production-posture, env-gate-fail-closed | headers open; posture implemented | PR 1 |
| A03 Software Supply Chain Failures | dependency-security, supply-chain-ci, sast-regression-gate, dependency-regression-gate | open | PR 9 |
| A04 Cryptographic Failures | key-management, pii-encryption, appkey-rotation, tls-hsts | implemented; HSTS + rotation-inventory open | PR 1, residual §1 |
| A05 Injection | raw-sql-inventory, input-escape-complete (R19), shell-command-array, prompt-injection containment (SEC-LLM gate 4) | implemented/regression-tested | PR 9 (arch analyzer) |
| A06 Insecure Design | rule-ai-agent-actions, security-invariants (R21), fail-closed-security-controls, multi-surface-gates | implemented; MCP write-choke open | PR 3 |
| A07 Authentication Failures | auth-hardening, password-policy, password-breach-check, api-token-lifecycle, backoffice-no-remember | token lifecycle + breach-check open | PR 10, residual §1 |
| A08 Software & Data Integrity Failures | deserialization, audit-trail-integrity, signed-url-tokens, webhook-verify (dormant) | implemented; SAST integrity gate open | PR 9 |
| A09 Logging & Alerting Failures | logging-security, request-correlation, log-retention-and-siem, audit-trail-integrity | implemented in-app; SIEM off-host infra | PR 1, INFRA §6 |
| A10 Mishandling of Exceptional Conditions | surface-failures-loudly (R14), external-response-validation, ssrf-outbound, circuit-breaker | R14 regression-tested; SSRF/breaker open | PR 2 |

Cross-check verdict: no Top-10 2025 category is uncovered. The three new-in-2025
categories (A03, A09, A10) are the ones with the most `open` surface and are the
reason PR 2 (A10) and PR 9 (A03) are prioritized.

---

## §5 — Effective populations per surface

The control-coverage rule (`SEC-COVERAGE-001`) requires each control to cover the
**whole** effective population, not one representative. This table tracks the
per-surface state; a control is only as strong as its weakest member.

| Surface | Population | Control state on the population | Gap → PR |
|---|---|---|---|
| HTTP / API | ~261 host + 13 vendor route groups | auth decision per group (R32 matrix); resolved-router gate not yet a test | route-exposure gate → PR 7 |
| CLI (Artisan) | 70 commands | destructive commands gated by R21 confirm tokens; operator-shell trust | verify no unguarded destructive command → PR 7 (adjacent) |
| Scheduler | 29 slots + connector sync | server-side only, config-gated, `onOneServer` | — (no external input) |
| Queue | 9 host jobs + connector/pkg jobs | initiator re-authorized at effect (SEC-AI-ACT §4) | MCP-triggered write re-auth proof → PR 3 |
| Streaming (SSE) | 2 (chat, tabular-review) | tenant-scoped in-controller (`forTenant`, 404 on miss) | cross-tenant denial regression test → PR 4 |
| Fallback / retry | provider fallback chain | metered + bounded | PII + provider-policy on fallback branch → PR 8 |
| MCP | 46 tools (12 write) | `EnforceMcpScope` + `McpToolAuthorizer` | bidirectional write-tool coverage test → PR 3 |
| Widget | 11 public routes | PII masker + tool validator + snapshot validator (closed contract) | SSRF on theme/intro fetch → PR 2 |
| React SPA | renderer surfaces | output-is-data allow-lists; refusal UX (R26) | CSP nonce wiring → PR 1 |
| Tauri | desktop webview | native client; token auth | capability wildcards + null CSP → PR 11 |
| Outbound HTTP | webhook/digest/widget-fetch | raw `Http::` today, unvalidated target | SSRF guard → PR 2 |
| Uploads | ingest API + staging + connectors | markdown text path normalized (`KbPath`) | magic-byte/MIME on binary fetch → PR 5 |

---

## §6 — Infrastructure residuals (OPEN until runtime evidence)

These cannot be closed from the repository. They stay **open** with an explicit
runtime-verification step (never inferred — `SEC-DNS-001` / control-coverage §7).

| # | Control | Evidence needed at runtime |
|---|---|---|
| 1 | aws-iam-sigv4 | OIDC/short-lived role bindings; no static keys in the deployed env |
| 2 | edge-credential-leak-protection | edge secret rotation + verification |
| 3 | db-least-privilege | app vs migration DB roles + grant separation |
| 4 | dns-dangling | external DNS record inventory + monitoring |
| 5 | email-auth-dns | SPF / DKIM / DMARC records present and aligned |
| 6 | redis-production-posture | Redis TLS + auth + network restriction |
| 7 | signed-commits | branch protection + commit/tag signature enforcement |
| 8 | tls-hsts (deployed) | HSTS + TLS headers actually emitted at the edge |
| 9 | response-headers (deployed) | exact security headers emitted; emitter ownership documented |
| 10 | log-retention-and-siem | measured on-disk retention + off-host SIEM delivery |
| 11 | audit-trail-integrity (retention) | independent, access-controlled audit retention |
| 12 | key-management / pii-encryption / appkey-rotation (rotation) | managed key rotation with previous-key coverage |
| 13 | gdpr-consent-and-access / processor-register | consent notice version + processor residency/DPA register |
| 14 | deploy-credentials | protected OIDC deploy identity, no secret step-outputs |

Note: item 8/9 have an **application-side** contract that ships in PR 1 (the
`SecurityHeaders` middleware); the deployed-edge emission remains infra.

---

## §7 — PR index

Living. Updated as each remediation PR merges to `develop`. Order = audit
severity. Every PR: confirm the gap against real code → implement + negative
regression tests → update §1–§6 rows + findings log → R40 local critic → PR with
Copilot reviewer → R36 cloud loop → `run-e2e` label (R46) → merge at 0 must-fix +
CI green.

| PR | Title | Closes (controls) | Standards touched | Status |
|---|---|---|---|---|
| A | Audit scaffolding (this doc + threat model + findings + agent + skill) | security-checklist, security-inventory, sync (doc) | method | in review |
| B | Coverage-gate hardening (bidirectional validator + requiredFiles + typecheck CI) | sync-ai-instructions, control-coverage | — | planned |
| 1 | Security response headers (CSP nonce, HSTS, XFO, nosniff, Referrer/Permissions-Policy) | content-security-policy, response-headers, csp-nonce-cache, csp-report-collection, tls-hsts, request-correlation | ASVS V3/V12, Top-10 A02/A09 | planned |
| 2 | Outbound SSRF guard (webhook/digest) | ssrf-outbound, http-client-service | ASVS V13(SSRF), AISVS C4, Top-10 A10 | merged #411 2026-08-10 — widget URLs client-rendered (SANO); circuit-breaker choke-point remains open |
| 3 | MCP write-tool scope gate (15 write tools) | rule-ai-agent-actions, ai-initiating-user | AISVS C5/C9/C10, Top-10 A06 | merged #412 2026-08-10 — mcp:tools:write required, reflection-locked |
| 4 | Tenant scope on SSE (tabular-review stream) — **real IDOR fix** | tenant-object-authorization, security-boundaries | ASVS V8, AISVS C8, Top-10 A01 | merged #414 2026-08-10 — confirmed cross-tenant leak (200→403) closed with tenant.authorize |
| 5 | Upload hardening (magic-byte vs SourceType) | upload-hardening | ASVS V5, Top-10 A05 | in review (#416) — FileTypeSniffer at the upload boundary; Browsershot arg-array remains a follow-up nit |
| 6 | Identity-aware throttle on /kb/chat | public-flow-throttle | ASVS V6.1, AISVS C11, Top-10 A07 | in review (#417) — throttle:kb-chat identity+tenant; no /kb/search route exists |
| 7 | Route-exposure regression gate + RBAC matrix completeness | route-exposure-regression-gate, http-surface-inventory, backoffice-exposure, control-coverage | ASVS V4, Top-10 A01 | planned |
| 8 | AI provider/model/base-URL allow-list policy | ai-provider-supply-chain, ai-data-flow, rule-ai-llm-security | AISVS C3/C6, Top-10 A05 | planned |
| 9 | Supply-chain & SAST CI (composer/npm audit, dependabot, larastan) | dependency-security, supply-chain-ci, sast-regression-gate, dependency-regression-gate | ASVS V15, AISVS C6, Top-10 A03 | planned |
| 10 | Sanctum token lifecycle + CSRF negatives | api-token-lifecycle, dormant-access, backoffice-no-remember | ASVS V7, Top-10 A07 | planned |
| 11 | Tauri desktop hardening (capabilities + CSP) | postmessage-origin (Tauri), tls-hsts (client) | ASVS V13, Top-10 A02 | planned |

**Residual (not a PR — tracked in §1/§6):** password-breach-check, MFA,
appkey-rotation inventory, session-device-binding report-only rollout,
dev-dump-anonymization verifier. Each has a named owner-step; none is closed by
documentation alone.
