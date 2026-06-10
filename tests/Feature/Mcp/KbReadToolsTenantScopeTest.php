<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Mcp\Tools\KbReadChunkTool;
use App\Mcp\Tools\KbReadDocumentTool;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use Tests\TestCase;

/**
 * Security review v8.8 — proves the read-by-id MCP tools
 * ({@see KbReadDocumentTool}, {@see KbReadChunkTool}) scope their lookup to
 * the MCP-resolved tenant, so a client bound to tenant A cannot read tenant
 * B's document body / chunk text by enumerating the global auto-increment id.
 *
 * Tests run unauthenticated on purpose: AccessScopeScope bypasses the
 * no-user path, so the ONLY thing standing between the caller and tenant B's
 * row is the forTenant() scope the fix added. If the scope regresses, the
 * cross-tenant assertions below flip from "not found" to a leak.
 */
final class KbReadToolsTenantScopeTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $tenants;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenants = $this->app->make(TenantContext::class);
    }

    protected function tearDown(): void
    {
        $this->tenants->reset();
        parent::tearDown();
    }

    public function test_read_document_tool_denies_cross_tenant_id(): void
    {
        $doc = $this->makeDocumentForTenant('tenant-b');

        // A client bound to tenant-a must NOT resolve tenant-b's document.
        $this->tenants->set('tenant-a');

        $this->expectException(ModelNotFoundException::class);
        (new KbReadDocumentTool())->handle(new Request(['document_id' => $doc->id]));
    }

    public function test_read_document_tool_returns_own_tenant_document(): void
    {
        $doc = $this->makeDocumentForTenant('tenant-b');

        $this->tenants->set('tenant-b');

        $response = (new KbReadDocumentTool())->handle(new Request(['document_id' => $doc->id]));

        // The own-tenant lookup succeeds (no exception) and surfaces the row.
        $this->assertStringContainsString($doc->title, $this->responseText($response));
    }

    public function test_read_chunk_tool_denies_cross_tenant_id(): void
    {
        $chunk = $this->makeChunkForTenant('tenant-b');

        $this->tenants->set('tenant-a');

        $this->expectException(ModelNotFoundException::class);
        (new KbReadChunkTool())->handle(new Request(['chunk_id' => $chunk->id]));
    }

    public function test_read_chunk_tool_returns_own_tenant_chunk(): void
    {
        $chunk = $this->makeChunkForTenant('tenant-b');

        $this->tenants->set('tenant-b');

        $response = (new KbReadChunkTool())->handle(new Request(['chunk_id' => $chunk->id]));

        $this->assertStringContainsString('secret chunk body', $this->responseText($response));
    }

    private function makeDocumentForTenant(string $tenantId): KnowledgeDocument
    {
        // BelongsToTenant auto-fills tenant_id from the active context on create.
        $this->tenants->set($tenantId);

        return KnowledgeDocument::create([
            'project_key' => 'demo',
            'source_type' => 'markdown',
            'title' => 'Tenant '.$tenantId.' secret doc',
            'source_path' => 'docs/'.$tenantId.'.md',
            'mime_type' => 'text/markdown',
            'language' => 'it',
            'access_scope' => 'internal',
            'status' => 'active',
            'document_hash' => hash('sha256', $tenantId),
            'version_hash' => hash('sha256', $tenantId),
            'metadata' => [],
            'indexed_at' => now(),
        ]);
    }

    private function makeChunkForTenant(string $tenantId): KnowledgeChunk
    {
        $doc = $this->makeDocumentForTenant($tenantId);

        return KnowledgeChunk::create([
            'knowledge_document_id' => $doc->id,
            'project_key' => $doc->project_key,
            'chunk_order' => 0,
            'chunk_hash' => hash('sha256', 'chunk-'.$tenantId),
            'heading_path' => null,
            'chunk_text' => 'secret chunk body',
            'metadata' => [],
        ]);
    }

    private function responseText(mixed $response): string
    {
        return (string) $response->content();
    }
}
