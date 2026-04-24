<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Admin — PDF export engine (Phase G4)
    |--------------------------------------------------------------------------
    |
    | Drives the selection inside {@see \App\Services\Admin\Pdf\PdfRendererFactory}.
    | Three values are supported:
    |
    |   - 'disabled'    — default. Every /export-pdf call returns 501.
    |   - 'dompdf'      — requires `dompdf/dompdf` (composer require).
    |   - 'browsershot' — requires `spatie/browsershot` + Node.js + Chromium.
    |
    | Neither driver is a hard dependency of this project; see the
    | composer.json "suggest" block for installation guidance. Leaving
    | the engine disabled is the correct default for CI and for operators
    | who do not expose the KB admin UI externally.
    |
    */
    'pdf_engine' => env('ADMIN_PDF_ENGINE', 'disabled'),

    /*
    |--------------------------------------------------------------------------
    | Admin — allowed artisan commands (Phase H2)
    |--------------------------------------------------------------------------
    |
    | Strict whitelist of the artisan commands the MaintenanceCommandController
    | will execute. Any command NOT present as a top-level key here is
    | rejected with HTTP 404 (the lookup mode is "is this key in this array"
    | — nothing else). Shell-metacharacter-looking strings (`&&`, `;`, `|`,
    | `$()`) never match because they are not valid array keys.
    |
    | Per-command schema:
    |   args_schema          — map of arg name => rules. Supported rule keys:
    |     type         : 'string' | 'int' | 'bool'
    |     required     : bool (default false)
    |     nullable     : bool (default false — null rejected unless set)
    |     min / max    : numeric bounds for int; char bounds for string
    |     enum         : array of permitted scalar values
    |   requires_permission  — Spatie permission name the caller MUST have.
    |                         The route-level `role:admin|super-admin` guard is
    |                         NOT enough; destructive commands demand the
    |                         `commands.destructive` permission that the
    |                         RbacSeeder only grants to `super-admin`.
    |   destructive          — bool. Destructive commands require a
    |                         confirm_token issued by POST /preview and
    |                         consumed (single-use + 5m TTL) by POST /run.
    |   description          — human-readable; rendered in the wizard.
    |
    | `--force` is NEVER client-controllable. It is applied server-side in
    | `CommandRunnerService::run()` when the command being invoked
    | historically expects it (e.g. `kb:delete`).
    |
    */
    'allowed_commands' => [
        // Non-destructive — can be run by anyone with `commands.run`.
        'kb:validate-canonical' => [
            'args_schema' => [
                'project' => ['type' => 'string', 'nullable' => true, 'max' => 120],
            ],
            'requires_permission' => 'commands.run',
            'destructive' => false,
            'description' => 'Validate canonical frontmatter across the corpus without writing.',
        ],
        'kb:rebuild-graph' => [
            'args_schema' => [
                'project' => ['type' => 'string', 'nullable' => true, 'max' => 120],
            ],
            'requires_permission' => 'commands.run',
            'destructive' => false,
            'description' => 'Recompute kb_nodes + kb_edges from canonical frontmatter.',
        ],
        'queue:retry' => [
            'args_schema' => [
                // Positional: `queue:retry {id}` — passing `--id=…` would
                // be an unrecognised option (Copilot #1 fix).
                'id' => ['type' => 'string', 'required' => true, 'max' => 120, 'kind' => 'argument'],
            ],
            'requires_permission' => 'commands.run',
            'destructive' => false,
            'description' => 'Re-dispatch a specific failed job by uuid or "all".',
        ],

        // Destructive — require `commands.destructive` permission + confirm_token.
        'kb:ingest-folder' => [
            'args_schema' => [
                // Positional (`{path?}`) — optional but still a positional arg.
                'path' => ['type' => 'string', 'required' => true, 'max' => 500, 'kind' => 'argument'],
                // Option (`--project=`).
                'project' => ['type' => 'string', 'required' => true, 'max' => 120, 'kind' => 'option'],
            ],
            'requires_permission' => 'commands.destructive',
            'destructive' => true,
            'description' => 'Walk the KB disk and ingest every markdown file as new canonical content.',
        ],
        'kb:delete' => [
            'args_schema' => [
                // Option (`--project=`).
                'project' => ['type' => 'string', 'required' => true, 'max' => 120, 'kind' => 'option'],
                // Positional (`{path}`).
                'path' => ['type' => 'string', 'required' => true, 'max' => 500, 'kind' => 'argument'],
            ],
            'requires_permission' => 'commands.destructive',
            'destructive' => true,
            'description' => 'Soft-delete a canonical document by (project, path).',
        ],
        'kb:prune-deleted' => [
            'args_schema' => [
                'days' => ['type' => 'int', 'nullable' => true, 'min' => 0, 'max' => 365],
            ],
            'requires_permission' => 'commands.destructive',
            'destructive' => true,
            'description' => 'Hard-delete documents soft-deleted more than --days days ago.',
        ],
        'kb:prune-embedding-cache' => [
            'args_schema' => [
                'days' => ['type' => 'int', 'nullable' => true, 'min' => 0, 'max' => 365],
            ],
            'requires_permission' => 'commands.destructive',
            'destructive' => true,
            'description' => 'Hard-delete embedding_cache rows last used more than --days days ago.',
        ],
        'kb:prune-orphan-files' => [
            'args_schema' => [
                'dry_run' => ['type' => 'bool', 'nullable' => true],
            ],
            'requires_permission' => 'commands.destructive',
            'destructive' => true,
            'description' => 'Delete files on the KB disk that have no knowledge_documents row.',
        ],
        'chat-log:prune' => [
            'args_schema' => [
                'days' => ['type' => 'int', 'nullable' => true, 'min' => 0, 'max' => 365],
            ],
            'requires_permission' => 'commands.destructive',
            'destructive' => true,
            'description' => 'Hard-delete chat_logs rows older than --days days.',
        ],
        'activity-log:prune' => [
            'args_schema' => [
                'days' => ['type' => 'int', 'nullable' => true, 'min' => 0, 'max' => 365],
            ],
            'requires_permission' => 'commands.destructive',
            'destructive' => true,
            'description' => 'Hard-delete activity_log rows older than --days days.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Admin — command runner token TTL (Phase H2)
    |--------------------------------------------------------------------------
    |
    | Copilot #3 fix: docblock now matches the real implementation.
    |
    | The preview/run two-step dance for destructive commands. Preview
    | issues an opaque random token via `random_bytes(32)` → hex; we
    | store ONLY `sha256($token)` in `admin_command_nonces` so the DB
    | row cannot replay the plaintext even if the table leaks. The
    | plaintext round-trips to the client once and is consumed by
    | /run in a transactional `lockForUpdate()` + `used_at` update
    | (Copilot #8 fix for the atomic consume race).
    |
    | `token_ttl_seconds` is how long that plaintext remains usable for
    | the destructive POST /run step. Default 300s / 5 min. Keep this
    | short — the whole point is that a human just confirmed the
    | action intent within a bounded window.
    |
    */
    'command_runner' => [
        'token_ttl_seconds' => (int) env('ADMIN_COMMAND_TOKEN_TTL', 300),
        'audit_retention_days' => (int) env('ADMIN_AUDIT_RETENTION_DAYS', 365),
        'nonce_retention_days' => (int) env('ADMIN_NONCE_RETENTION_DAYS', 1),
    ],
];
