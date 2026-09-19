<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Mcp\Servers\KnowledgeBaseServer;
use App\Models\KbCanonicalAudit;
use App\Models\McpTenantToken;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
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
        app(TenantContext::class)->set($tokenTenant);

        $payload = $request->json()->all();
        if (($payload['method'] ?? null) !== 'tools/call') {
            return $next($request);
        }

        $toolName = (string) data_get($payload, 'params.name', '');
        if ($toolName === '') {
            return response()->json(['error' => 'tool_name_required'], 422);
        }

        $requiredScope = $this->requiredScopeForTool($toolName);
        $scopes = is_array($token->scopes_json) ? $token->scopes_json : [];
        if (! in_array($requiredScope, $scopes, true)) {
            return response()->json([
                'error' => 'mcp_scope_missing',
                'required_scope' => $requiredScope,
            ], 403);
        }

        $token->forceFill(['last_used_at' => now()])->save();
        $this->auditInvocation($toolName, data_get($payload, 'params.arguments'));

        return $next($request);
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

