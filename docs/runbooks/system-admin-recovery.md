# Runbook — system administrator bootstrap and recovery

Use this runbook only from a trusted host console. `system-admin` is global,
above every tenant, and is never granted from the web UI, invitations, MCP or
the generic `auth:grant` command.

## Normal grant

The account must already exist, be active and not soft-deleted:

```bash
php artisan system-admin:grant ops@example.com
```

The command asks for confirmation. For an audited non-interactive deployment:

```bash
php artisan system-admin:grant ops@example.com --yes
```

Verify by signing in as the known account: `/api/auth/me` must report both
roles, both permissions, and `features.system_admin=true`. Then open
`/app/system/tenants` and inspect the completed `system-admin:grant` row in
`admin_command_audit`.

## Revoke excess legacy grants after v8.30

The v8.30 migration preserves every legacy global `super-admin` by adding
`system-admin`. Review the resulting operator list, then revoke accounts that
should administer only their membership tenants:

```bash
php artisan system-admin:revoke former-global@example.com --yes
```

Revoke removes only `system-admin`; companion `super-admin` remains. The command
refuses to remove the final active system administrator.

## Last-administrator recovery

If the last account is inactive or deleted, restore/activate that identity
through a trusted database/operator recovery procedure first, then run the
normal grant command. Do not insert `model_has_roles` rows manually: that skips
the companion-role invariant, confirmation and `admin_command_audit`.

If application boot is possible but no active system administrator remains:

1. take a database backup;
2. restore or create a known, active local account through the deployment's
   approved identity recovery workflow;
3. run `php artisan db:seed --class=RbacSeeder --force`;
4. run `php artisan system-admin:grant <known-email> --yes`;
5. verify `/api/auth/me` reports `features.system_admin=true`;
6. open `/app/system/tenants` and verify the registry;
7. inspect `admin_command_audit` for the completed `system-admin:grant`.

## Failure handling

- `No account exists`: create/recover the identity first.
- `inactive` or `deleted`: activate/restore it; the command fails closed.
- `System roles are missing`: run `RbacSeeder`, then retry.
- `Cannot revoke the last system administrator`: grant and verify a second
  active operator before retrying.
- audit/database write error: no role mutation is committed. Repair the
  database and rerun; never bypass the service with a direct pivot insert.

## Lifecycle recovery

Suspended and archived tenants are absent from the normal team switcher.
Sign in as a system administrator, open `/app/system/tenants`, choose the
tenant, select `active`, review the exact transition preview and confirm it.
The token is single-use, expires after five minutes and is bound to the actor,
tenant and from/to states. Generate a new preview after any state drift.
