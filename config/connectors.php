<?php

/*
|--------------------------------------------------------------------------
| v4.5/W1 — External-source connector framework
|--------------------------------------------------------------------------
|
| Configuration for the pluggable connector framework (Google Drive,
| Notion, Evernote, Fabric, OneDrive, Confluence, Jira, ...). Each
| connector implements `App\Connectors\ConnectorInterface` and is
| registered either as a built-in below OR by an installed composer
| package via its `composer.json::extra.askmydocs.connectors` array.
|
| See docs/connectors/README.md for the developer guide.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Built-in connectors
    |--------------------------------------------------------------------------
    |
    | v4.6/Wn — All 7 reference connectors (Google Drive, Notion,
    | Evernote, Fabric, OneDrive, Confluence, Jira) now ship as
    | standalone composer packages:
    |
    |   - padosoft/askmydocs-connector-google-drive
    |   - padosoft/askmydocs-connector-notion
    |   - padosoft/askmydocs-connector-evernote
    |   - padosoft/askmydocs-connector-fabric
    |   - padosoft/askmydocs-connector-onedrive
    |   - padosoft/askmydocs-connector-confluence
    |   - padosoft/askmydocs-connector-jira
    |
    | Each package declares its connector FQCNs under
    | `composer.json::extra.askmydocs.connectors` and is auto-discovered
    | at boot by the `Padosoft\AskMyDocsConnectorBase\ConnectorRegistry`
    | (mirror of Laravel's `extra.laravel.providers` convention).
    |
    | This `built_in` array stays as an extension point for host
    | applications (and community connectors) that want to register a
    | connector without shipping a separate composer package — e.g. a
    | proprietary in-house connector that should never appear on
    | Packagist. Add the FQCN here and the registry resolves + boot-
    | validates it just like a composer-discovered connector.
    |
    */
    'built_in' => [
        // Empty by design — every shipped connector is a standalone
        // composer package (v4.6 extraction). Host operators may
        // append in-house connector FQCNs here.
    ],

    /*
    |--------------------------------------------------------------------------
    | Default sync cadence
    |--------------------------------------------------------------------------
    |
    | The `App\Connectors\Scheduling\SyncScheduler` dispatches a
    | `ConnectorSyncJob` per active installation when this many minutes
    | have elapsed since the installation's `last_sync_at`. Per-connector
    | overrides live below.
    |
    */
    'default_sync_cadence_minutes' => (int) env('CONNECTOR_DEFAULT_SYNC_CADENCE_MINUTES', 15),

    /*
    |--------------------------------------------------------------------------
    | Per-connector cadence overrides
    |--------------------------------------------------------------------------
    |
    | Keyed by `ConnectorInterface::key()`. Use this to give chatty
    | connectors a tighter sync window without affecting the defaults.
    | e.g.
    |
    |   'per_connector_cadence' => [
    |       'google-drive' => 10,  // every 10 min
    |       'notion'       => 30,  // every 30 min
    |   ],
    |
    */
    'per_connector_cadence' => [
        // No overrides shipped by default — the framework picks
        // `default_sync_cadence_minutes` for every connector unless
        // operators set an override here.
    ],

    /*
    |--------------------------------------------------------------------------
    | OAuth state token TTL
    |--------------------------------------------------------------------------
    |
    | `BaseConnector::issueOAuthState()` stores its CSRF state token in
    | the application cache with this expiry (seconds). 10 minutes is
    | long enough for a typical OAuth round-trip including
    | provider-side login + 2FA, short enough to make replay attacks
    | impractical.
    |
    */
    'oauth_state_ttl_seconds' => (int) env('CONNECTOR_OAUTH_STATE_TTL_SECONDS', 600),

    /*
    |--------------------------------------------------------------------------
    | Queue name for ConnectorSyncJob
    |--------------------------------------------------------------------------
    |
    | Override to isolate connector traffic onto a dedicated queue
    | (e.g. `connectors` worker pool) so it doesn't compete with
    | the chat / ingest hot path.
    |
    | v8.21 (Ciclo 2 — queue baseline): the default is now `connectors`,
    | NOT `default`. Connector sync is bursty + slow (network IO to remote
    | providers) and previously shared `default` with autowiki + change-
    | analysis. Run a dedicated worker pool: `queue:work --queue=connectors`
    | (sync), `--queue=kb-ingest` (per-doc ingestion, see kb.ingest.queue), and
    | one for your connection's DEFAULT queue (everything else). Note the default
    | queue NAME is the connection's configured queue (`REDIS_QUEUE` / `DB_QUEUE`
    | / ...), not necessarily the literal `default`. See the Ingestion & Sync
    | docs for the Horizon autoscaling note.
    |
    */
    'sync_job_queue' => env('CONNECTOR_SYNC_JOB_QUEUE', 'connectors'),

    /*
    |--------------------------------------------------------------------------
    | Fake IMAP ping (E2E / local seam — v8.17)
    |--------------------------------------------------------------------------
    |
    | The IMAP server is reached by the BACKEND over TCP, so Playwright cannot
    | stub it. When true, AppServiceProvider swaps the IMAP client factory for a
    | deterministic, input-driven fake (host containing `invalid`/`fail` → login
    | failure; otherwise success). DEFAULT OFF — production always talks to the
    | real server. Mirrors the AI_PROVIDER=fake test seam.
    |
    */
    'fake_imap_ping' => (bool) env('CONNECTOR_IMAP_FAKE_PING', false),

    /*
    |--------------------------------------------------------------------------
    | IMAP connection serialization (per-mailbox mutex)
    |--------------------------------------------------------------------------
    |
    | Many IMAP servers (Gmail caps ~15/account) reject "Too many simultaneous
    | connections" when several connections hit the SAME account at once — which
    | happens with multiple installations (labels) on one mailbox, or a sync
    | overlapping an operator action (test-fetch / folder picker). When
    | `serialize_connections` is true (default) the host wraps the IMAP client
    | factory so EVERY connection acquires a per-mailbox lock (keyed by
    | host+port+username, cross-tenant) before connecting and releases it on close
    | — at most one live connection per mailbox at any time, across all surfaces.
    |
    |   - wait_seconds          : how long a NEW connection blocks for the mailbox
    |                             to free before giving up (→ 503 "busy" on the HTTP
    |                             surfaces). Short: sync jobs re-queue (below), so the
    |                             only residual wait is sync↔operator-action (seconds).
    |   - ttl_seconds           : lock auto-expiry, > the sync job timeout (600s) so a
    |                             dead process can never deadlock a mailbox.
    |   - requeue_after_seconds : when a sync JOB finds the mailbox busy, the
    |                             WithoutOverlapping middleware releases it back to the
    |                             queue after this delay (no worker block, no ERRORED).
    |
    | The cross-process guarantee needs an atomic lock store (Redis in production).
    | DEFAULT-OFF in the test env (single process), where the decorator is exercised
    | in isolation.
    |
    */
    'imap' => [
        'serialize_connections' => (bool) env('CONNECTOR_IMAP_SERIALIZE_CONNECTIONS', true),
        'mailbox_lock' => [
            'wait_seconds' => (int) env('CONNECTOR_IMAP_MAILBOX_LOCK_WAIT', 15),
            'ttl_seconds' => (int) env('CONNECTOR_IMAP_MAILBOX_LOCK_TTL', 700),
            'requeue_after_seconds' => (int) env('CONNECTOR_IMAP_MAILBOX_REQUEUE_AFTER', 60),
            // Wall-clock window a sync job keeps re-queuing on a busy mailbox before
            // giving up — decoupled from the failure-retry count (see
            // SerializedConnectorSyncJob::retryUntil).
            'requeue_window_minutes' => (int) env('CONNECTOR_IMAP_MAILBOX_REQUEUE_WINDOW_MIN', 30),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-connector provider settings
    |--------------------------------------------------------------------------
    |
    | Connector implementations read their provider client_id /
    | client_secret / redirect_uri from this block. Each connector
    | owns its own sub-array; the host operator wires the env vars.
    |
    */
    'providers' => [
        'google-drive' => [
            'client_id' => env('CONNECTOR_GOOGLE_DRIVE_CLIENT_ID'),
            'client_secret' => env('CONNECTOR_GOOGLE_DRIVE_CLIENT_SECRET'),
            'redirect_uri' => env(
                'CONNECTOR_GOOGLE_DRIVE_REDIRECT_URI',
                env('APP_URL', 'http://localhost').'/api/admin/connectors/google-drive/oauth/callback'
            ),
            'oauth_authorize_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'oauth_token_url' => 'https://oauth2.googleapis.com/token',
            'oauth_revoke_url' => 'https://oauth2.googleapis.com/revoke',
            'api_base' => 'https://www.googleapis.com/drive/v3',
        ],

        'notion' => [
            'client_id' => env('CONNECTOR_NOTION_CLIENT_ID'),
            'client_secret' => env('CONNECTOR_NOTION_CLIENT_SECRET'),
            'redirect_uri' => env(
                'CONNECTOR_NOTION_REDIRECT_URI',
                env('APP_URL', 'http://localhost').'/api/admin/connectors/notion/oauth/callback'
            ),
            'oauth_authorize_url' => 'https://api.notion.com/v1/oauth/authorize',
            'oauth_token_url' => 'https://api.notion.com/v1/oauth/token',
            // Notion has no programmatic revoke endpoint as of API
            // v2022-06-28; operators must disconnect inside the
            // Notion workspace UI to fully revoke an integration.
            'oauth_revoke_url' => null,
            'api_base' => 'https://api.notion.com/v1',
            // Notion-Version header value. Pin to a known-good
            // revision; bump when Notion ships a backward-compatible
            // version we've validated against.
            'api_version' => env('CONNECTOR_NOTION_API_VERSION', '2022-06-28'),
        ],

        'evernote' => [
            'client_id' => env('CONNECTOR_EVERNOTE_CLIENT_ID'),
            'client_secret' => env('CONNECTOR_EVERNOTE_CLIENT_SECRET'),
            'redirect_uri' => env(
                'CONNECTOR_EVERNOTE_REDIRECT_URI',
                env('APP_URL', 'http://localhost').'/api/admin/connectors/evernote/oauth/callback'
            ),
            'oauth_authorize_url' => 'https://www.evernote.com/oauth2/authorize',
            'oauth_token_url' => 'https://www.evernote.com/oauth2/token',
            'oauth_revoke_url' => 'https://www.evernote.com/oauth2/revoke',
            // Override to https://sandbox.evernote.com for local dev
            // (Evernote ships a parallel sandbox at the same /v1 path
            // structure for testing without affecting production data).
            'api_base' => env('CONNECTOR_EVERNOTE_API_BASE', 'https://api.evernote.com'),
        ],

        'fabric' => [
            // Fabric.so (fabric.so) — the AI-native knowledge tool, NOT
            // Microsoft Fabric. Auth is API-key based today; OAuth2 is
            // documented as "coming soon" per
            // https://developers.fabric.so/developer-guide/getting-started.
            //
            // Per-tenant credentials live on the installation's
            // `config_json.api_key` / `config_json.workspace_id` — the
            // env-var path below is a development convenience for
            // single-tenant operators.
            'api_key' => env('CONNECTOR_FABRIC_API_KEY'),
            'workspace_id' => env('CONNECTOR_FABRIC_WORKSPACE_ID'),
            'api_base' => env('CONNECTOR_FABRIC_API_BASE', 'https://api.fabric.so'),
            // Flip to true once fabric.so ships OAuth2 GA + the
            // connector's `initiateOAuth()` / `handleOAuthCallback()`
            // implementations land. Until then, leave false — the
            // admin SPA renders the API-key form instead of OAuth.
            'oauth_enabled' => (bool) env('CONNECTOR_FABRIC_OAUTH_ENABLED', false),
            'client_id' => env('CONNECTOR_FABRIC_CLIENT_ID'),
            'client_secret' => env('CONNECTOR_FABRIC_CLIENT_SECRET'),
            'redirect_uri' => env(
                'CONNECTOR_FABRIC_REDIRECT_URI',
                env('APP_URL', 'http://localhost').'/api/admin/connectors/fabric/oauth/callback'
            ),
            'oauth_authorize_url' => 'https://api.fabric.so/v2/oauth/authorize',
            'oauth_token_url' => 'https://api.fabric.so/v2/oauth/token',
        ],

        'onedrive' => [
            'client_id' => env('CONNECTOR_ONEDRIVE_CLIENT_ID'),
            'client_secret' => env('CONNECTOR_ONEDRIVE_CLIENT_SECRET'),
            'redirect_uri' => env(
                'CONNECTOR_ONEDRIVE_REDIRECT_URI',
                env('APP_URL', 'http://localhost').'/api/admin/connectors/onedrive/oauth/callback'
            ),
            // Microsoft identity platform v2.0. The `common` tenant
            // lets both work + personal accounts authorise against
            // the same redirect URI; operators with a single-tenant
            // app can override via CONNECTOR_ONEDRIVE_TENANT (e.g.
            // a customer's Azure AD tenant id).
            'tenant' => env('CONNECTOR_ONEDRIVE_TENANT', 'common'),
            // Leave the URLs null in config so the connector composes
            // them from `tenant` at call time. Override here only if
            // you need to point at a national/sovereign Microsoft
            // cloud (e.g. https://login.microsoftonline.us/...).
            'oauth_authorize_url' => env('CONNECTOR_ONEDRIVE_OAUTH_AUTHORIZE_URL'),
            'oauth_token_url' => env('CONNECTOR_ONEDRIVE_OAUTH_TOKEN_URL'),
            'api_base' => env('CONNECTOR_ONEDRIVE_API_BASE', 'https://graph.microsoft.com/v1.0'),
        ],

        'confluence' => [
            'client_id' => env('CONNECTOR_CONFLUENCE_CLIENT_ID'),
            'client_secret' => env('CONNECTOR_CONFLUENCE_CLIENT_SECRET'),
            'redirect_uri' => env(
                'CONNECTOR_CONFLUENCE_REDIRECT_URI',
                env('APP_URL', 'http://localhost').'/api/admin/connectors/confluence/oauth/callback'
            ),
            'oauth_authorize_url' => 'https://auth.atlassian.com/authorize',
            'oauth_token_url' => 'https://auth.atlassian.com/oauth/token',
            // Atlassian's accessible-resources endpoint — returns
            // every Atlassian product instance the OAuth user
            // authorised. We pick the first Confluence-capable site
            // and persist its `cloudId` in `extra_json.cloud_id`.
            'accessible_resources_url' => 'https://api.atlassian.com/oauth/token/accessible-resources',
            // API root for per-tenant Confluence requests. The
            // connector composes `{api_base}/ex/confluence/{cloudId}/wiki/rest/api`
            // at call time.
            'api_base' => env('CONNECTOR_CONFLUENCE_API_BASE', 'https://api.atlassian.com'),
        ],

        'jira' => [
            // OAuth 2.0 3LO — shared Atlassian flow with Confluence.
            // The same workspace can install both connectors (one
            // ConfluenceConnector + one JiraConnector row) against
            // the same `cloud_id`; the connector keys are distinct so
            // installation rows don't collide.
            //
            // Env vars follow the project-wide `CONNECTOR_<PROVIDER>_*`
            // convention so all seven connectors share one discovery
            // shape (Copilot iter1 finding #5).
            'client_id' => env('CONNECTOR_JIRA_CLIENT_ID'),
            'client_secret' => env('CONNECTOR_JIRA_CLIENT_SECRET'),
            'redirect_uri' => env(
                'CONNECTOR_JIRA_REDIRECT_URI',
                env('APP_URL', 'http://localhost').'/api/admin/connectors/jira/oauth/callback'
            ),
            'oauth_authorize_url' => env('CONNECTOR_JIRA_OAUTH_AUTHORIZE_URL', 'https://auth.atlassian.com/authorize'),
            'oauth_token_url' => env('CONNECTOR_JIRA_OAUTH_TOKEN_URL', 'https://auth.atlassian.com/oauth/token'),
            // Atlassian DOES expose a programmatic revoke endpoint
            // for OAuth 2.0 3LO; `disconnect()` calls it best-effort.
            'oauth_revoke_url' => env('CONNECTOR_JIRA_OAUTH_REVOKE_URL', 'https://auth.atlassian.com/oauth/token/revoke'),
            'accessible_resources_url' => env(
                'CONNECTOR_JIRA_ACCESSIBLE_RESOURCES_URL',
                'https://api.atlassian.com/oauth/token/accessible-resources',
            ),
            // API base template with `{cloud_id}` placeholder. The
            // connector substitutes it at call time.
            'api_base_template' => env(
                'CONNECTOR_JIRA_API_BASE_TEMPLATE',
                'https://api.atlassian.com/ex/jira/{cloud_id}/rest/api/3',
            ),
        ],
    ],

];
