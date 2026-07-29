# ADR 0024 — Require membership on every operational tenant route

- Status: Accepted
- Date: 2026-07-29
- Release: v8.30
- Supersedes: the `tenant.cross-access` decision in ADR 0023

## Context

The first system-admin boundary gave platform operators
`tenant.cross-access`. That kept old tenant route allowlists working, but also
made an identity with three memberships capable of entering a fourth tenant.
It blurred two distinct authorities: global registry governance and
operational access to customer data.

## Decision

`system-admin` owns only `platform.admin`. Every operational tenant route
requires an explicit active-tenant membership, including requests without an
`X-Tenant-Id` header. The legacy literal `default` is a reserved,
non-operational fallback and is rejected even if stale membership data exists.

Global `/api/system-admin/*` routes remain outside `tenant.authorize` and are
gated by `platform.admin`. They are the only way a system administrator may
govern a tenant where the identity has no membership.

`/api/auth/me.teams` and tenant-local team management derive exclusively from
active, non-reserved tenant memberships. No migration materializes the old
implicit `default` fallback: doing so would manufacture a company association
and, with project isolation enabled, grant project access that did not
previously exist.

The global control plane also exposes a read-only, paginated Super Admin roster
and paginated tenant associations. Role mutation and impersonation remain out
of scope.

## Consequences

- A forged tenant header, URL, or header-less fallback cannot cross membership.
- A system administrator with no memberships can still log in and use the
  global control plane, while tenant APIs return `403 tenant_forbidden`.
- Tenant-local identity mutation is refused when the identity is shared with a
  different tenant.
- An identity with only stale `default` membership rows is treated as having no
  company and enters the resumable onboarding flow.
