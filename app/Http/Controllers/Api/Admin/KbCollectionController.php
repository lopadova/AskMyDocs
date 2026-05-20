<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Models\KbCollection;
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

