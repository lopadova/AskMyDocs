<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Services\Engagement\GamificationInsightsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * v8.18/W4 — the AI gamification coaching card for the AUTHENTICATED CALLER.
 *
 * Strictly self-scoped: a user can only ever read their OWN card (the user id is
 * taken from the session, never from input) — no way to read another user's
 * coaching. R44 HTTP surface over {@see GamificationInsightsService}. R14/R43:
 * when gamification is disabled or nothing has been computed yet, returns 200
 * with `available:false` (a clean empty state, never a 500).
 */
class UserCoachingController extends Controller
{
    public function show(Request $request, GamificationInsightsService $insights): JsonResponse
    {
        $card = $insights->forUser((int) $request->user()->getKey());

        return response()->json([
            'available' => $card !== null,
            'insight' => $card,
        ]);
    }
}
