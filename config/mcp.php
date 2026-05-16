<?php

return [

    /*
    |-----------------------------------------------------------------------
    | v5.0 Agentic switch
    |-----------------------------------------------------------------------
    |
    | Keep MCP disabled by default. Enable on environments where at least
    | one MCP server is configured under `mcp_servers` and the operator
    | wants chat turns to route through the tool-calling loop.
    */
    'enabled' => (bool) env('AI_AGENTIC_ENABLED', false),

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
        'parallel_invocation' => (bool) env('AI_MCP_PARALLEL_INVOCATION', true),
    ],

    /*
    |-----------------------------------------------------------------------
    | Audit redaction
    |-----------------------------------------------------------------------
    |
    | Tool inputs are pii-redacted before persistence to
    | mcp_tool_call_audit.input_json_redacted; results are hashed.
    */
    'audit' => [
        'redact_inputs' => (bool) env('AI_MCP_AUDIT_REDACT_INPUTS', true),
        'hash_results' => (bool) env('AI_MCP_AUDIT_HASH_RESULTS', true),
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
