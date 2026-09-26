# SDD ledger — plan: docs/plans/askmydocs-enterprise-roadmap.md

## 2026-09-27

- E00.01: local discovery reconciled against HEAD `96848ed95dc6f7169fc4ae7bf117158f0c322d4d`; Composer manifest valid; graph non-adoption guard passed. Runtime initially exposed missing installed routines packages.
- E00.02: installed the two packages already pinned in `composer.lock`; added explicit routines provider registration because stale package discovery prevented bootstrap. `route:list`, artisan command registration, Composer validation and platform checks passed. Clean-install CI/release matrix/SBOM remain open.
- E00.03: documented tenant-owned tables versus intentionally shared derived embedding cache; tenant and ACL tests passed locally. PostgreSQL and separate-worker A→B proof remain open.
- E01.01: immutable execution context, tenant-bound transport and durable run tests passed: 26 tests, 90 assertions. OIDC/real PostgreSQL/native runtime remain open.
- E01.02: replaced the feature-flagged 501 implementation with encrypted RFC-6238-compatible TOTP enrollment, one-time recovery codes, replay protection, disable flow and named rate limiter. Target regression passed: 10 tests, 34 assertions. Browser authenticator, PostgreSQL/process race, config-cache/deployment journey remain open.
- E01.03: added report-only IAM resource/action manifest, Spatie PDP adapter and fail-closed shadow evaluator. Target passed: 2 tests, 6 assertions; existing ACL/tenant suite passed 14 tests, 22 assertions. Persistent dashboard, promotion gates and IAM Server/directory federation remain open; task is not complete.

## Release / coordination

- AskMyDocs changes are on branch `roadmap/askmydocs-enterprise-e01-02`, PR #518, with CI gates running. Vocentra is owned by a separate agent and was not modified.
- No Rebel package source was changed; no Rebel package release is required for this work.
- Do not mark any item with an open limit as fully complete. No credentials or secrets were recorded in this ledger.
