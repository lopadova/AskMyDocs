<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Models\KbDocAnalysis;
use App\Models\KnowledgeDocument;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * v8.7/W3–W4 — read-only admin listing of AI document-change analyses.
 *
 * Surfaces the `kb_doc_analyses` rows produced by `AnalyzeDocumentChangeJob`
 * so reviewers can see, per document change, the enhancement suggestions +
 * cross-references + impacted-doc flags. Read-only — analyses are produced
 * by the async job, never created via this controller (suggest-only,
 * ADR 0003).
 *
 * Auth: `auth:sanctum` + `role:admin|super-admin` (route group). R30 — every
 * read is tenant-scoped via `forTenant($this->tenant->current())`.
 */
final class KbDocAnalysisController extends Controller
{
    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * GET /api/admin/kb/analyses?project_keys[]=...&status=completed&per_page=20
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_keys' => ['nullable', 'array'],
            'project_keys.*' => ['string', 'max:120'],
            'status' => ['nullable', 'string', 'in:completed,failed'],
            'document_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = KbDocAnalysis::query()
            ->forTenant($this->tenant->current())
            ->orderByDesc('created_at');

        if (! empty($validated['project_keys'])) {
            $query->whereIn('project_key', $validated['project_keys']);
        }
        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (! empty($validated['document_id'])) {
            $query->where('knowledge_document_id', $validated['document_id']);
        }

        $perPage = (int) ($validated['per_page'] ?? 20);
        $page = $query->paginate($perPage);

        // Resolve doc titles in one tenant-scoped query rather than a JOIN.
        // A JOIN to knowledge_documents would force qualifying every shared
        // column (id / project_key / created_at / title all collide); a
        // separate id→title map keeps the query simple and leaves the R30
        // `forTenant(` marker explicit. (`forTenant` itself qualifies
        // `<table>.tenant_id`, so tenant scoping is JOIN-safe regardless.)
        $docIds = collect($page->items())->pluck('knowledge_document_id')->filter()->unique()->all();
        $titles = $docIds === []
            ? collect()
            : KnowledgeDocument::query()
                ->withTrashed()
                ->forTenant($this->tenant->current())
                ->whereIn('id', $docIds)
                ->pluck('title', 'id');

        $data = collect($page->items())->map(function (KbDocAnalysis $row) use ($titles): array {
            return [
                'id' => $row->id,
                'project_key' => $row->project_key,
                'knowledge_document_id' => $row->knowledge_document_id,
                'document_title' => $titles[$row->knowledge_document_id] ?? null,
                'doc_slug' => $row->doc_slug,
                'trigger' => $row->trigger,
                'analysis_json' => $row->analysis_json,
                'suggestion_count' => $row->suggestion_count,
                'impacted_count' => $row->impacted_count,
                'status' => $row->status,
                'provider' => $row->provider,
                'model' => $row->model,
                'error' => $row->error,
                'created_at' => $row->created_at,
            ];
        })->all();

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }
}
