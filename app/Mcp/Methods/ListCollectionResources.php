<?php

declare(strict_types=1);

namespace App\Mcp\Methods;

use App\Models\KbCollection;
use App\Support\TenantContext;
use Laravel\Mcp\Server\Contracts\Method;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Server\Transport\JsonRpcRequest;
use Laravel\Mcp\Server\Transport\JsonRpcResponse;

final class ListCollectionResources implements Method
{
    public function handle(JsonRpcRequest $request, ServerContext $context): JsonRpcResponse
    {
        $tenantId = app(TenantContext::class)->current();

        $resources = KbCollection::query()
            ->forTenant($tenantId)
            ->orderBy('name')
            ->get(['id', 'name', 'description'])
            ->map(fn (KbCollection $collection): array => [
                'uri' => "collection://{$tenantId}/{$collection->id}",
                'name' => $collection->name,
                'title' => $collection->name,
                'description' => $collection->description ?? "Living collection {$collection->name}",
                'mimeType' => 'application/json',
            ])
            ->values()
            ->all();

        return JsonRpcResponse::result($request->id, ['resources' => $resources]);
    }
}
