<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\KbChunkFeedback;
use App\Models\KnowledgeChunk;
use App\Models\ProjectMembership;
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

        // F1 (deep-review v8.0.1) — three-layer access gate.
        //
        //   Layer 1: AccessScopeScope (global scope on
        //   KnowledgeDocument) already filters the eager-loaded
        //   document for users without `kb.read.any`. Cross-project
        //   docs come back as `$chunk->document === null`, which we
        //   answer with 404 — by design, to avoid leaking the
        //   existence of a doc the user has no project membership for.
        //
        //   Layer 2 (R30 — added on PR #223 iter-2): an explicit
        //   tenant-scoped `project_memberships` check. The existing
        //   `User::hasDocumentAccess()` flow walks
        //   `allowedProjects()` → `projectMemberships()` WITHOUT a
        //   `tenant_id` predicate; that's correct for the read path
        //   (the global scope already constrains by tenant via
        //   `forTenant`), but on this WRITE path a chunk in tenant B
        //   could be accepted because the user has a same-named
        //   project membership in tenant A. We refuse with 403 unless
        //   a membership row for THIS tenant + THIS project exists.
        //   `kb.read.any` holders bypass the check (matches the
        //   wildcard semantics in `allowedProjects()`).
        //
        //   Layer 3: `hasDocumentAccess()` runs the full ACL +
        //   scope_allowlist evaluation against any document the prior
        //   layers admitted. This catches deny ACL rows on a doc the
        //   user otherwise can read + scope_allowlist tag/folder
        //   mismatch. See KbChunkFeedbackApiTest for every path.
        $document = $chunk->document;
        if ($document === null) {
            abort(404);
        }

        if (! $user->can('kb.read.any')) {
            $hasMembership = ProjectMembership::query()
                ->where('tenant_id', $tenantId)
                ->where('user_id', $user->id)
                ->where('project_key', $document->project_key)
                ->exists();
            if (! $hasMembership) {
                abort(403);
            }
        }

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
