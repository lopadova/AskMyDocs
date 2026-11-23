# AskMyDocs PACT Client

AskMyDocs uses the published PACT Laravel and Core packages v0.2.2 and the Core 0.2.0 wire protocol. The master connects by domain through `/.well-known/pact` and public OAuth registration. The client and master must use the same PACT prefix, by default `/pact`.

## Install

Use the existing Laravel authentication and application key. Keep the database, `storage/app/pact`, and the Passport key pair in `storage/oauth-*.key` across releases. The host OAuth service uses its own `oauth_*` tables. The native PACT client uses Passport tables with the `pact_passport_` prefix.

```sh
composer install
php artisan config:clear
php artisan pact:install --role=client --no-interaction
php artisan migrate --path=database/migrations/2026_11_21_000001_create_pact_client_subjects.php --force
php artisan migrate --path=vendor/laravel/passport/database/migrations --force
php artisan config:cache
php artisan route:cache
php artisan pact:work --once
php artisan pact:doctor
```

Run the Passport migration path explicitly because the installer detects the host's existing OAuth table names. The command uses the configured Passport connection and records completed migrations.

Use HTTPS and an `APP_URL` that matches the site. On the first Herd install, pass one `--allow-local-host` per local peer on both sites. The SDK requires these entries for local addresses even over HTTPS. For an existing local installation, add only the required Herd hostnames to `local_hosts` in `storage/app/pact/installation.json`, then rebuild the caches and restart `pact:work`. Local peer entries belong in the local installation only.

Start both workers under the process supervisor in each environment.

```sh
php artisan pact:work --no-interaction
php artisan queue:work pact_client --queue=pact-client --sleep=1 --no-interaction --max-time=3600
```

The first worker processes the PACT outbox. The second handles AskMyDocs agent runs and uses the application database queue. On Herd, `./ops/launchd/install-local.sh --pact-only` installs both user services.

## Laravel Cloud

Attach a private S3 disk for PACT using `PACT_STATE_DISK` (default `pact-state`). Set `PACT_STATE_OBJECT` to a distinct object for each environment, such as `askmydocs/develop/installation.v1.enc` or `askmydocs/production/installation.v1.enc`. The default disk reads the `PACT_STATE_S3_*` variables in `.env.example`.

Before enabling restore, run `php artisan pact-state:backup` where the existing installation files are available. For a new environment, first run the install and migration commands above with its production HTTPS `APP_URL` and `APP_KEY`, then run the same backup command in that environment. The command writes and verifies a create-only encrypted bundle with the installation metadata and both PACT keys. It includes the Passport keys when they are files. For Passport keys supplied via environment variables, set the same pair on every replica.

Set `PACT_STATE_RESTORE=true` on the Cloud environment and redeploy with the same `APP_KEY`, origin, disk and object. Web, CLI and workers restore the files before the PACT package registers its routes. A missing, unreadable or conflicting bundle interrupts startup. A bundle from a different environment must stay with its original origin and identity.

The `develop` deployment script runs `bash scripts/deploy/pact-client.sh` after the application migrations, including after a `develop` database reset. In production, append that same line after `php artisan migrate --force --no-interaction`. It reapplies PACT and isolated Passport migrations using temporary cache paths.

Keep the PACT files out of the release image. In the Cloud Build Commands, run Composer as `PACT_STATE_RESTORE=false composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader` so package discovery can run before the replica is provisioned. Omit `optimize`, `config:cache` and `route:cache` from this build, since the client identity and routes load during runtime restore. Leave `PACT_STATE_RESTORE=true` in the environment settings for web, CLI and workers. After rollout, verify discovery, a fresh heartbeat and an existing test association. [Laravel Cloud deploy commands](https://laravel.com/cloud/docs/environments#deploy-commands)

## Connect and test

From the master's **Collega agente** form enter `askmydocsdev.test` for local Herd testing. Sign in on AskMyDocs, choose a project, approve the two directions, then return to the master to select a team. The master worker activates the connection. Changing account or active tenant before approval requires fresh consent.

For a connection-only test, set `PACT_CLIENT_DIAGNOSTIC=true` before connecting, refresh the config cache, and restart the PACT worker. The built-in contract returns a ping without invoking the agent. Remove the setting to expose `pact.agent.chat.v1`, refresh the cache and reconnect to refresh the master’s agent card.

The chat contract sends a question to the existing AskMyDocs agent. It can search the project's knowledge base and consult authorized HTTP GET/HEAD routes, Freshdesk reads and MCP connector tools classified as read-only and idempotent. The worker returns the answer and sources, can ask for a clarification or budget confirmation, and checks the current authorization before work and result delivery. Conversation access through the PACT reader remains denied by default.

To revoke a connection, run `php artisan pact:disconnect CONNECTION_ID --user=PACT_SUBJECT_ID`. Use the subject stored as `pact_connections.user_id`, which identifies the authorized AskMyDocs user and project in `pact_client_subjects`. The PACT worker sends the revocation to the master.
