<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use Illuminate\Routing\Controller;
use App\Services\Kb\Knowledge\KnowledgeDocumentContext;
use App\Services\Kb\Knowledge\KnowledgeReadService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class KnowledgeEntityController extends Controller
{
    public function __construct(private readonly TenantContext $tenant, private readonly KnowledgeReadService $reader) {}

    public function index(Request $request): JsonResponse
    {
        $d = $request->validate(['project_key' => 'required|string|max:128|not_in:*', 'q' => 'nullable|string|max:2000', 'namespace' => 'nullable|string|max:64', 'limit' => 'sometimes|integer|min:1|max:100']);
        return response()->json($this->reader->search($this->tenant->current(), $d['project_key'], $d['q'] ?? '', $request->user(), $d['namespace'] ?? null, $d['limit'] ?? 20));
    }

    public function show(Request $request, int $entity): JsonResponse
    {
        $d = $request->validate(['project_key' => 'required|string|max:128|not_in:*', 'cursor' => 'nullable|integer|min:0', 'limit' => 'sometimes|integer|min:1|max:100']);
        $result = $this->reader->context($this->tenant->current(), $d['project_key'], $entity, $request->user(), $d['cursor'] ?? null, $d['limit'] ?? 30);
        abort_if($result === null, 404);
        return response()->json($result);
    }

    public function document(Request $request, int $document, KnowledgeDocumentContext $context): JsonResponse
    {
        $d = $request->validate(['project_key' => 'required|string|max:128|not_in:*', 'cursor' => 'sometimes|integer|min:0', 'limit' => 'sometimes|integer|min:1|max:3']);
        $result = $context->read($this->tenant->current(), $d['project_key'], $document, $request->user(), $d['cursor'] ?? 0, $d['limit'] ?? 1);
        abort_if($result === null, 404);
        return response()->json($result);
    }
}
