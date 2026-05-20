<?php

declare(strict_types=1);

namespace App\Mcp\Methods;

use App\Models\KbCollection;
use App\Models\KbCollectionMember;
use App\Models\KnowledgeDocument;
use App\Support\TenantContext;
use Laravel\Mcp\Server\Contracts\Method;
use Laravel\Mcp\Server\Exceptions\JsonRpcException;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Server\Transport\JsonRpcRequest;
use Laravel\Mcp\Server\Transport\JsonRpcResponse;

final class ReadCollectionResource implements Method
{
    public function handle(JsonRpcRequest $request, ServerContext $context): JsonRpcResponse
    {
        $uri = (string) $request->get('uri');
        $tenantId = app(TenantContext::class)->current();

        if (! preg_match('#^collection://([^/]+)/(\d+)$#', $uri, $matches)) {
            throw new JsonRpcException('Invalid collection URI.', -32002, $request->id);
        }

        $uriTenant = $matches[1];
        $collectionId = (int) $matches[2];
        if ($uriTenant !== $tenantId) {
            throw new JsonRpcException('Collection URI tenant mismatch.', -32002, $request->id);
        }

        $collection = KbCollection::query()
            ->forTenant($tenantId)
            ->find($collectionId);

        if ($collection === null) {
            throw new JsonRpcException('Collection not found.', -32002, $request->id);
        }

        $members = KbCollectionMember::query()
            ->forTenant($tenantId)
            ->where('collection_id', $collection->id)
            ->where('manually_excluded', false)
            ->orderByDesc('score')
            ->orderByDesc('created_at')
            ->get()
            ->map(function (KbCollectionMember $member) use ($tenantId): array {
                $doc = KnowledgeDocument::query()
                    ->forTenant($tenantId)
                    ->find($member->knowledge_document_id);

                return [
                    'knowledge_document_id' => $member->knowledge_document_id,
                    'reason' => $member->reason,
                    'score' => $member->score,
                    'document' => [
                        'id' => $doc?->id,
                        'title' => $doc?->title,
                        'doc_id' => $doc?->doc_id,
                        'slug' => $doc?->slug,
                        'project_key' => $doc?->project_key,
                        'source_path' => $doc?->source_path,
                    ],
                ];
            })
            ->values()
            ->all();

        $payload = [
            'tenant_id' => $tenantId,
            'collection' => [
                'id' => $collection->id,
                'slug' => $collection->slug,
                'name' => $collection->name,
                'description' => $collection->description,
                'visibility' => $collection->visibility,
                'threshold' => $collection->threshold,
            ],
            'members' => $members,
            'members_count' => count($members),
        ];

        return JsonRpcResponse::result($request->id, [
            'contents' => [[
                'uri' => $uri,
                'mimeType' => 'application/json',
                'text' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]],
        ]);
    }
}
