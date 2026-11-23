# AskMyDocs PACT Client

AskMyDocs uses the published PACT Laravel and Core packages v0.3.1 and the Core 0.2.0 wire protocol. The master connects by domain through `/.well-known/pact` and public OAuth registration. The client and master must use the same PACT prefix, by default `/pact`.

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

Start the PACT protocol worker under the process supervisor.

```sh
php artisan pact:work --no-interaction
```

This worker processes the PACT outbox. AskMyDocs agent runs use the existing application workers on the default queue connection. On Herd, `./ops/launchd/install-local.sh --pact-only` installs the protocol worker.

To also route the PACT handler through the existing local Laravel queue, set `PACT_QUEUE_CONNECTION=pact_client` and `PACT_QUEUE=pact-client`. These values are optional per environment. Rebuild the config cache and restart the workers after changing them.

## Laravel Cloud

The existing `pact:work` process receives PACT tasks. New agent runs use the same queues as AskMyDocs chat on `QUEUE_CONNECTION`. When the session and research workers are available, `AGENT_SESSION_QUEUE` handles the conversation and `AGENT_QUEUE` handles research. With the serial chat path, `AGENT_QUEUE` handles the entire turn. If AskMyDocs chat uses Managed Queues, PACT uses them as well.

During Cloud setup, attach a private S3 disk with read, list and write access. AskMyDocs uses `pact-state` if configured, otherwise the private default S3 disk or the sole private S3 disk. `PACT_STATE_DISK` selects a different disk when needed. The object key defaults to `askmydocs/pact/<environment-host>/installation.v1.enc`. Keep `PACT_STATE_OBJECT` if the environment already uses a custom key. For an external bucket, the `pact-state` disk reads `PACT_STATE_S3_*` from `.env.example`.

Use `bash scripts/deploy/laravel-cloud-develop.sh` as the `develop` Deploy Command and `bash scripts/deploy/laravel-cloud.sh` as the production Deploy Command. The first deploy creates the client identity and saves a verified bundle encrypted with `APP_KEY` before running migrations. It uses the existing installation files if present. Each later deploy restores the same bundle and applies pending PACT and isolated Passport migrations. The bundle includes Passport keys stored as files. When supplied through environment variables, use the same Passport pair on every replica.

Keep the same `APP_KEY`, HTTPS origin, disk and object across deploys. Web and workers restore before Laravel registers PACT routes. Composer package discovery and cache builds can run before the first deploy, keeping key material out of the release image. An unavailable or conflicting bundle stops startup. If the PACT schema already exists and both the local installation and S3 bundle are missing, the deploy reports the missing identity so it can be recovered without breaking existing links. [Laravel Cloud deploy commands](https://laravel.com/cloud/docs/environments#deploy-commands)

## Connect and test

From the master's **Collega agente** form enter `askmydocsdev.test` for local Herd testing. Sign in on AskMyDocs, choose a project, approve the two directions, then return to the master to select a team. The master worker activates the connection. Changing account or active tenant before approval requires fresh consent.

For a connection-only test, set `PACT_CLIENT_DIAGNOSTIC=true` before connecting, refresh the config cache, and restart the PACT worker. The built-in contract returns a ping without invoking the agent. Remove the setting to expose `pact.agent.chat.v1`, refresh the cache and reconnect to refresh the master’s agent card.

The chat contract sends a question to the existing AskMyDocs agent. It can search the project's knowledge base and consult authorized HTTP GET/HEAD routes, Freshdesk reads and MCP connector tools classified as read-only and idempotent. The worker returns the answer and sources, can ask for a clarification or budget confirmation, and checks the current authorization before work and result delivery. Conversation access through the PACT reader remains denied by default.

To revoke a connection, run `php artisan pact:disconnect CONNECTION_ID --user=PACT_SUBJECT_ID`. Use the subject stored as `pact_connections.user_id`, which identifies the authorized AskMyDocs user and project in `pact_client_subjects`. The PACT worker sends the revocation to the master.
