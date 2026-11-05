<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Mcp\Servers\KnowledgeBaseServer;
use App\Models\KbCanonicalAudit;
use App\Models\McpTenantToken;
use App\Models\User;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use ReflectionClass;
use Symfony\Component\HttpFoundation\Response;

final class EnforceMcpScope
{
    /**
     * Read-only "propose" tools that still require an elevated scope because
     * they surface editorial suggestions. Disjoint from the write set below.
     *
     * v8.37/W3b round 8 (Copilot PR #496 must-fix) — every key here is the
     * NORMALIZED tool name, i.e. `Tool::name()` (which defaults to
     * `Str::kebab(class_basename($this))` — the FULL class basename,
     * "...Tool" suffix included) stripped of non-alphanumerics. The
     * original 4 entries below were missing that trailing `tool`: e.g.
     * `KbListDanglingWikilinksTool::name()` is
     * `kb-list-dangling-wikilinks-tool`, normalizing to
     * `kblistdanglingwikilinkstool`, not `kblistdanglingwikilinks`. Since
     * none of the 4 ever matched, and all 4 ARE `#[IsReadOnly]` (so
     * {@see writeToolNames()} excludes them too), `requiredScopeForTool()`
     * silently fell through to the SCOPE_READ default for every one of
     * them — the intended `mcp:tools:propose` elevation was dead code, not
     * enforced. Discovered while adding this PR's own entry (which had the
     * SAME bug, but for a #[IsReadOnly]-absent tool that fell to
     * SCOPE_WRITE instead — unreachable by the default
     * `['mcp:read', 'mcp:tools:propose']` token minted by
     * McpTenantTokenController::store(), rather than merely under-gated).
     *
     * @var array<string, true>
     */
    private const PROPOSE_TOOL_NAMES = [
        'kblistdanglingwikilinkstool' => true,
        'kbdetectdecisiondebttool' => true,
        'kbsuggestsupersessionchaintool' => true,
        'kbproposecanonicaledittool' => true,
        'kbproposetextcorrectiontool' => true,
        // v8.38/W4c (ADR 0032 §10/§11) — proposes a promotion candidate,
        // never writes the corpus directly. Same posture as
        // kbproposetextcorrectiontool above.
        'kbimportwikitool' => true,
    ];

    public const SCOPE_READ = 'mcp:read';

    public const SCOPE_PROPOSE = 'mcp:tools:propose';

    public const SCOPE_WRITE = 'mcp:tools:write';

    /**
     * Normalized names of every write-capable tool registered on
     * KnowledgeBaseServer, derived once from the `#[IsReadOnly]` attribute so
     * the middleware can never drift from the actual tool set. A read-scoped
     * token must NOT be able to invoke a mutating tool.
     *
     * @var array<string, true>|null
     */
    private static ?array $writeToolNames = null;

    public function handle(Request $request, Closure $next): Response
    {
        // v8.37/W3b round 8 (Copilot PR #496 must-fix) — the token is now
        // validated for EVERY protocol method, not only `tools/call`. The
        // previous early-return before any bearer-token check let
        // `initialize`/`tools/list`/etc. reach the MCP handler completely
        // unauthenticated once `auth:sanctum` was removed from routes/ai.php
        // (round 7) — that removal was correct (auth:sanctum rejected the
        // only real caller's McpTenantToken bearer before this middleware
        // ever ran), but it left `mcp.scope` as this route's SOLE auth
        // layer, and this middleware only ever gated the one method it
        // needed a tool NAME for.
        $plainToken = (string) $request->bearerToken();
        if ($plainToken === '') {
            return response()->json(['error' => 'mcp_token_required'], 401);
        }

        $token = McpTenantToken::query()
            ->where('token_hash', hash('sha256', $plainToken))
            ->first();
        if ($token === null) {
            return response()->json(['error' => 'mcp_token_invalid'], 401);
        }
        if ($token->revoked_at !== null) {
            return response()->json(['error' => 'mcp_token_revoked'], 403);
        }
        if ($token->expires_at !== null && $token->expires_at->lte(now())) {
            return response()->json(['error' => 'mcp_token_expired'], 403);
        }

        // v8.37/W3b round 8 (Copilot PR #496 must-fix) — this bare route
        // carries no `tenant.resolve` middleware and nothing else ever sets
        // TenantContext here, so the PREVIOUS "activeTenant === token's
        // tenant" comparison was comparing the token against a context
        // that was ALWAYS the untouched 'default' — rejecting every real,
        // valid token for a non-default tenant with `mcp_tenant_mismatch`.
        // The bearer token itself is what establishes tenant identity on
        // this route (mirrors how it establishes auth identity); an
        // explicit `X-Tenant-Id` header (McpConnectCommand sends one from
        // its `--tenant=` option) is honoured as a defense-in-depth sanity
        // check — a caller pointed at the wrong token/tenant pairing is
        // rejected outright — but is never itself the source of truth.
        $tokenTenant = (string) $token->tenant_id;
        $headerTenant = trim((string) $request->header('X-Tenant-Id', ''));
        if ($headerTenant !== '' && $headerTenant !== $tokenTenant) {
            return response()->json(['error' => 'mcp_tenant_mismatch'], 403);
        }

        // SEC-AUDIT-fix (2026-09-25) — `AccessScopeScope::apply()` (R33)
        // returns with NO restriction the moment `auth()->user()` is null
        // ("bypass in unauthenticated contexts"), and nothing on this route
        // ever authenticated a Laravel user for the token: `auth:sanctum`
        // was deliberately removed from routes/ai.php (round 7, see the
        // comment above `handle()`) because it rejected the McpTenantToken
        // bearer, and no replacement ever bound a principal. The practical
        // effect, live since that change: EVERY MCP retrieval tool that
        // queries KnowledgeDocument/KnowledgeChunk (KbSearchTool,
        // KbReadChunkTool, KbSearchByProjectTool, ...) ran with the
        // project/ACL layers of AccessScopeScope entirely disabled — only
        // tenant isolation (R30, via TenantContext below) held. A token
        // scoped to `mcp:read` for a user restricted to `hr/policies/**`
        // could retrieve `hr/salaries/**` chunks over MCP: the exact H8/
        // v8.31 shape R33 exists to prevent, this time for the whole
        // transport rather than one query arm.
        //
        // The fix restores the token's `created_by` user as the request
        // principal — ADR 0032 §4's "principal binding" gap, except it is
        // not new-feature debt: it is a live bypass on the ALREADY-SHIPPED
        // MCP surface, fixed here ahead of and independently from the W4
        // wiki-export work that first named it. `User::find()` returns
        // null for a missing id AND for a soft-deleted user (SoftDeletes'
        // own global scope) — offboarding a user therefore revokes their
        // minted tokens' retrieval power without a separate check, exactly
        // what SEC-OFFBOARD-001 asks for. Fail closed on a missing
        // principal (orphaned/legacy token, or `created_by` never set)
        // rather than falling back to the previous unrestricted behaviour.
        $principal = User::query()->find($token->created_by);
        if ($principal === null) {
            return response()->json(['error' => 'mcp_principal_missing'], 403);
        }

        // Copilot review PR #497 (pullrequestreview-5257132403,
        // discussion_r4054304571) — TenantContext is a process-scoped
        // singleton (see the round-7/8 comments above and
        // AppServiceProvider::registerRateLimiters — `$this->app->
        // singleton(TenantContext::class)`). This bare route has no
        // `tenant.resolve` middleware to overwrite it on the NEXT request,
        // unlike every tenant-aware web/API route (ResolveTenant always
        // calls set() unconditionally). Setting it here without restoring
        // means a long-running worker (Octane, or any container reused
        // across requests) would leak this MCP token's tenant into
        // whatever request that same process handles next, until
        // something else happens to reset it — risking cross-tenant reads
        // and mis-attributed audit rows in the meantime. Save/restore the
        // PRE-request value (not a hardcoded 'default') via try/finally so
        // every return path below — including the early-return branches
        // for scope checks — restores it, not just the happy path.
        $tenantContext = app(TenantContext::class);
        $previousTenant = $tenantContext->current();
        $tenantContext->set($tokenTenant);

        // Same discipline as ExecuteAgentRunJob's principal restoration:
        // forgetGuards() BEFORE setUser() and again in `finally`. This is
        // an HTTP middleware, not a queue job, but the reasoning is
        // identical — Octane (and any other long-lived worker reusing this
        // process across requests) must never let one caller's principal
        // leak into the next request the same process handles.
        Auth::forgetGuards();
        Auth::setUser($principal);

        try {
            // Copilot review PR #497 (pullrequestreview-5256772155) —
            // `mcp:read` is the baseline scope for the MCP transport as a
            // whole, not just for `tools/call`. Before this check, a token
            // carrying ONLY an elevated scope (`mcp:tools:propose`/
            // `mcp:tools:write`, minted without `mcp:read`) — or, more
            // subtly, a valid but entirely empty/misconfigured
            // `scopes_json` — could still reach `initialize`/`tools/list`/
            // every other protocol method, because those methods never
            // consulted `scopes_json` at all.
            $scopes = is_array($token->scopes_json) ? $token->scopes_json : [];
            if (! in_array(self::SCOPE_READ, $scopes, true)) {
                return response()->json([
                    'error' => 'mcp_scope_missing',
                    'required_scope' => self::SCOPE_READ,
                ], 403);
            }

            $payload = $request->json()->all();
            $isToolsCall = ($payload['method'] ?? null) === 'tools/call';

            $toolName = '';
            if ($isToolsCall) {
                $toolName = (string) data_get($payload, 'params.name', '');
                if ($toolName === '') {
                    return response()->json(['error' => 'tool_name_required'], 422);
                }

                $requiredScope = $this->requiredScopeForTool($toolName);
                if (! in_array($requiredScope, $scopes, true)) {
                    return response()->json([
                        'error' => 'mcp_scope_missing',
                        'required_scope' => $requiredScope,
                    ], 403);
                }
            }

            // Copilot review PR #497 (pullrequestreview-5257728388) —
            // `last_used_at` used to update only on `tools/call`, but every
            // protocol method is now fully authenticated and scope-checked
            // (round 8 above). A caller that only ever calls
            // `initialize`/`tools/list` would never update its token's
            // `last_used_at`, under-reporting real traffic in the admin
            // token list. Update it for every authenticated request that
            // reaches this point, regardless of method.
            $token->forceFill(['last_used_at' => now()])->save();

            if ($isToolsCall) {
                $this->auditInvocation($toolName, data_get($payload, 'params.arguments'));
            }

            return $next($request);
        } finally {
            Auth::forgetGuards();
            $tenantContext->set($previousTenant);
        }
    }

    private function requiredScopeForTool(string $toolName): string
    {
        $normalized = self::normalizeToolName($toolName);

        if (isset(self::PROPOSE_TOOL_NAMES[$normalized])) {
            return self::SCOPE_PROPOSE;
        }

        if (isset(self::writeToolNames()[$normalized])) {
            return self::SCOPE_WRITE;
        }

        return self::SCOPE_READ;
    }

    private static function normalizeToolName(string $toolName): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9]/', '', $toolName));
    }

    /**
     * Write-capable tool names, computed once per process by reflecting over
     * KnowledgeBaseServer's registered tools: a tool WITHOUT the `#[IsReadOnly]`
     * annotation mutates state and therefore requires the write scope.
     *
     * @return array<string, true>
     */
    public static function writeToolNames(): array
    {
        if (self::$writeToolNames !== null) {
            return self::$writeToolNames;
        }

        /** @var array<int, class-string> $tools */
        $tools = (new ReflectionClass(KnowledgeBaseServer::class))
            ->getProperty('tools')
            ->getDefaultValue();

        $names = [];
        foreach ($tools as $toolClass) {
            $toolReflection = new ReflectionClass($toolClass);
            if ($toolReflection->getAttributes(IsReadOnly::class) !== []) {
                continue;
            }
            $instance = $toolReflection->newInstanceWithoutConstructor();
            $names[self::normalizeToolName($instance->name())] = true;
        }

        return self::$writeToolNames = $names;
    }

    private function auditInvocation(string $toolName, mixed $rawArgs): void
    {
        $projectKey = is_array($rawArgs) && is_string($rawArgs['project_key'] ?? null)
            ? (string) $rawArgs['project_key']
            : 'mcp';

        $argsJson = is_array($rawArgs) ? json_encode($rawArgs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '[]';
        $argsHash = hash('sha256', (string) $argsJson);

        KbCanonicalAudit::query()->create([
            'tenant_id' => app(TenantContext::class)->current(),
            'project_key' => $projectKey !== '' ? $projectKey : 'mcp',
            'event_type' => 'mcp_tool_invoked',
            'actor' => 'token',
            'metadata_json' => [
                'tool_name' => $toolName,
                'args_hash' => $argsHash,
            ],
        ]);
    }
}

