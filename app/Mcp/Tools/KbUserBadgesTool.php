<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Services\Engagement\GamificationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * v8.15/W5 — MCP read surface (R44) for a contributor's gamification badges:
 * the badge catalog with earned flag + progress toward each threshold. This is
 * the MCP twin of the `GET /api/me/badges` HTTP surface and the
 * `gamification:recompute` command — all three delegate to GamificationService.
 *
 * When gamification is disabled (KB_GAMIFICATION_ENABLED=false, R43) the tool
 * returns `enabled:false` with an empty roster. Tenant-scoped via
 * EnforceMcpScope (R30).
 */
#[Description("List a contributor's gamification badges within the current tenant: each badge with its earned flag and progress toward the threshold. Returns enabled:false when gamification is turned off.")]
#[IsReadOnly]
#[IsIdempotent]
class KbUserBadgesTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'user_id' => $schema->integer()
                ->description('The contributor user id whose badges to report.')
                ->required(),
        ];
    }

    public function handle(Request $request, GamificationService $gamification): Response
    {
        $userId = (int) $request->get('user_id');

        return Response::json($gamification->badgesFor($userId));
    }
}
