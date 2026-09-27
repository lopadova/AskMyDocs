# SDD ledger — plan: docs/plans/askmydocs-enterprise-roadmap.md

## 2026-09-27

- E00.01: local discovery reconciled against HEAD `96848ed95dc6f7169fc4ae7bf117158f0c322d4d`; Composer manifest valid; graph non-adoption guard passed. Runtime initially exposed missing installed routines packages.
- E00.02: installed the two packages already pinned in `composer.lock`; added explicit routines provider registration because stale package discovery prevented bootstrap. `route:list`, artisan command registration, Composer validation and platform checks passed. Clean-install CI/release matrix/SBOM remain open.
- E00.03: documented tenant-owned tables versus intentionally shared derived embedding cache; tenant and ACL tests passed locally. PostgreSQL and separate-worker A→B proof remain open.
- E01.01: immutable execution context, tenant-bound transport and durable run tests passed: 26 tests, 90 assertions. OIDC/real PostgreSQL/native runtime remain open.
- E01.02: replaced the feature-flagged 501 implementation with encrypted RFC-6238-compatible TOTP enrollment, one-time recovery codes, replay protection, disable flow and named rate limiter. Target regression passed: 10 tests, 34 assertions. Browser authenticator, PostgreSQL/process race, config-cache/deployment journey remain open.
- E01.03: added report-only IAM resource/action manifest, Spatie PDP adapter and fail-closed shadow evaluator. Target passed: 2 tests, 6 assertions; existing ACL/tenant suite passed 14 tests, 22 assertions. Persistent dashboard, promotion gates and IAM Server/directory federation remain open; task is not complete.
- W00.01: added the gap matrix and synthetic corpus specification (120 normal, 40 adversarial and 20 tool/action cases). The actual generated corpus and holdout evidence remain open.
- E02.01: added canonical ActionIntent construction/digesting in PHP and TypeScript plus the WidgetSession classification gate. PR #520 merged after PHPUnit, Vitest and dependency-audit checks passed; executor wiring across every MCP/Flow consumer remains open.
- E02.02: added the Flow-backed ActionApprovalService, one-shot receipt model and pre-effect ACL/digest revalidation. Local target passed: 3 tests, 9 assertions. Browser approval journey, production PostgreSQL worker-race evidence and all executor wiring remain open.

## Release / coordination

- AskMyDocs release `v8.41.0` is published from merged PR #520 at `f4ab7137542f2c7af568c2fb19abe86d99f322f2`; its required PHPUnit, Vitest and audit checks passed. The E02.02 branch is the next unreleased work. Vocentra is owned by a separate agent and was not modified.
- No Rebel package source was changed; no Rebel package release is required for this work.
- Do not mark any item with an open limit as fully complete. No credentials or secrets were recorded in this ledger.
