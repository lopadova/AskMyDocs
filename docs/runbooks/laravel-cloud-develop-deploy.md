# Runbook — Laravel Cloud develop deployment

This runbook applies only to the Laravel Cloud test environment connected to
the `develop` branch. Production must use a separate database/schema and must
never enable the develop deployment gate.

Laravel Cloud runs deploy commands immediately before the new deployment goes
live. Deploy commands have a 15-minute timeout and are the correct stage for
database migrations:
<https://cloud.laravel.com/docs/environments#deploy-commands>.

## Environment variables

Configure these variables only on the Cloud environment tracking `develop`:

```dotenv
DEVELOP_DEPLOY_ENVIRONMENT=develop
DEVELOP_DEPLOY_ENABLED=true
DEVELOP_SEED_PASSWORD=<a-secret-with-at-least-12-characters>
```

Keep `DEVELOP_DEPLOY_ENABLED=false` in production. The deploy script and
`DevelopSeeder` require the separate environment identity to be one of the
allowed non-production values, so copying the flag alone cannot authorize a
destructive reset. Laravel Cloud may reserve `APP_ENV=production` even for a
test environment; do not weaken production runtime behavior just to identify
the deployment target.

## Laravel Cloud commands

Append this line to the environment's **Build Commands**:

```bash
bash scripts/deploy/capture-commit-message.sh
```

It captures the exact message of the revision Cloud is building. Then set the
environment's **Deploy Commands** to:

```bash
bash scripts/deploy/laravel-cloud-develop.sh
```

Laravel Cloud makes custom variables available to Laravel's configuration,
but they may not be exported to the deploy-command shell. The script therefore
falls back to bootstrapping Laravel through
`scripts/deploy/resolve-laravel-environment.php` when the shell gate is absent.
The resolver returns only the environment name, the boolean gate, and the
password length; it never prints the seed password.

Do not add `php artisan optimize:clear`, `queue:restart`,
`horizon:terminate`, or `storage:link`: Laravel Cloud either manages those
concerns or explicitly advises against running them in deploy commands.

## Commit-message directives

These are markers in the final commit message deployed from `develop`; they
are not Git tags.

| Commit message | Database action |
| --- | --- |
| no marker | `php artisan migrate --force --no-interaction` |
| `[reset-database]` | `php artisan migrate:fresh --force --no-interaction` |
| `[init-seed]` | normal migration, then `DevelopSeeder` |
| both markers | `migrate:fresh`, then `DevelopSeeder` |

Examples:

```bash
# Normal deployment: preserves data.
git commit -m "feat: add the new develop feature"

# Reapply deterministic fixtures without dropping unrelated data.
git commit -m "test: refresh develop fixtures [init-seed]"

# Rebuild the test database and load fixtures.
git commit -m "chore: rebuild develop database [reset-database] [init-seed]"
```

When merging through GitHub, the markers must be in the squash/merge commit
message that actually lands on `develop`. A marker only in an earlier PR commit
is not considered.

`[reset-database]` is intentionally destructive. While the deploy command is
running, the old application version may still receive test traffic against
the database being rebuilt. Do not use the test environment during that
deployment.

## Seeded fixtures

`DevelopSeeder` is idempotent and creates:

- `develop-acme` and `develop-globex`, both active operational tenants;
- two projects per tenant (`*-kb` and `*-operations`);
- three distinct identities per tenant:
  - `owner@<company>.develop.test` — `super-admin`, owner of both projects;
  - `admin@<company>.develop.test` — `admin`, admin of both projects;
  - `viewer@<company>.develop.test` — `viewer`, member of the KB project only;
- `system@develop.test` — global `system-admin` + companion `super-admin`,
  deliberately without an operational tenant membership.

All seven identities use the password stored in `DEVELOP_SEED_PASSWORD`.
Running `[init-seed]` again restores the fixture identities, activates them,
and rotates their password to the current secret.

## Failure handling

- `Git metadata is unavailable`: ensure the capture script is the final Build
  Command and that it produced `.laravel-cloud-commit-message`.
- `DEVELOP_DEPLOY_ENABLED is not true`: set the variable only on the develop
  Cloud environment and redeploy.
- `Refusing ... APP_ENV=production`: configure
  `DEVELOP_DEPLOY_ENVIRONMENT=develop` on the Cloud test environment. Laravel
  Cloud may keep its reserved `APP_ENV=production` value.
- `DEVELOP_SEED_PASSWORD is empty` or too short: configure a secret of at least
  12 characters, then redeploy the same commit.
- a migration or seeder returns non-zero: the script stops immediately and
  Laravel Cloud must not promote that deployment.
