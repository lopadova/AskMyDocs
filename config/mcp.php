<?php

return [

    /*
    |-----------------------------------------------------------------------
    | v5.0 Agentic switch
    |-----------------------------------------------------------------------
    |
    | Keep MCP disabled by default. Enable only on GA/preview environments
    | where the Node sidecar is deployed on localhost with loopback
    | connectivity.
    */
    'enabled' => (bool) env('AI_AGENTIC_ENABLED', false),

    /*
    |-----------------------------------------------------------------------
    | Node sidecar
    |-----------------------------------------------------------------------
    |
    | The sidecar exposes a small HTTP shim on localhost:3535.
    */
    'sidecar' => [
        'base_url' => env('MCP_CLIENT_BASE_URL', 'http://127.0.0.1:3535'),
        'health_endpoint' => '/healthz',
        'timeout_ms' => (int) env('MCP_CLIENT_TIMEOUT_MS', 2500),
    ],

    /*
    |-----------------------------------------------------------------------
    | Tool-calling configuration
    |-----------------------------------------------------------------------
    |
    | MCP/Tool-calling is only supported by providers that expose
    | function-calling semantics in the Chat Completions payload
    | (currently openai / openrouter).
    */
    'tool_calling' => [
        'max_iterations' => (int) env('AI_MCP_TOOL_CALL_MAX_ITERATIONS', 3),
        'default_tool_choice' => env('AI_MCP_TOOL_CALL_DEFAULT_CHOICE', 'auto'),
    ],

    /*
    |-----------------------------------------------------------------------
    | Internal endpoint auth
    |-----------------------------------------------------------------------
    |
    | For v1 scaffold this is optional. If empty, only Sanctum-protected
    | requests can reach internal callbacks.
    */
    'internal_auth_token' => env('MCP_INTERNAL_AUTH_TOKEN'),
];
