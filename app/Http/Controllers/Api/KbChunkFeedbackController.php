<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\KbChunkFeedback;
use App\Models\KnowledgeChunk;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

final class KbChunkFeedbackController extends Controller
{
    public function __invoke(Request $request, TenantContext $tenants): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            abort(401);
        }

        $validated = $request->validate([
            'chunk_id' => ['required', 'integer'],
            'signal' => ['required', 'string', Rule::in(KbChunkFeedback::signals())],
        ]);

        $tenantId = $tenants->current();
        $chunk = KnowledgeChunk::query()
            ->forTenant($tenantId)
            ->find($validated['chunk_id']);

        if ($chunk === null) {
            abort(404);
        }

        $row = KbChunkFeedback::query()->updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'user_id' => (int) $user->id,
                'knowledge_chunk_id' => (int) $chunk->id,
            ],
            [
                'signal' => $validated['signal'],
            ],
        );

        return response()->json([
            'chunk_id' => (int) $row->knowledge_chunk_id,
            'signal' => (string) $row->signal,
        ]);
    }
}

