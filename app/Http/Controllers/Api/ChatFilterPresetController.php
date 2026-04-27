<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\ChatFilterPreset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * RESTful CRUD for `ChatFilterPreset` (T2.9 — backend slice).
 *
 * Per-user authorization: every read/write is scoped to
 * `auth()->id()` via a `where('user_id', $userId)` predicate, so
 * user A literally cannot see, modify, or delete user B's presets
 * — the row appears as "not found" (404) instead of a permissions
 * error (403). This avoids leaking the existence of other users'
 * presets and keeps the API surface tenant-clean.
 *
 * Auth: Sanctum bearer token (same as /api/kb/chat).
 *
 * The FE consumer (FilterBar dropdown — deferred T2.9-FE) calls:
 *  - GET    /api/chat-filter-presets          → list user's presets
 *  - POST   /api/chat-filter-presets          → create
 *  - GET    /api/chat-filter-presets/{id}     → show one
 *  - PUT    /api/chat-filter-presets/{id}     → update name+filters
 *  - DELETE /api/chat-filter-presets/{id}     → delete
 */
final class ChatFilterPresetController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;

        $presets = ChatFilterPreset::query()
            ->where('user_id', $userId)
            ->orderBy('name')
            ->get(['id', 'name', 'filters', 'created_at', 'updated_at']);

        return response()->json(['data' => $presets]);
    }

    public function store(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'min:1',
                'max:120',
                // Per-user uniqueness — prevent overwriting an existing
                // preset by accident. Update via PUT /id is the explicit
                // path for renaming/replacing.
                Rule::unique('chat_filter_presets', 'name')
                    ->where(fn ($q) => $q->where('user_id', $userId)),
            ],
            'filters' => ['required', 'array'],
        ]);

        $preset = ChatFilterPreset::create([
            'user_id' => $userId,
            'name' => $validated['name'],
            'filters' => $validated['filters'],
        ]);

        return response()->json([
            'data' => $preset->only(['id', 'name', 'filters', 'created_at', 'updated_at']),
        ], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $preset = $this->findOwnedOr404($request, $id);

        return response()->json([
            'data' => $preset->only(['id', 'name', 'filters', 'created_at', 'updated_at']),
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $preset = $this->findOwnedOr404($request, $id);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'min:1',
                'max:120',
                Rule::unique('chat_filter_presets', 'name')
                    ->where(fn ($q) => $q->where('user_id', $userId))
                    ->ignore($preset->id),
            ],
            'filters' => ['required', 'array'],
        ]);

        $preset->update($validated);

        return response()->json([
            'data' => $preset->only(['id', 'name', 'filters', 'created_at', 'updated_at']),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $preset = $this->findOwnedOr404($request, $id);
        $preset->delete();

        return response()->json(null, 204);
    }

    /**
     * Looks up a preset constrained to the authenticated user. Other
     * users' presets surface as 404 (not 403) so the API doesn't leak
     * the existence of presets owned by anyone else. NotFoundHttpException
     * is preferred over ValidationException because it carries the
     * correct semantic status (404, not 422) and Laravel's exception
     * handler renders it consistently as `{"message": "..."}` for
     * JSON requests.
     */
    private function findOwnedOr404(Request $request, int $id): ChatFilterPreset
    {
        $userId = (int) $request->user()->id;

        $preset = ChatFilterPreset::query()
            ->where('user_id', $userId)
            ->find($id);

        if ($preset === null) {
            throw new NotFoundHttpException('Preset not found.');
        }

        return $preset;
    }
}
