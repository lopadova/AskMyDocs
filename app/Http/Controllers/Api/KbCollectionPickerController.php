<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\KbCollection;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

final class KbCollectionPickerController extends Controller
{
    public function __invoke(TenantContext $tenants): JsonResponse
    {
        $tenantId = $tenants->current();

        $rows = KbCollection::query()
            ->forTenant($tenantId)
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json([
            'data' => $rows->map(fn (KbCollection $c) => [
                'id' => $c->id,
                'name' => $c->name,
            ])->values()->all(),
        ]);
    }
}
