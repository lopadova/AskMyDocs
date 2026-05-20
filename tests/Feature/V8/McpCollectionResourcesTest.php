<?php

declare(strict_types=1);

namespace Tests\Feature\V8;

use App\Mcp\Methods\ListCollectionResources;
use App\Mcp\Methods\ReadCollectionResource;
use App\Models\KbCollection;
use App\Models\KbCollectionMember;
use App\Models\KnowledgeDocument;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Server\Transport\JsonRpcRequest;
use Tests\TestCase;

final class McpCollectionResourcesTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_resources_returns_collection_uris_for_active_tenant(): void
    {
        app(TenantContext::class)->set('tenant-a');

        KbCollection::query()->create([
            'tenant_id' => 'tenant-a',
            'slug' => 'engineering',
            'name' => 'Engineering',
            'visibility' => 'private',
            'criteria' => [],
            'threshold' => 0.7,
        ]);
        KbCollection::query()->create([
            'tenant_id' => 'tenant-b',
            'slug' => 'other',
            'name' => 'Other',
            'visibility' => 'private',
            'criteria' => [],
            'threshold' => 0.7,
        ]);

        $method = new ListCollectionResources();
        $response = $method->handle(
            new JsonRpcRequest(1, 'resources/list', []),
            $this->context(),
        )->toArray();

        $resources = $response['result']['resources'];
        $this->assertCount(1, $resources);
        $this->assertSame('collection://tenant-a/1', $resources[0]['uri']);
        $this->assertSame('Engineering', $resources[0]['name']);
    }

    public function test_read_resource_returns_members_payload(): void
    {
        app(TenantContext::class)->set('tenant-a');

        $collection = KbCollection::query()->create([
            'tenant_id' => 'tenant-a',
            'slug' => 'engineering',
            'name' => 'Engineering',
            'visibility' => 'private',
            'criteria' => [],
            'threshold' => 0.75,
        ]);
        $doc = KnowledgeDocument::query()->create([
            'tenant_id' => 'tenant-a',
            'project_key' => 'engineering',
            'doc_id' => 'DOC-1',
            'slug' => 'doc-1',
            'title' => 'Doc 1',
            'content' => 'x',
            'source_type' => 'markdown',
            'source_path' => 'docs/doc-1.md',
            'version_hash' => sha1('doc-1'),
            'status' => 'active',
            'is_canonical' => false,
        ]);
        KbCollectionMember::query()->create([
            'tenant_id' => 'tenant-a',
            'collection_id' => $collection->id,
            'knowledge_document_id' => $doc->id,
            'reason' => 'semantic_match',
            'score' => 0.88,
            'manually_excluded' => false,
        ]);

        $method = new ReadCollectionResource();
        $response = $method->handle(
            new JsonRpcRequest(2, 'resources/read', ['uri' => "collection://tenant-a/{$collection->id}"]),
            $this->context(),
        )->toArray();

        $contents = $response['result']['contents'];
        $this->assertCount(1, $contents);
        $payload = json_decode($contents[0]['text'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($collection->id, $payload['collection']['id']);
        $this->assertSame(1, $payload['members_count']);
        $this->assertSame($doc->id, $payload['members'][0]['knowledge_document_id']);
    }

    private function context(): ServerContext
    {
        return new ServerContext(
            ['2025-11-25'],
            [],
            'test',
            '1.0.0',
            '',
            50,
            15,
            [],
            [],
            [],
        );
    }
}
