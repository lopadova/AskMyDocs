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
    | FQCN list of connector implementations that ship inside AskMyDocs
    | core (no separate composer package required). The reference Google
    | Drive connector lands here in v4.5/W1 — subsequent connectors
    | (Notion, Evernote, ...) ship as `padosoft/askmydocs-connector-*`
    | packages and auto-register via composer extra discovery.
    |
    */
    'built_in' => [
        \App\Connectors\BuiltIn\GoogleDriveConnector::class,
        \App\Connectors\BuiltIn\NotionConnector::class,
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
    */
    'sync_job_queue' => env('CONNECTOR_SYNC_JOB_QUEUE', 'default'),

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
        ],
    ],

];
