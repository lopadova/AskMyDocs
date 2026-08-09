# Lorenzo security experience applied to AskMyDocs

Date: 2026-08-08

## Decision

This catalogue distils generic, reusable security experience contributed by
Lorenzo and adapts it to AskMyDocs in project-native terms. The controls are
grounded in the Laravel AI/RAG, MCP, public widget, React and Tauri surfaces that
exist in this repository.

Detailed rules:

- `rule-security-ai-llm.md` — `SEC-LLM-001`, including AI hardening lessons;
- `rule-security-ai-actions.md` — `SEC-AI-ACT-001`;
- `rule-security-auth-data-boundaries.md` — auth, token, tenant and signed-link controls;
- `rule-security-input-data-protection.md` — unsafe input, outbound and protected data;
- `rule-security-runtime-browser.md` — production, CORS/CSP/TLS and resource posture;
- `rule-security-ci-supply-chain.md` — dependency, CI and cloud-credential posture;
- `rule-security-control-coverage.md` — fail-closed population/atomicity/inventory;
- `rule-security-instruction-sync.md` — canonical/mirror/CI drift contract.

## Control disposition

`Adopted` means the invariant is enforceable in this repository. `Adapted` means
the same risk exists through an AskMyDocs-specific surface. `Infrastructure`
means repository policy is present but closure also needs runtime/platform
evidence. `N/A current topology` must be revisited if the topology changes.

| Security control | Disposition | AskMyDocs target |
|---|---|---|
| rule-ai-agent-actions | Adopted | AI actions rule + widget/MCP registries |
| rule-ai-llm-security | Adopted | AI/LLM rule + secure-ai-surface skill |
| ajax-route-hardening | Adapted | auth/data-boundary rule for all entrypoints |
| api-login-gates | Adopted | API/admin/MCP strong-auth consistency |
| api-token-lifecycle | Adopted | purpose, expiry, revocation, re-auth |
| audit-trail-integrity | Adopted | attributable independent security audit |
| auth-hardening | Adopted | auth/data-boundary rule |
| aws-iam-sigv4 | Infrastructure | short-lived OIDC/least privilege; verify deployment |
| backoffice-exposure | Adapted | AskMyDocs admin/API RBAC and R32 matrix |
| blocking-ci-gate | Adopted | real blocking PHPUnit/Vitest/Playwright/security gates |
| ci-workflow-permissions | Adopted | least GitHub Actions permissions |
| client-ip-identity | Adopted | trusted-proxy/IP identity rule |
| edge-credential-leak-protection | Infrastructure | rotate and verify at the deployment edge |
| content-security-policy | Adopted | SPA/widget CSP and safe rendering |
| control-coverage | Adopted | effective-population rule |
| cors-config | Adopted | exact credentialed origins at edge + Laravel |
| db-least-privilege | Infrastructure | app/migration identities and grants require DB evidence |
| dependency-security | Adopted | lock/advisory/provenance gate |
| deserialization | Adopted | authenticated schema/type deserialization |
| dns-dangling | Infrastructure | external DNS inventory/monitoring |
| dormant-access | Adopted | offboarding membership/session/token revocation |
| effective-security-population | Adopted | behavior-based inventory, all paths |
| email-auth-dns | Infrastructure | SPF/DKIM/DMARC external evidence |
| env-gate-fail-closed | Adopted | deploy block + scheduled runtime drift report |
| export-formula-injection | Adopted | spreadsheet formula neutralization |
| external-response-validation | Adopted | bounded status/type/schema validation |
| fail-closed-security-controls | Adopted | missing/unknown policy denies |
| frontend-secrets | Adopted | no secrets in Vite/React/widget/Tauri bundles |
| gdpr-data-inventory | Adopted | minimization, retention, erasure and provider egress |
| http-client-service | Adapted | hardened centralized client or inventoried SDK boundary |
| tenant-object-authorization | Adapted | R30-R32 tenant/ownership scope |
| key-management | Adopted | managed rotation and separated duties |
| logging-security | Adopted | PII-safe correlated diagnostics |
| multi-surface-security-gates | Adapted | HTTP/CLI/queue/MCP/widget/Tauri parity |
| password-breach-check | Adopted | consistent compromised-password policy |
| password-policy | Adopted | consistent password flows |
| path-containment | Adopted | normalized/resolved root containment |
| pii-encryption | Adopted | classified sensitive-field encryption |
| public-flow-throttle | Adopted | identity/tenant-aware public and AI throttles |
| race-conditions | Adopted | R21 atomic check+record and concurrency tests |
| raw-sql-inventory | Adopted | bound values and allowed identifiers |
| redis-production-posture | Infrastructure | TLS/auth/network/identity runtime evidence |
| resource-limits | Adopted | body/upload/page/RAG/AI/parser/queue caps |
| secret-settings-naming | Adopted | secrets are not generic displayable settings |
| security-boundaries | Adopted | settings/prompts/headers are not credentials |
| security-checklist | Adopted | explicit evidence states and mapping |
| security-inventory | Adopted | entrypoint/sink/effective-population inventory |
| security-setting-shape | Adopted | canonical strict setting shapes/defaults |
| shell-command-array | Adopted | fixed executable + argument arrays |
| signed-commits | Infrastructure | branch/platform verification plus CI policy |
| signed-url-tokens | Adopted | keyed, expiring, purpose-bound, atomic use |
| ssrf-outbound | Adopted | scheme/host/IP/DNS/redirect validation |
| multi-repository-security-coverage | N/A current topology | revisit if independently deployed components are added |
| supply-chain-ci | Adopted | immutable actions, lockfiles, secret isolation |
| sync-ai-instructions | Adopted | Claude canonical + Copilot mirrors + validator |
| tls-hsts | Adopted + Infrastructure | app/proxy contract; deployed headers need evidence |
| upload-hardening | Adopted | decoded MIME/size/storage/archive/image rules |
| webhook-verify-before-effects | Adopted | signature/replay/body before parsing/effects |
| xml-parsing | Adopted | no network/entities plus size/depth limits |
| rule-build-verification | Adopted | fresh applicable build/test evidence |
| accepted-security-debt | Adopted | named, owned, expiring fail-closed exception |
| admin-authorization-granularity | Adopted | specific admin capabilities + limiter inventory |
| ai-data-flow | Adopted | every provider egress, PII and spend path |
| ai-initiating-user | Adopted | immutable initiator + current re-authorization |
| ai-provider-supply-chain | Adopted | exact code-owned endpoint/model policy |
| appkey-rotation | Adopted | encryption/HMAC dependency and rotation inventory |
| backoffice-no-remember | Adopted | staff sessions reject persistent recaller credentials |
| circuit-breaker | Adopted | per-service mandatory outbound choke point |
| csp-nonce-cache | Adopted | fresh nonce across cache hit/miss/refresh |
| csp-report-collection | Adopted | safe throttled report-only collector |
| dependency-regression-gate | Adopted | stable advisory IDs + expiring exceptions |
| deploy-credentials | Adopted | protected OIDC deploy identity, no secret outputs |
| dev-dump-anonymization | Adopted | verifiable non-production anonymization marker |
| exposed-public-files | Adopted | positive public asset inventory |
| frontend-tenant-architecture | Adapted | R30-R32; frontend tenant state never authorizes |
| gdpr-consent-and-access | Adopted | notice version + aligned access/erasure map |
| http-surface-inventory | Adopted | resolved-route public perimeter inventory |
| log-retention-and-siem | Adopted + Infrastructure | measured retention + off-host delivery evidence |
| postmessage-origin | Adopted | exact widget/Tauri/browser message origins |
| processor-register | Adopted | provider/processor purpose, data, residency and DPA |
| production-posture | Adopted | raw environment and runtime measurement |
| request-correlation | Adopted | safe correlation ID across response/logs |
| response-headers | Adopted + Infrastructure | exact real headers and emitter ownership |
| route-exposure-regression-gate | Adopted | real resolved-router regression gate requirement |
| sast-regression-gate | Adopted | baseline/full SAST fail-closed contract |
| session-device-binding | Adopted | measured UA signal, never credential/IP binding |
| sri-pinned-cdn | Adopted | exact immutable CDN assets + SHA-384 SRI |
| third-party-resources | Adopted | browser resource inventory, pin/self-host/CSP |

## AI hardening lessons

The catalogue explicitly requires:

1. provider/model/base-URL policy in code and at every client/request path;
2. PII gate on SDK, direct HTTP, streaming, fallback, widget and queued paths;
3. atomic per-identity/global budget reservation before spend;
4. immutable initiating-user context plus re-authorization at effect time;
5. mandatory tool audit for allowed, denied, failed and replayed calls;
6. HMAC/fingerprint-bound, DB-unique idempotency with concurrent reservation;
7. untrusted documents/snapshots/tool results kept out of system messages;
8. closed backend/frontend action contracts with unknown fields removed;
9. safe renderer props and no untrusted HTML/dynamic execution;
10. generic correlated diagnostics without provider/DB body or PII leakage;
11. real image decode, MIME and size checks rather than data-URI syntax checks;
12. production runtime rejection of debug/fake/test behavior;
13. immutable CI action references, locked installs and real gates.

## AskMyDocs adaptations

- No `.gemini/` mirror is added because AskMyDocs has no Gemini instruction consumer.
- Edge and external controls remain visible as infrastructure evidence, while Laravel
  keeps a defence-in-depth policy where applicable.
- Existing local and cloud review workflows remain delivery gates; the validator
  checks the instruction contract deterministically.
- Verification exposed an existing local-review wrapper defect: shell command
  substitutions inside an unquoted prompt heredoc could execute prompt text. The
  wrapper now uses inert prose and the validator rejects backticks and `$()` there.
- The same verification exposed response-encoding drift. The parser now strips NUL
  bytes, forces text matching, requires the exact `SUMMARY` contract and validates
  the numeric count before arithmetic.

## Verification

Run `npm run security:rules`. It verifies every generic control above has a
disposition, required rule IDs and AI hardening invariants exist, mirrors remain
bounded and CI executes the contract checker.
