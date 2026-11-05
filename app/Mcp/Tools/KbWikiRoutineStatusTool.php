<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Routines\WikiRoutineService;
use App\Support\TenantContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * v8.39/W5 (ADR 0033 §8) — read-only MCP status surface for the Auto-Wiki
 * maintenance routine, delegating to {@see WikiRoutineService} — the same
 * core `kb:wiki-routine status` and `GET /api/admin/kb/wiki-routine` use.
 *
 * There is deliberately NO MCP `run` tool (ADR 0033 §8, documented R44
 * exception): starting the routine spends and rewrites `auto`-tier pages,
 * the same posture W1's OCR re-run and W3's review-approval already took —
 * an agent may see the routine's state, never start it.
 * `KnowledgeBaseServerRegistrationTest` derives the tool roster from the
 * files present in `app/Mcp/Tools/`, so this absence is provable, not
 * merely asserted here.
 */
#[Description('Report the Auto-Wiki maintenance routine\'s status: whether one is provisioned for this tenant, its lifecycle status, next scheduled run, and the last run\'s outcome/message. Read-only. Answers {disabled: true} when the routine is off (KB_WIKI_ROUTINE_ENABLED).')]
#[IsReadOnly]
#[IsIdempotent]
class KbWikiRoutineStatusTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request, WikiRoutineService $routines, TenantContext $tenants): Response
    {
        if (! $routines->enabled()) {
            return Response::json(['disabled' => true, 'flag' => 'KB_WIKI_ROUTINE_ENABLED']);
        }

        return Response::json($routines->status($tenants->current()));
    }
}
