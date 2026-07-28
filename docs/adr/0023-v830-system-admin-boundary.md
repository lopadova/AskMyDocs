# ADR 0023 — Separate system administration from tenant super-administration

- Status: Accepted
- Date: 2026-07-28
- Release: v8.30

## Context

AskMyDocs historically used `super-admin` both for the maximum application
role and for global platform operation. Because that role received every
permission, it also inherited `tenant.cross-access`; a user responsible for
three companies could therefore enumerate and enter unrelated tenants.

The two responsibilities have different trust boundaries:

- tenant administration decides what a user may do inside tenants where the
  identity has membership;
- system administration governs the tenant registry itself and must work even
  when no tenant is selected, or when the target is suspended or archived.

Spatie teams are disabled, so application roles remain global to an identity.
Membership determines where a tenant user may act; the effective role
determines what that identity may do in each of those tenants.

## Decision

Introduce a protected `system-admin` role with only:

- `platform.admin`, which gates the global control plane;
- `tenant.cross-access`, which bypasses membership for emergency/platform
  operation.

`super-admin` remains the strongest tenant role but receives neither global
permission. Every `system-admin` also receives companion `super-admin`, allowing
existing `role:admin|super-admin` tenant routes to keep their established
allowlists.

The control plane moves to `/api/system-admin/tenants` and
`/app/system/tenants`. It is independent of `X-Tenant-Id`; every service query
scopes explicitly to the target tenant. `/api/auth/me` exposes the additive
`features.system_admin` capability for navigation.

The protected role can be changed only by:

```bash
php artisan system-admin:grant user@example.com --yes
php artisan system-admin:revoke user@example.com --yes
```

The commands reject inactive/deleted accounts, serialize against the role row,
refuse to revoke the last active system administrator, and atomically commit
the privilege change with its successful audit transition. Tenant Users CRUD,
role CRUD, `auth:grant`, demo-user helpers, invitations and tenant provisioning
cannot assign `system-admin`.

Tenant provisioning may create `super-admin`, `admin`, `editor`, or `viewer`.
For an existing identity it never changes roles: the current global role must
already satisfy the requested level. Lifecycle mutations require a
database-backed, single-use token bound to actor, tenant, source state and
destination state.

## Migration

The deployment migration creates the role and permissions, copies every legacy
`super-admin` assignment to `system-admin`, writes a bounded audit trail, and
then removes the global permissions from `super-admin`. The copy preserves
current access during rollout; it is intentionally conservative. Operators
must review the migrated list and revoke `system-admin` from identities that
should become tenant-only super-admins.

The migration processes legacy identities with `chunkById(100)` and is
idempotent. Future `super-admin` grants are not copied.

## R44 surface decision

The tenant-control capability has shared PHP services and an authenticated HTTP
surface. Global role bootstrap/recovery is deliberately CLI-only. There is no
MCP write tool for granting platform privilege or creating, suspending,
archiving or reactivating tenants.

This is an explicit R44 exception: autonomous global governance would cross the
agent trust boundary and make a compromised MCP token capable of disabling the
entire installation. Agents may operate tenant capabilities after human
selection; platform authority remains an interactive host-console operation.

## Consequences

Positive:

- tenant super-admins cannot enumerate or cross into unrelated tenants;
- global administration has a named, auditable capability;
- suspended and archived tenants remain recoverable without relying on their
  own request context;
- existing deployments do not lose their operators during migration;
- future global permissions cannot leak through `Permission::all()`.

Costs:

- a system administrator holds two companion roles;
- deployments must review legacy promotions after upgrade;
- role changes are global to an identity because tenant-specific Spatie teams
  remain intentionally disabled;
- emergency recovery requires console/database access and follows the
  documented runbook.

## Rejected alternatives

- Keep `super-admin` global and add a lower multi-tenant role: rejected because
  it preserves the ambiguous, dangerous name at the highest boundary.
- Add `system-admin` to every existing tenant route allowlist: rejected because
  hundreds of distributed allowlists would drift; the companion role preserves
  one tested compatibility invariant.
- Expose platform mutations through MCP: rejected because global tenant
  lifecycle and privilege grants are not agent-safe capabilities.
- Silently promote existing identities during tenant provisioning: rejected
  because Spatie roles apply across all of that identity's tenant memberships.
