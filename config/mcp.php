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
    | Inbound MCP server (v8.37/W3b round 7, SEC-THROTTLE-001)
    |-----------------------------------------------------------------------
    |
    | POST /mcp/kb (routes/ai.php, Mcp::web) is the HTTP transport this app
    | HOSTS for external MCP clients calling KnowledgeBaseServer's tools —
    | the reverse direction of the `tool_calling` config above. Keyed by
    | the McpTenantToken bearer hash + tenant (there is no Sanctum user on
    | this route — see EnforceMcpScope), never IP alone. A zero/invalid
    | config cannot silently disable it — it floors at 1/min.
    */
    'server' => [
        'rate_limit_per_minute' => (int) env('MCP_SERVER_RATE_LIMIT_PER_MINUTE', 60),
    ],

];
