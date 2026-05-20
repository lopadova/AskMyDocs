<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Models\KbCollection;
use App\Models\KbCollectionMember;
use App\Models\KnowledgeDocument;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class KbCollectionController extends Controller
{
    public function index(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->current();
        $query = KbCollection::query()->forTenant($tenantId)->orderBy('name');

        $search = trim((string) $request->query('q', ''));
        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', '%' . $search . '%')
                    ->orWhere('slug', 'like', '%' . $search . '%');
            });
        }

        return response()->json([
            'data' => $query->get()->map(fn (KbCollection $c): array => $this->serialize($c))->all(),
        ]);
    }

    public function store(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->current();
        $validated = $request->validate($this->rules($tenantId));
        $validated['tenant_id'] = $tenantId;

        $collection = KbCollection::query()->create($validated);

        return response()->json(['data' => $this->serialize($collection)], 201);
    }

    public function show(int $id, TenantContext $tenantContext): JsonResponse
    {
        return response()->json([
            'data' => $this->serialize($this->findForTenantOr404($id, $tenantContext->current())),
        ]);
    }

    public function update(Request $request, int $id, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->current();
        $collection = $this->findForTenantOr404($id, $tenantId);
        $validated = $request->validate($this->rules($tenantId, $collection->id));

        $collection->fill($validated);
        $collection->save();

        return response()->json(['data' => $this->serialize($collection)]);
    }

    public function destroy(int $id, TenantContext $tenantContext): JsonResponse
    {
        $this->findForTenantOr404($id, $tenantContext->current())->delete();

        return response()->json(null, 204);
    }

    public function members(int $id, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->current();
        $collection = $this->findForTenantOr404($id, $tenantId);

        $members = KbCollectionMember::query()
            ->forTenant($tenantId)
            ->where('collection_id', $collection->id)
            ->orderByDesc('id')
            ->get()
            ->map(function (KbCollectionMember $member) use ($tenantId): array {
                $document = KnowledgeDocument::query()
                    ->withoutGlobalScopes()
                    ->forTenant($tenantId)
                    ->where('id', $member->knowledge_document_id)
                    ->first();

                return [
                    'id' => $member->id,
                    'knowledge_document_id' => $member->knowledge_document_id,
                    'reason' => $member->reason,
                    'semantic_score' => $member->semantic_score,
                    'manually_excluded' => (bool) $member->manually_excluded,
                    'created_at' => optional($member->created_at)?->toISOString(),
                    'document' => $document === null ? null : [
                        'id' => $document->id,
                        'project_key' => $document->project_key,
                        'slug' => $document->slug,
                        'title' => $document->title,
                    ],
                ];
            })
            ->all();

        return response()->json(['data' => $members]);
    }

    public function addMember(Request $request, int $id, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->current();
        $collection = $this->findForTenantOr404($id, $tenantId);
        $validated = $request->validate([
            'knowledge_document_id' => ['required', 'integer', 'min:1'],
        ]);

        $document = KnowledgeDocument::query()
            ->forTenant($tenantId)
            ->find((int) $validated['knowledge_document_id']);
        if ($document === null) {
            throw new NotFoundHttpException('Knowledge document not found.');
        }

        $member = KbCollectionMember::query()->updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'collection_id' => $collection->id,
                'knowledge_document_id' => $document->id,
            ],
            [
                'reason' => 'manual',
                'semantic_score' => null,
                'manually_excluded' => false,
            ],
        );

        return response()->json([
            'data' => [
                'collection_id' => $member->collection_id,
                'knowledge_document_id' => $member->knowledge_document_id,
                'reason' => $member->reason,
                'manually_excluded' => $member->manually_excluded,
            ],
        ], 201);
    }

    public function removeMember(int $id, int $documentId, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->current();
        $collection = $this->findForTenantOr404($id, $tenantId);

        $document = KnowledgeDocument::query()
            ->forTenant($tenantId)
            ->find($documentId);
        if ($document === null) {
            throw new NotFoundHttpException('Knowledge document not found.');
        }

        KbCollectionMember::query()->updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'collection_id' => $collection->id,
                'knowledge_document_id' => $document->id,
            ],
            [
                'reason' => 'manual',
                'semantic_score' => null,
                'manually_excluded' => true,
            ],
        );

        return response()->json(null, 204);
    }

    private function rules(string $tenantId, ?int $ignoreId = null): array
    {
        return [
            'slug' => [
                'required',
                'string',
                'max:120',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('kb_collections', 'slug')
                    ->where(fn ($q) => $q->where('tenant_id', $tenantId))
                    ->ignore($ignoreId),
            ],
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string'],
            'visibility' => ['required', Rule::in(['private', 'tenant'])],
            'criteria' => ['nullable', 'array'],
            'semantic_prompt' => ['nullable', 'string'],
            'threshold' => ['required', 'numeric', 'min:0', 'max:1'],
        ];
    }

    private function findForTenantOr404(int $id, string $tenantId): KbCollection
    {
        $collection = KbCollection::query()->forTenant($tenantId)->find($id);
        if ($collection === null) {
            throw new NotFoundHttpException('Collection not found.');
        }

        return $collection;
    }

    private function serialize(KbCollection $collection): array
    {
        return [
            'id' => $collection->id,
            'tenant_id' => $collection->tenant_id,
            'slug' => $collection->slug,
            'name' => $collection->name,
            'description' => $collection->description,
            'visibility' => $collection->visibility,
            'criteria' => $collection->criteria ?? [],
            'semantic_prompt' => $collection->semantic_prompt,
            'threshold' => (float) $collection->threshold,
            'created_at' => optional($collection->created_at)?->toISOString(),
            'updated_at' => optional($collection->updated_at)?->toISOString(),
        ];
    }
}
