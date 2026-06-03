<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Services\Kb\Retrieval\RelatedGraphService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * v8.8/W6 — chat-side related-graph read API.
 *
 * Given the canonical doc slugs an answer cited, returns their 1-hop graph
 * neighbours so the chat UI can render a "Related" panel. Consumer-side
 * (same `auth:sanctum` + tenant group as `/api/kb/chat`); tenant + project
 * scoped inside the service (R30). Empty when graph expansion is off or the
 * project has no canonical graph.
 */
final class KbGraphController extends Controller
{
    public function __construct(private readonly RelatedGraphService $related) {}

    /**
     * GET /api/kb/related?project_key=X&slugs[]=a&slugs[]=b
     */
    public function related(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_key' => ['required', 'string', 'max:120'],
            'slugs' => ['required', 'array', 'min:1', 'max:20'],
            'slugs.*' => ['string', 'max:200'],
        ]);

        $related = $this->related->relatedTo(
            $validated['slugs'],
            (string) $validated['project_key'],
        );

        return response()->json([
            'related' => $related,
            'meta' => ['count' => count($related)],
        ]);
    }
}
