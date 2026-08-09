# Rule: CI and software supply chain

## Identifiers

`SEC-DEPS-001`, `SEC-SUPPLY-001`, `SEC-CIGATE-001`, `SEC-CI-PERM-001`,
`SEC-SIGNCOMMIT-001`, `SEC-AWSCRED-001`, `SEC-DNS-001`, `SEC-DNSMAIL-001`,
`BUILD-VERIFY-001`.

## Mandatory controls

- Reproducible installs use committed lockfiles and `npm ci` / locked Composer.
  Dependency updates are reviewed for advisories, lifecycle, provenance and
  transitive impact; security scanning is a blocking CI gate with documented,
  expiring exceptions.
- Advisory baselines key by stable advisory ID, reject new/stale/malformed/expired
  entries and scanner failure, and flag abandoned packages. Accepted security debt
  is named, scoped, owned, visible and expires fail-closed; `continue-on-error` is
  not an acceptance mechanism.
- GitHub Actions use least-privilege `permissions`, pin third-party actions to a
  reviewed immutable commit SHA, avoid untrusted PR data in shell, and never expose
  secrets to forked/untrusted code. Artifact/cache provenance and retention are explicit.
- Review/automation prompts passed through shell heredocs are code-adjacent input:
  unquoted heredocs must not contain backticks, `$()` or other command substitutions.
  Prefer quoted heredocs or construct the few required variables separately, and gate
  the prompt template so prose can never execute as a shell command.
- Automation must treat reviewer output as untrusted bytes. Normalize known encoding
  artifacts before parsing, require an exact machine-readable verdict, validate every
  numeric field before shell arithmetic, and fail closed on missing/malformed output.
- Deployment/release requires the real security tests and cannot be bypassed by a
  green placeholder, skipped job or mutable tag. Protected branches and signed
  commits/tags are enforced by platform policy and periodically verified.
- Build/lint/type/test commands applicable to every changed surface run with fresh
  exit-zero evidence. SAST uses the real PR base/full scan, blocks new findings and
  fails on missing/malformed reports. Public-route exposure CI boots the real app,
  inspects resolved middleware and rejects undeclared/stale public mutating routes.
- Cloud credentials use workload identity/OIDC and short-lived least-privilege roles;
  no static AWS keys, instance-metadata credential scraping or credentials in logs.
- Production deploys use protected environments and explicit approval. Secrets are
  read only at point of use, never propagated through step outputs/artifacts; an
  environment declared OIDC-ready cannot silently fall back to static credentials.
- Third-party browser assets are inventoried. Immutable URLs are exactly versioned
  and use SHA-384 SRI plus `crossorigin`; floating/variant resources are pinned or
  self-hosted and remain constrained by CSP. SRI never authorizes a host.
- Public DNS, mail authentication and external-service records are inventoried.
  Dangling records, expired ownership and SPF/DKIM/DMARC drift are monitored by
  infrastructure controls; repository documentation must not pretend CI can prove
  external state it cannot observe.

## AskMyDocs adaptation

The existing PHPUnit, Vitest, architecture, local critic, cloud Copilot and deferred
Playwright gates remain authoritative. Security-rule validation augments them; it
does not create a synthetic all-green gate or bypass R36/R40/R46.
