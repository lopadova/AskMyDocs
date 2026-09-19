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
| by the request's raw Bearer token hash alone — not "api", which is
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
| Follow-up (pullrequestreview-5257033036, discussion_r4054237132): the
| limiter key used to append a `TenantContext::current()` segment. With
| throttle running before scope, that segment isn't established by this
| request yet (EnforceMcpScope::set() hasn't fired) and, being a process
| singleton, can carry stale state from a prior request on the same
| worker — so the same token could drift across buckets. The limiter now
| keys on the token hash alone (see AppServiceProvider), which already
| uniquely and stably identifies the caller.
|
*/

Mcp::web('/mcp/kb', KnowledgeBaseServer::class)
    ->middleware(['throttle:mcp', 'mcp.scope']);
