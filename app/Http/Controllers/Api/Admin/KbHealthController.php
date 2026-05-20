<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Models\KbCanonicalHealthSnapshot;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class KbHealthController extends Controller
{
    public function index(Request $request, TenantContext $tenants): JsonResponse
    {
        $validated = $request->validate([
            'project' => ['nullable', 'string', 'max:100'],
            'min_score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $tenantId = $tenants->current();
        $project = isset($validated['project']) ? trim((string) $validated['project']) : null;
        $minScore = (int) ($validated['min_score'] ?? 0);
        $limit = (int) ($validated['limit'] ?? 100);

        $q = KbCanonicalHealthSnapshot::query()
            ->forTenant($tenantId)
            ->when($project !== null && $project !== '', fn ($b) => $b->where('project_key', $project))
            ->where('health_score', '>=', $minScore)
            ->orderByDesc('health_score')
            ->orderBy('id');

        $rows = $q->limit($limit)->get([
            'knowledge_document_id',
            'project_key',
            'doc_slug',
            'health_score',
            'factors',
            'computed_at',
        ]);

        $stats = KbCanonicalHealthSnapshot::query()
            ->forTenant($tenantId)
            ->when($project !== null && $project !== '', fn ($b) => $b->where('project_key', $project));

        return response()->json([
            'data' => $rows->map(fn ($r) => [
                'knowledge_document_id' => (int) $r->knowledge_document_id,
                'project_key' => (string) $r->project_key,
                'doc_slug' => $r->doc_slug,
                'health_score' => (int) $r->health_score,
                'factors' => (array) ($r->factors ?? []),
                'computed_at' => optional($r->computed_at)?->toIso8601String(),
            ])->values()->all(),
            'meta' => [
                'tenant_id' => $tenantId,
                'project' => $project,
                'count' => $rows->count(),
                'total' => (int) $stats->count(),
                'avg_score' => (float) ($stats->avg('health_score') ?? 0),
                'max_score' => (int) ($stats->max('health_score') ?? 0),
            ],
            'weights' => (array) config('askmydocs.kb_health.weights', []),
            'threshold_event_score' => (int) config('askmydocs.kb_health.threshold_event_score', 70),
        ]);
    }
}

