<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Models\UnmappedSourcePrincipal;
use App\Services\Kb\Access\SourceAclTriageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/**
 * v8.33 / ADR 0028 phase 2 — HTTP surface (R44) for the source-ACL triage
 * queue, over the same core as `kb:source-acl` and the MCP read tool.
 *
 * The mutation here records a DECISION about a question, not a permission:
 * marking an entry ignored says "no internal subject should be granted for
 * this principal". Granting is a separate, deliberate act through the
 * ordinary ACL surface, which is what keeps a one-click triage screen from
 * quietly becoming a one-click access-granting screen.
 */
class KbSourceAclController extends Controller
{
    public function index(Request $request, SourceAclTriageService $triage): JsonResponse
    {
        $validated = $request->validate([
            'project_key' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(UnmappedSourcePrincipal::STATUSES)],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $status = $validated['status'] ?? UnmappedSourcePrincipal::STATUS_PENDING;

        $queue = $triage->queue(
            $validated['project_key'] ?? null,
            $status,
            (int) ($validated['limit'] ?? 50),
        );

        return response()->json([
            'summary' => $triage->summary(),
            'status' => $status,
            'data' => $queue->map(static fn (UnmappedSourcePrincipal $row): array => [
                'id' => $row->id,
                'document_id' => $row->knowledge_document_id,
                'document_title' => $row->document?->title,
                'source_path' => $row->document?->source_path,
                'project_key' => $row->project_key,
                'principal_type' => $row->principal_type,
                'principal' => $row->principal_external_id,
                'effect' => $row->effect,
                'status' => $row->status,
                'first_seen_at' => $row->first_seen_at?->toIso8601String(),
                'last_seen_at' => $row->last_seen_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    /**
     * Record a decision about one entry.
     *
     * A row belonging to another tenant answers 404 rather than 403, so the
     * response does not confirm that the id exists (R30).
     */
    public function update(
        Request $request,
        int $principal,
        SourceAclTriageService $triage,
    ): JsonResponse {
        $validated = $request->validate([
            'status' => ['required', Rule::in(UnmappedSourcePrincipal::STATUSES)],
        ]);

        $row = $triage->setStatus($principal, $validated['status']);

        if ($row === null) {
            return response()->json([
                'message' => 'Triage entry not found.',
            ], 404);
        }

        return response()->json([
            'data' => [
                'id' => $row->id,
                'status' => $row->status,
            ],
        ]);
    }
}
