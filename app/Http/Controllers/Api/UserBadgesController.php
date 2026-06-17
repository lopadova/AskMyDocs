<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Services\Engagement\GamificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * v8.15/W5 — the authenticated user's gamification badges. The HTTP leg of the
 * tri-surface (R44): the `gamification:recompute` command awards, the
 * `KbUserBadgesTool` MCP tool reads for any user id, and this controller reads
 * for the caller — all three delegate to {@see GamificationService}.
 *
 * `/api/me/badges` — the badge catalog with earned/progress for the caller.
 * Read-only (awarding is the gamification:recompute command's job; the earned
 * flag also reflects current thresholds live). When gamification is disabled
 * returns `enabled:false` + an empty list so the FE hides the section. R30 via
 * the service; auth:sanctum.
 */
final class UserBadgesController extends Controller
{
    public function __construct(private readonly GamificationService $gamification)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->gamification->badgesFor((int) $request->user()->getKey()));
    }
}
