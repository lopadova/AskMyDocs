---
applyTo: "**/*.{php,ts,tsx,js,jsx,mjs,json,yml,yaml}"
description: "AskMyDocs application security baseline distilled from Lorenzo's experience"
---

# Security baseline

- Auth and authorization apply to every HTTP/CLI/queue/MCP/widget entrypoint;
  settings, headers, client IDs and prompts are not security boundaries.
- Tenant/ownership scope comes from trusted context and is applied in the query.
- Consume nonce/single-use/rate/budget/idempotency invariants atomically with DB
  uniqueness where applicable; test real concurrency.
- Validate every external boundary: deserialization type/schema/signature, upload
  decode+MIME+size, filesystem containment, SSRF redirects/DNS/IP, XML entity/network
  disabled, webhook verification before effects, bounded response status/type/schema.
- Commands use fixed executable and argument arrays. Dynamic SQL identifiers use
  closed allow-lists; values are bound.
- Secrets/PII never enter frontend, logs, prompts, URLs or exception responses.
  Use generic correlated errors, encrypted fields/managed keys and auditable retention.
- Credentialed CORS uses exact origins plus `Vary: Origin`; CSP/TLS/cookies/proxies
  fail closed. CORS is browser isolation, never authentication.
- Production blocks debug/fake/test modes and malformed security settings at deploy
  and runtime; scheduled drift reporting supplements, not replaces, the deploy gate.
- Bound uploads, bodies, pages, exports, queues, parsers, RAG and AI work.
- CI uses locked installs, least permissions, immutable action revisions, protected
  secrets and real blocking gates. External DNS/IAM/Redis/DB state is reported as
  infrastructure evidence, not guessed from repository config.
- A control is complete only when every SDK/direct/fallback/stream/job/legacy path is
  inventoried and policy matches implementation in both directions. Unknown denies.

Canonical rules: `.claude/rules/rule-security-auth-data-boundaries.md`,
`rule-security-input-data-protection.md`, `rule-security-runtime-browser.md`,
`rule-security-ci-supply-chain.md`, and `rule-security-control-coverage.md`.
