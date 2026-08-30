<?php

declare(strict_types=1);

namespace Tests\Feature\Kb;

use App\Ai\EmbeddingsResponse;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeDocumentAcl;
use App\Models\ProjectMembership;
use App\Models\UnmappedSourcePrincipal;
use App\Models\User;
use App\Services\Kb\DocumentIngestor;
use App\Services\Kb\EmbeddingCacheService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Padosoft\AskMyDocsConnectorBase\Access\SourceAccess;
use Padosoft\AskMyDocsConnectorBase\Access\SourcePrincipal;
use Tests\TestCase;

/**
 * ADR 0028 phase 2 — the permission list has to survive the trip through
 * ingestion, or none of the enforcement downstream ever fires.
 *
 * It travels in `$metadata` under a reserved key rather than as an argument
 * to `dispatchIngestion()`, because adding even an OPTIONAL parameter to that
 * contract is a breaking change for every host implementing it: PHP rejects
 * an implementation declaring fewer parameters than its interface, so hosts
 * would fatal at class-declaration time on upgrade. These tests pin the
 * channel that replaced it.
 */
final class DocumentIngestorSourceAclTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = app(TenantContext::class)->current();

        $cache = Mockery::mock(EmbeddingCacheService::class);
        $cache->shouldReceive('generate')->andReturnUsing(
            fn (array $texts) => new EmbeddingsResponse(
                embeddings: array_map(static fn () => [0.1, 0.2, 0.3], $texts),
                provider: 'openai',
                model: 'text-embedding-3-small',
            ),
        );
        $this->app->instance(EmbeddingCacheService::class, $cache);
    }

    protected function tearDown(): void
    {
        $this->app->make(TenantContext::class)->reset();
        parent::tearDown();
    }

    public function test_a_reported_permission_list_restricts_the_ingested_document(): void
    {
        $named = $this->member('named@example.com');

        $document = $this->ingest('drive/board/notes.md', SourceAccess::of([
            SourcePrincipal::user('named@example.com'),
        ]));

        $this->assertNotNull(
            $document->fresh()->source_acl_enforced_at,
            'Ingestion did not record that the source dictates this document.',
        );

        $this->assertDatabaseHas('knowledge_document_acl', [
            'knowledge_document_id' => $document->id,
            'subject_type' => KnowledgeDocumentAcl::SUBJECT_USER,
            'subject_id' => (string) $named->id,
            'origin' => KnowledgeDocumentAcl::ORIGIN_SOURCE_MIRROR,
        ]);
    }

    public function test_a_principal_that_cannot_be_placed_is_queued_at_ingest_time(): void
    {
        $document = $this->ingest('drive/board/notes.md', SourceAccess::of([
            SourcePrincipal::user('contractor@agency.example'),
        ]));

        $this->assertDatabaseHas('kb_unmapped_source_principals', [
            'knowledge_document_id' => $document->id,
            'principal_external_id' => 'contractor@agency.example',
            'status' => UnmappedSourcePrincipal::STATUS_PENDING,
        ]);
    }

    public function test_a_corpus_that_reports_nothing_is_untouched(): void
    {
        // Every connector shipping today reports no permissions at all, so
        // this is the path that must cost exactly nothing (R43).
        $document = $this->ingest('docs/handbook.md', null);

        $this->assertNull($document->fresh()->source_acl_enforced_at);
        $this->assertSame(0, UnmappedSourcePrincipal::query()->count());
        $this->assertDatabaseCount('knowledge_document_acl', 0);
    }

    public function test_a_malformed_payload_does_not_restrict_the_document(): void
    {
        // A decoding failure must never read as "the source says nobody":
        // that would hide a document on the strength of a bug. SourceAccess
        // decodes anything malformed to unknown(), and the mirror skips
        // unknown lists.
        $document = $this->ingestRaw('docs/handbook.md', [
            SourceAccess::METADATA_KEY => ['principals' => 'not-a-list'],
        ]);

        $this->assertNull($document->fresh()->source_acl_enforced_at);
    }

    private function ingest(string $path, ?SourceAccess $access): KnowledgeDocument
    {
        return $this->ingestRaw(
            $path,
            $access === null ? [] : [SourceAccess::METADATA_KEY => $access->toArray()],
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function ingestRaw(string $path, array $metadata): KnowledgeDocument
    {
        return app(DocumentIngestor::class)->ingestMarkdown(
            projectKey: 'default',
            sourcePath: $path,
            title: basename($path),
            markdown: "# Notes\n\nBoard compensation review.\n",
            metadata: $metadata,
        );
    }

    private function member(string $email): User
    {
        $user = User::create([
            'name' => 'Named',
            'email' => $email,
            'password' => bcrypt('secret-secret'),
        ]);

        ProjectMembership::create([
            'tenant_id' => $this->tenantId,
            'user_id' => $user->id,
            'project_key' => 'default',
            'role' => 'member',
            'scope_allowlist' => null,
        ]);

        return $user->fresh();
    }
}
