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
| Rate limiting is the dedicated "mcp" limiter (AppServiceProvider), keyed
| by the request's raw Bearer token hash + tenant — not "api", which is
| never registered in this app and would throw on every request.
|
| Copilot review PR #497 (pullrequestreview-5256955613) — `throttle:mcp`
| runs BEFORE `mcp.scope`, not after. Laravel's middleware pipeline never
| reaches a later middleware once an earlier one short-circuits with a
| response, so with scope first a request `EnforceMcpScope` rejects
| (missing/invalid/expired/revoked token, tenant mismatch) never hit the
| limiter at all — unthrottled token-guessing against this route, plus an
| unthrottled DB lookup per invalid token. Running the limiter first
| closes that: every request is throttled before scope gets a chance to
| reject it.
|
| This doesn't weaken the limiter's per-caller isolation for legitimate
| traffic: the key's tenant segment resolves to TenantContext's untouched
| default here (EnforceMcpScope::set() hasn't run yet — this bare route
| carries no tenant.resolve middleware), but the token-hash segment
| already comes straight off the raw request and uniquely identifies the
| caller on its own (SHA-256 collision resistance), so two different
| tokens never share a bucket regardless of which middleware set the
| tenant segment.
|
*/

Mcp::web('/mcp/kb', KnowledgeBaseServer::class)
    ->middleware(['throttle:mcp', 'mcp.scope']);
