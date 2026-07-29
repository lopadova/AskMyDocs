# ADR 0025 — Separate registration intent from operational tenant membership

- Status: Accepted
- Date: 2026-07-29
- Release: v8.30

## Context

Public registration requires an invitation code. Historically validation
depended on a tenant context and could fall back to the literal `default`.
That cannot distinguish two legitimate flows:

1. invite a person into an existing company with a predefined access grant;
2. authorize a person to register a new company, whose tenant does not exist yet.

Reserving numeric tenant IDs would not solve the boundary cleanly because the
application's tenant identity and every tenant-aware foreign key use the string
slug, not the numeric primary key of the optional `tenants` registry.

## Decision

The existing invitation tables remain the sole source of invite state. Public
registration codes are globally unique and are stored in the reserved
`system-registration` namespace, represented by an idempotently seeded
`tenants` row with `is_system=true`.

Each public code carries exactly one registration intent:

- `company_bootstrap`: no grant and no membership. Registration opens the
  account session, then requires resumable company onboarding.
- `tenant_join`: exactly one explicit tenant grant, targeting an active
  non-system tenant and one or more projects that already belong to it.
  Redemption provisions the membership and onboarding is not required.

`registration-invite:create` is the trusted issuer. Omitting `--tenant` creates
a bootstrap code; supplying `--tenant` and one or more `--project` values
creates a tenant-linked code.

`POST /api/auth/onboarding/company` is authenticated but deliberately outside
tenant authorization. It rechecks that the identity has no operational
membership, then atomically creates the tenant, initial project and owner
membership and grants the tenant `super-admin` role. `platform.admin` identities
are not eligible because their zero-tenant destination is the global control
plane.

The literal `default` is a reserved legacy slug. It is neither a public
registration namespace nor an implicit membership.

## Consequences

- No new invitation table or numeric-ID remapping is required.
- A registration interrupted after account creation resumes onboarding on the
  next login; no dashboard is reachable while the membership list is empty.
- Removing a normal user from every company intentionally makes the account
  eligible to create a new company on its next login.
- The technical system tenant never appears in tenant switchers, operational
  middleware, tenant administration lists or global tenant inventory.
- Public code lookup is safe only while `invite_codes.code` remains globally
  unique; the database unique index is therefore part of this contract.
