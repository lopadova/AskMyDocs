<?php

declare(strict_types=1);

/**
 * v7.0/W1.B — overrides for `padosoft/askmydocs-mcp-pack`.
 *
 * The package ships its own `config/mcp-pack.php` and `mergeConfigFrom`
 * merges its defaults under the `mcp-pack` namespace. Any key declared
 * HERE takes precedence — keys we omit fall back to the package defaults.
 *
 * Only two knobs are non-default for AskMyDocs:
 *
 *   1. `audit_model` — points at the host's subclass that fills BOTH
 *      the package-contract columns (`input_hash`, `actor`) and the
 *      host's operator-forensics columns (`input_json_redacted`,
 *      `user_id`). See `app/Models/McpToolCallAudit.php`.
 *
 *   2. `tool_calling.enabled` — read from `AI_AGENTIC_ENABLED`, the
 *      master kill-switch AskMyDocs has used since v5.0. The package
 *      default is `false`, which would mean tool calling never fires;
 *      we tie it to the host flag so operator intent is honoured.
 */
return [

    'audit_model' => \App\Models\McpToolCallAudit::class,

    'tool_calling' => [
        'enabled' => env('AI_AGENTIC_ENABLED', false),
        'max_iterations' => env('AI_MCP_TOOL_CALL_MAX_ITERATIONS', 3),
    ],

];
