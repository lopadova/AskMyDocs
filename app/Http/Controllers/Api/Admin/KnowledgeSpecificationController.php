<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use Illuminate\Routing\Controller;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeSpecification;
use App\Services\Kb\Knowledge\KnowledgeEnrichment;
use App\Services\Kb\Knowledge\KnowledgeReprocessing;
use App\Services\Kb\Knowledge\KnowledgeSpecificationService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class KnowledgeSpecificationController extends Controller
{
    public function __construct(private readonly TenantContext $tenant, private readonly KnowledgeSpecificationService $specifications) {}

    private function project(Request $request): string
    {
        return $request->validate(['project_key' => 'required|string|max:128'])['project_key'];
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->specifications->versions($this->tenant->current(), $this->project($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['project_key' => 'required|string|max:128', 'description' => 'present|string|max:16000', 'blocks' => 'required|array']);
        return response()->json(['revision' => $this->specifications->draft($this->tenant->current(), $data['project_key'], $data['description'], $data['blocks'], (string) $request->user()->id)], 201);
    }

    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate(['description' => 'required|string|max:16000']);
        return response()->json(['blocks' => $this->specifications->generate($data['description'])]);
    }

    public function activate(Request $request, int $revision): JsonResponse
    {
        return response()->json(['effective' => $this->specifications->activate($this->tenant->current(), $this->project($request), $revision)]);
    }

    public function disable(Request $request): JsonResponse
    {
        $this->specifications->disable($this->tenant->current(), $this->project($request));
        return response()->json(['enabled' => false]);
    }

    public function preview(Request $request, int $revision, KnowledgeEnrichment $enrichment): JsonResponse
    {
        $data = $request->validate(['project_key' => 'required|string|max:128|not_in:*', 'document_ids' => 'required|array|min:1|max:5', 'document_ids.*' => 'integer|min:1']);
        $spec = KnowledgeSpecification::query()->forTenant($this->tenant->current())->whereIn('project_key', [$data['project_key'], '*'])->findOrFail($revision);
        $contract = $this->specifications->effective($this->tenant->current(), $data['project_key'], $spec);
        $results = [];
        foreach ($data['document_ids'] as $id) {
            $doc = KnowledgeDocument::query()->forTenant($this->tenant->current())->where('project_key', $data['project_key'])->where('status', 'active')->findOrFail($id);
            abort_unless($request->user()->hasDocumentAccess($doc, 'view'), 404);
            $results[] = $enrichment->preview($doc, $contract);
        }
        return response()->json(['preview' => $results, 'persisted' => false]);
    }

    public function reprocess(Request $request, KnowledgeReprocessing $reprocessing): JsonResponse
    {
        $project = $this->project($request);
        abort_if($this->specifications->effective($this->tenant->current(), $project) === null, 422, 'Activate a project specification first.');
        return response()->json($reprocessing->select($this->tenant->current(), $project, $request->input('selection', []), $request->user()), 202);
    }

    public function runs(Request $request, KnowledgeReprocessing $reprocessing): JsonResponse
    {
        $runs = $reprocessing->runs($this->tenant->current(), $this->project($request));
        // Admin roles still honor document-specific restrictions.
        $allowed = KnowledgeDocument::query()->forTenant($this->tenant->current())->whereIn('id', array_column($runs, 'knowledge_document_id'))->get()
            ->filter(fn ($d) => $request->user()->hasDocumentAccess($d, 'view'))->pluck('id')->all();
        return response()->json(['runs' => array_values(array_filter($runs, fn ($r) => in_array($r['knowledge_document_id'], $allowed, true)))]);
    }
}
