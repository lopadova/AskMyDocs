<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\User;
use App\Services\Kb\Export\KbWikiExportRequestService;
use App\Support\TenantContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

/**
 * v8.38/W4c (ADR 0032 §11) — MCP surface for starting a portable wiki
 * export. A genuine write: it queues {@see \App\Jobs\ExecuteKbWikiExportJob}
 * and, once the job completes, retains a downloadable bundle — so, unlike
 * `KbProposeTextCorrectionTool`/`KbImportWikiTool`, this tool is NOT in
 * {@see \App\Http\Middleware\EnforceMcpScope::PROPOSE_TOOL_NAMES}: it falls
 * to the reflection-derived `mcp:tools:write` scope (no `#[IsReadOnly]`
 * attribute), the same gate `KbReembedProjectTool`/`KbDetokenizeTool` sit
 * behind.
 *
 * Authorized like the HTTP endpoint (`POST /api/admin/kb/exports`,
 * `role:admin|super-admin`): the MCP transport's `mcp:tools:write` SCOPE and
 * a Laravel ROLE are different axes — a token can carry the write scope
 * without its bound principal (EnforceMcpScope's `created_by`) holding an
 * admin role in THIS tenant, so this tool re-checks the role explicitly,
 * mirroring {@see \App\Jobs\ExecuteKbWikiExportJob}'s own re-check.
 *
 * Every MCP tool call is already audited by `EnforceMcpScope::auditInvocation()`
 * (`kb_canonical_audit`, `event_type: mcp_tool_invoked`) — that generic gate
 * is treated as satisfying ADR 0032 §11's "written to admin_command_audit"
 * requirement rather than duplicating a second bespoke audit write here;
 * `admin_command_audit` is the whitelisted-Artisan-runner's own table
 * (`CommandRunnerService`), a different surface than this one.
 *
 * `#[IsIdempotent]` is accurate: the underlying
 * {@see KbWikiExportRequestService::requestExport()} already resolves a
 * repeated identical request to the SAME row via its own idempotency key
 * (ADR 0032 §11) — this tool adds nothing on top of that except the
 * MCP-specific per-principal rate limit ADR 0032 §11 asks for.
 */
#[Description('Start (or reuse, via idempotency) an async portable wiki export for a project — the ACL-scoped folder ADR 0032 describes, downloadable once complete via KbGetExportTool / GET /api/admin/kb/exports/{id}/download. Only format="llm-wiki" and include_images=false are implemented; any other value is refused. Requires admin or super-admin. Rate-limited per principal. Answers {disabled: true} when Wiki Export is off (KB_WIKI_EXPORT_ENABLED).')]
#[IsIdempotent]
class KbCreateExportTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_key' => $schema->string()
                ->description('The project_key to export.')
                ->required(),
            'format' => $schema->string()
                ->description('Optional. Only "llm-wiki" (the default) is implemented.'),
            'include_images' => $schema->boolean()
                ->description('Optional. Only false (the default) is implemented.'),
        ];
    }

    public function handle(Request $request, KbWikiExportRequestService $exports, TenantContext $tenants): Response
    {
        if (! (bool) config('kb.wiki_export.enabled', false)) {
            return Response::json(['disabled' => true, 'flag' => 'KB_WIKI_EXPORT_ENABLED']);
        }

        $user = Auth::user();
        if (! $user instanceof User) {
            return Response::error('No MCP principal bound to this request.');
        }
        if (! $user->hasAnyRole(['admin', 'super-admin'])) {
            return Response::error('The MCP token\'s principal does not hold export permission (requires admin or super-admin).');
        }

        $projectKey = trim((string) $request->get('project_key', ''));
        if ($projectKey === '') {
            return Response::error('project_key is required.');
        }

        // Independent-review nit (PR #509) — tooManyAttempts()+hit() is a
        // check-then-act pair; without a lock, two concurrent calls from
        // the same principal could both pass the check before either
        // records a hit, slightly overshooting the budget. A per-principal
        // lock closes it cheaply, mirroring KbWikiImportService's own
        // per-actor Cache::lock() construction.
        $limit = max(0, (int) config('kb.wiki_export.create_requests_per_hour', 10));
        $limiterKey = 'kb-wiki-export-create:'.$tenants->current().':'.$user->id;
        $rateLimitError = Cache::lock("kb-wiki-export-create-lock:{$limiterKey}", 10)->block(5, function () use ($limit, $limiterKey): ?string {
            if ($limit > 0 && RateLimiter::tooManyAttempts($limiterKey, $limit)) {
                return "Export-request rate limit exceeded: {$limit} per hour.";
            }
            if ($limit > 0) {
                RateLimiter::hit($limiterKey, 3600);
            }

            return null;
        });
        if ($rateLimitError !== null) {
            return Response::error($rateLimitError);
        }

        try {
            $exportRequest = $exports->requestExport(
                $tenants->current(),
                $projectKey,
                $user,
                [
                    'format' => $request->get('format', 'llm-wiki'),
                    'include_images' => (bool) $request->get('include_images', false),
                ],
            );
        } catch (\InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        }

        return Response::json([
            'id' => $exportRequest->id,
            'status' => $exportRequest->status,
            'project_key' => $exportRequest->project_key,
        ]);
    }
}
