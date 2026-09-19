<?php

use App\Mcp\Servers\KnowledgeBaseServer;
use Laravel\Mcp\Facades\Mcp;

/*
|--------------------------------------------------------------------------
| AI / MCP Routes
|--------------------------------------------------------------------------
|
| Auth is EnforceMcpScope ("mcp.scope") alone: it looks up the request's
| Bearer token against McpTenantToken (its own hashed-token table),
| validates revocation/expiry/tenant match, and enforces the per-tool
| read/propose/write scope — entirely independent of Sanctum/Auth::user(),
| which real MCP clients (McpConnectCommand emits an `askmd_...`
| McpTenantToken, never a Sanctum PAT) never populate.
|
| Rate limiting is the dedicated "mcp" limiter (AppServiceProvider),
| keyed by the McpTenantToken bearer hash + tenant — not "api", which is
| never registered in this app and would throw on every request.
|
*/

Mcp::web('/mcp/kb', KnowledgeBaseServer::class)
    ->middleware(['mcp.scope', 'throttle:mcp']);
