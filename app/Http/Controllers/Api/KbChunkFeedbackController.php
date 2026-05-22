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
            ->with('document')
            ->find($validated['chunk_id']);

        if ($chunk === null) {
            abort(404);
        }

        $document = $chunk->document;
        if ($document === null) {
            // Soft-deleted or missing document — the chunk has no
            // canonical anchor for an access decision.
            abort(404);
        }

        // F1 (deep-review v8.0.1) — explicit project + ACL gate. A
        // chunk sharing the active tenant does NOT imply the user can
        // access its document; hasDocumentAccess() walks the full
        // permission + ACL + project_memberships + scope_allowlist
        // evaluation that KbSearchService also uses.
        if (! $user->hasDocumentAccess($document)) {
            abort(403);
        }

        $now = now();
        // F2 (deep-review v8.0.1) — atomic upsert against the
        // (tenant_id, user_id, knowledge_chunk_id) UNIQUE. Replaces
        // the prior updateOrCreate (select-then-write) that raced on
        // concurrent double-clicks / client retries and produced
        // duplicate-key 500s.
        KbChunkFeedback::query()->upsert(
            [[
                'tenant_id' => $tenantId,
                'user_id' => (int) $user->id,
                'knowledge_chunk_id' => (int) $chunk->id,
                'signal' => $validated['signal'],
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['tenant_id', 'user_id', 'knowledge_chunk_id'],
            ['signal', 'updated_at'],
        );

        return response()->json([
            'chunk_id' => (int) $chunk->id,
            'signal' => (string) $validated['signal'],
        ]);
    }
}
