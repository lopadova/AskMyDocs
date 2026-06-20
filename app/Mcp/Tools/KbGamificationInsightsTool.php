<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\KbGamificationInsight;
use App\Services\Engagement\GamificationInsightsService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * v8.18/W4 — MCP read surface (R44, the third surface) for the AI gamification
 * insights: a contributor coaching card, a project health narrative, or the
 * tenant executive narrative. The MCP twin of `GET /api/me/coaching` +
 * `GET /api/admin/engagement/insights` and the `gamification:narrate` command —
 * all delegate to {@see GamificationInsightsService}.
 *
 * Tenant-scoped via EnforceMcpScope (R30). When gamification is disabled
 * (KB_GAMIFICATION_ENABLED=false, R43) or the scope has not been computed yet,
 * the tool returns `available:false` rather than throwing.
 */
#[Description('Return the AI gamification insight for a scope within the current tenant: scope=user (with an id = user id) yields a coaching card; scope=project (id = project_key) yields a project health narrative; scope=tenant yields the organisation narrative. Returns available:false when gamification is off or the scope has no computed insight yet.')]
#[IsReadOnly]
#[IsIdempotent]
class KbGamificationInsightsTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'scope' => $schema->string()
                ->description('One of: user | project | tenant.')
                ->required(),
            'id' => $schema->string()
                ->description('The user id (scope=user) or project_key (scope=project); ignored for scope=tenant.'),
            'period' => $schema->string()
                ->description('Optional period label (e.g. 2026-W25); defaults to the latest computed.'),
        ];
    }

    public function handle(Request $request, GamificationInsightsService $insights): Response
    {
        $scope = (string) $request->get('scope');
        $id = trim((string) ($request->get('id') ?? ''));
        $period = trim((string) ($request->get('period') ?? '')) ?: null;

        $insight = match ($scope) {
            // Require a strictly-numeric id for the user scope — `(int) "12abc"`
            // would silently coerce to 12 and return the WRONG user's card.
            KbGamificationInsight::SCOPE_USER => ctype_digit($id) ? $insights->forUser((int) $id, $period) : null,
            KbGamificationInsight::SCOPE_PROJECT => $id === '' ? null : $insights->forScope(KbGamificationInsight::SCOPE_PROJECT, $id, $period),
            KbGamificationInsight::SCOPE_TENANT => $insights->forScope(KbGamificationInsight::SCOPE_TENANT, '', $period),
            default => null,
        };

        return Response::json([
            'available' => $insight !== null,
            'scope' => $scope,
            'insight' => $insight,
        ]);
    }
}
