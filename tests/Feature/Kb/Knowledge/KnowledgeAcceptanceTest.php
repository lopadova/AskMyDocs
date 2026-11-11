<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\Knowledge;

use App\Models\KbNode;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeEntity;
use App\Models\KnowledgeExtraction;
use App\Models\KnowledgeObservation;
use App\Models\KnowledgeSection;
use App\Models\User;
use App\Services\Kb\Knowledge\KnowledgeContract;
use App\Services\Kb\Knowledge\KnowledgeDocumentContext;
use App\Services\Kb\Knowledge\KnowledgeEnrichment;
use App\Services\Kb\Knowledge\KnowledgeExtractor;
use App\Services\Kb\Knowledge\KnowledgeReadService;
use App\Services\Kb\Knowledge\KnowledgeSpecificationService;
use App\Support\TenantContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class KnowledgeAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;
    private int $calls = 0;
    private array $proposals = [];
    private bool $failNext = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        app(TenantContext::class)->set('test-tenant');
        config(['kb.autowiki.enabled' => true, 'knowledge.connection' => 'sync', 'knowledge.sections_per_job' => 100, 'cache.default' => 'array']);
        Queue::fake();
        $this->actor = User::create(['name' => 'Admin', 'email' => uniqid().'@test.local', 'password' => 'x']);
        $this->actor->assignRole('admin');
        $fake = \Mockery::mock(KnowledgeExtractor::class)->makePartial();
        $fake->shouldReceive('propose')->andReturnUsing(function (string $text) {
            $this->calls++;
            if ($this->failNext) { $this->failNext = false; throw new \RuntimeException('provider down'); }
            return ['contributions' => array_values(array_filter($this->proposals, fn ($c) => str_contains($text, $c['quote'])))];
        });
        $fake->shouldReceive('judge')->andReturnUsing(fn ($candidate) => ['status' => 'accepted', 'support' => str_contains($candidate['quote'], 'proposto') ? 'proposed' : 'documented']);
        $this->app->instance(KnowledgeExtractor::class, $fake);
    }

    private function activate(string $project = 'eng', ?array $blocks = null): int
    {
        $service = app(KnowledgeSpecificationService::class);
        $spec = $service->draft('test-tenant', $project, 'Assistenza e ordini', $blocks ?? app(KnowledgeContract::class)->template(), (string) $this->actor->id);
        $service->activate('test-tenant', $project, $spec->id);
        return $spec->id;
    }

    private function document(array $texts, string $project = 'eng', string $type = 'text'): KnowledgeDocument
    {
        $doc = KnowledgeDocument::create(['tenant_id' => 'test-tenant', 'project_key' => $project, 'source_type' => $type,
            'source_path' => uniqid('mail/').'.txt', 'title' => 'Original conversation', 'mime_type' => 'text/plain', 'status' => 'active',
            'document_hash' => hash('sha256', implode('', $texts)), 'version_hash' => bin2hex(random_bytes(32)), 'source_updated_at' => now()]);
        foreach ($texts as $i => $text) KnowledgeChunk::create(['tenant_id' => 'test-tenant', 'project_key' => $project, 'knowledge_document_id' => $doc->id, 'chunk_order' => $i, 'heading_path' => 'Section '.$i, 'chunk_text' => $text, 'chunk_hash' => hash('sha256', $text), 'metadata' => []]);
        return $doc;
    }

    private function contribution(string $id, string $quote, string $value = 'Aggiornamento', string $namespace = 'order'): array
    {
        return ['type' => 'order', 'namespace' => $namespace, 'identifier' => $id, 'quote' => $quote, 'rule' => 'history', 'property' => 'event', 'value' => $value];
    }

    public function test_repeated_order_has_one_project_entity_and_original_timeline_without_retry_duplicates(): void
    {
        $this->activate();
        $this->proposals = [$this->contribution('00123', 'Ordine 00123 spedito.'), $this->contribution('00123', 'Ordine 00123 consegnato.')];
        $first = $this->document(['Ordine 00123 spedito.']);
        $second = $this->document(['Ordine 00123 consegnato.']);
        $service = app(KnowledgeEnrichment::class);
        $run = $service->process($first);
        $service->process($second); $service->process($first);
        $this->assertSame(2, $this->calls);
        $this->assertDatabaseCount('knowledge_entities', 1);
        $this->assertDatabaseCount('knowledge_observations', 2);
        $context = app(KnowledgeReadService::class)->context('test-tenant', 'eng', KnowledgeEntity::first()->id, $this->actor, limit: 1);
        $this->assertSame('00123', $context['entity']['identifier']);
        $this->assertNotNull($context['next_cursor']);
        $next = app(KnowledgeReadService::class)->context('test-tenant', 'eng', KnowledgeEntity::first()->id, $this->actor, $context['next_cursor']);
        $this->assertSame('Ordine 00123 consegnato.', $next['timeline'][0]['quote']);
        $this->assertSame('complete', $run->status);
        $this->assertSame(0, KbNode::query()->count()); // Legacy graph cannot expose entity counts.
        $this->assertSame(1, KbNode::withoutGlobalScope('document_category')->where('node_category', 'entity')->count());
    }

    public function test_one_conversation_contributes_separately_to_two_orders_and_procedure(): void
    {
        $this->activate();
        $one = 'Ordine 00123 spedito.'; $two = 'Ordine 00456 in attesa.'; $procedure = 'Per riavviare il dispositivo, spegnerlo, attendere dieci secondi e riaccenderlo.';
        $this->proposals = [$this->contribution('00123', $one, 'Spedito'), $this->contribution('00456', $two, 'In attesa'),
            ['type' => 'procedure', 'namespace' => 'procedure', 'identifier' => 'source', 'quote' => $procedure, 'rule' => 'procedure', 'property' => 'instructions', 'value' => $procedure]];
        app(KnowledgeEnrichment::class)->process($this->document(["$one\n$two\n$procedure"]));
        $this->assertDatabaseCount('knowledge_entities', 3);
        $this->assertSame(['In attesa'], KnowledgeObservation::where('entity_id', KnowledgeEntity::where('identifier', '00456')->first()->id)->pluck('value')->all());
        $this->assertDatabaseCount('knowledge_observations', 3);
    }

    public function test_project_and_namespace_are_part_of_identity(): void
    {
        $blocks = app(KnowledgeContract::class)->template();
        $blocks['identity']['namespaces'][] = ['key' => 'erp', 'type' => 'order', 'pattern' => '[0-9]+'];
        $this->activate('eng', $blocks); $this->activate('sales', $blocks);
        $this->proposals = [$this->contribution('00123', 'Ordine 00123 spedito.'), $this->contribution('00123', 'ERP 00123 in attesa.', namespace: 'erp')];
        app(KnowledgeEnrichment::class)->process($this->document(['Ordine 00123 spedito. ERP 00123 in attesa.']));
        app(KnowledgeEnrichment::class)->process($this->document(['Ordine 00123 spedito.'], 'sales'));
        $this->assertDatabaseCount('knowledge_entities', 3);
        $this->assertCount(1, app(KnowledgeReadService::class)->search('test-tenant', 'eng', '00123', $this->actor, 'erp')['entities']);
        $this->assertNull(app(KnowledgeReadService::class)->context('other-tenant', 'eng', KnowledgeEntity::first()->id, $this->actor));
    }

    public function test_changed_deleted_and_revoked_originals_cannot_supply_labels_or_proofs(): void
    {
        $this->activate();
        $this->proposals = [$this->contribution('00123', 'Ordine 00123 spedito.')];
        $doc = $this->document(['Ordine 00123 spedito.']);
        app(KnowledgeEnrichment::class)->process($doc);
        $entity = KnowledgeEntity::first(); $reader = app(KnowledgeReadService::class);
        $this->assertNotNull($reader->context('test-tenant', 'eng', $entity->id, $this->actor));
        $chunk = $doc->chunks()->first(); $chunk->update(['chunk_text' => 'Testo modificato.']);
        $this->assertNull($reader->context('test-tenant', 'eng', $entity->id, $this->actor));
        $chunk->update(['chunk_text' => 'Ordine 00123 spedito.']);
        $doc->delete();
        $this->assertSame([], $reader->search('test-tenant', 'eng', '00123', $this->actor)['entities']);
        $doc->restore();
        $viewer = User::create(['name' => 'Viewer', 'email' => uniqid().'@test.local', 'password' => 'x']); $viewer->assignRole('viewer');
        config(['kb.project_isolation.enabled' => true]);
        \App\Models\ProjectMembership::create(['tenant_id' => 'test-tenant', 'project_key' => 'eng', 'user_id' => $viewer->id, 'role' => 'member']);
        $this->assertNotNull($reader->context('test-tenant', 'eng', $entity->id, $viewer));
        \App\Models\KnowledgeDocumentAcl::create(['knowledge_document_id' => $doc->id, 'subject_type' => 'user', 'subject_id' => (string) $viewer->id, 'permission' => 'view', 'effect' => 'deny']);
        $this->assertNull($reader->context('test-tenant', 'eng', $entity->id, $viewer));
    }

    public function test_distant_sections_are_processed_and_can_be_read_independently(): void
    {
        $this->activate();
        $one = 'Ordine 00123 spedito.'; $two = 'Ordine 00456 in attesa.';
        $this->proposals = [$this->contribution('00123', $one), $this->contribution('00456', $two)];
        $doc = $this->document([$one, str_repeat('Contenuti intermedi. ', 2000), $two], type: 'pdf');
        $run = app(KnowledgeEnrichment::class)->process($doc);
        $this->assertGreaterThan(3, $run->total_sections);
        $this->assertSame($run->total_sections, $run->processed_sections);
        $this->assertDatabaseCount('knowledge_observations', 2);
        $map = app(KnowledgeDocumentContext::class)->read('test-tenant', 'eng', $doc->id, $this->actor);
        $this->assertTrue($map['coverage']['partial']);
        $last = end($map['section_map'])['ordinal'];
        $read = app(KnowledgeDocumentContext::class)->read('test-tenant', 'eng', $doc->id, $this->actor, $last);
        $this->assertSame($two, $read['sections'][0]['text']);
    }

    public function test_revision_changes_are_stale_until_selective_reprocessing_and_no_eager_dispatch(): void
    {
        $this->activate();
        $this->proposals = [$this->contribution('00123', 'Ordine 00123 proposto rimborso.')];
        $doc = $this->document(['Ordine 00123 proposto rimborso.']);
        app(KnowledgeEnrichment::class)->process($doc);
        $this->activate();
        Queue::assertNothingPushed();
        $context = app(KnowledgeReadService::class)->context('test-tenant', 'eng', KnowledgeEntity::first()->id, $this->actor);
        $this->assertTrue($context['coverage']['stale_revision']);
        $this->assertSame('proposed', $context['timeline'][0]['support']);
        app(KnowledgeEnrichment::class)->process($doc);
        $context = app(KnowledgeReadService::class)->context('test-tenant', 'eng', KnowledgeEntity::first()->id, $this->actor);
        $this->assertCount(1, $context['timeline']);
        $this->assertFalse($context['coverage']['stale_revision']);
    }

    public function test_retry_resumes_acquired_sections_and_kill_switch_leaves_originals(): void
    {
        $this->activate();
        $this->proposals = [$this->contribution('00123', 'Ordine 00123 spedito.')];
        $doc = $this->document(['Ordine 00123 spedito.', 'Ordine 00456 in attesa.']);
        config(['knowledge.sections_per_job' => 1]);
        $first = app(KnowledgeEnrichment::class)->process($doc);
        $this->assertSame('pending', $first->status);
        $this->failNext = true;
        try { app(KnowledgeEnrichment::class)->process($doc); $this->fail('Expected provider error'); } catch (\RuntimeException) {}
        $this->assertSame('failed', $first->fresh()->status);
        $run = app(KnowledgeEnrichment::class)->process($doc);
        $this->assertSame('complete', $run->status);
        $this->assertSame(3, $this->calls);
        $this->assertDatabaseCount('knowledge_extractions', 1);
        config(['knowledge.enabled' => false]);
        $this->assertNull(app(KnowledgeEnrichment::class)->process($doc));
        $this->assertNotNull($doc->fresh());
        $this->assertDatabaseCount('knowledge_observations', 1);
    }

    public function test_company_inheritance_replaces_whole_blocks_and_projects_remain_opt_in(): void
    {
        $this->activate('*');
        $specs = app(KnowledgeSpecificationService::class);
        $this->assertNull($specs->effective('test-tenant', 'eng'));
        $this->activate('eng', array_fill_keys(KnowledgeContract::BLOCKS, null));
        $this->assertNotNull($specs->effective('test-tenant', 'eng')['company_revision_id']);
        $this->assertNull(app(KnowledgeEnrichment::class)->process($this->document(['Ordine 00123 spedito.'], 'legacy')));
    }

    public function test_literal_quotes_and_identifiers_are_deterministic_gates(): void
    {
        $extractor = new KnowledgeExtractor;
        $contract = ['blocks' => app(KnowledgeContract::class)->template()];
        $candidate = $this->contribution('123', 'Ordine 00123 spedito.');
        $this->assertNull($extractor->validate($candidate, $candidate['quote'], $contract));
        $candidate = $this->contribution('00123', 'Citazione inventata per 00123.');
        $this->assertNull($extractor->validate($candidate, 'Ordine 00123 spedito.', $contract));
        $result = $extractor->judge($this->contribution('00123', 'Ordine 00123 chiuso.'), $contract);
        $this->assertSame('abstained', $result['status']);
        $this->assertSame('calibration_required', $result['reason']);
    }
    public function test_http_cli_and_mcp_share_authorized_entities_and_timeline(): void
    {
        $this->activate();
        $this->proposals = [$this->contribution('00123', 'Ordine 00123 spedito.')];
        $doc = $this->document(['Ordine 00123 spedito.']);
        app(KnowledgeEnrichment::class)->process($doc);
        $this->actingAs($this->actor);
        $this->getJson('/api/kb/knowledge/entities?project_key=eng&q=00123')->assertOk()->assertJsonPath('entities.0.identifier', '00123');
        $entity = KnowledgeEntity::first();
        $this->getJson('/api/kb/knowledge/entities/'.$entity->id.'/timeline?project_key=eng')->assertOk()->assertJsonPath('timeline.0.quote', 'Ordine 00123 spedito.');
        $this->getJson('/api/kb/knowledge/entities/'.$entity->id.'?project_key=sales')->assertNotFound();
        $this->artisan('kb:knowledge', ['operation' => 'search', 'project' => 'eng', '--tenant' => 'test-tenant', '--actor' => $this->actor->id, '--query' => '00123'])->expectsOutputToContain('00123')->assertSuccessful();
        $response = app(\App\Mcp\Tools\SearchKnowledgeEntitiesTool::class)->handle(new \Laravel\Mcp\Request(['project_key' => 'eng', 'query' => '00123']), app(KnowledgeReadService::class));
        $this->assertStringContainsString('00123', (string) $response->content());
        $read = app(\App\Mcp\Tools\ReadKnowledgeContextTool::class)->handle(new \Laravel\Mcp\Request(['project_key' => 'eng', 'entity_id' => $entity->id]), app(KnowledgeReadService::class), app(KnowledgeDocumentContext::class));
        $this->assertStringContainsString('Ordine 00123 spedito.', (string) $read->content());
    }

    public function test_admin_http_versions_activation_preview_and_selective_processing(): void
    {
        $this->actingAs($this->actor);
        $response = $this->postJson('/api/admin/kb/knowledge-specifications/drafts', ['project_key' => 'eng', 'description' => 'Ordini', 'blocks' => app(KnowledgeContract::class)->template()])->assertCreated();
        $revision = $response->json('revision.id');
        $this->postJson('/api/admin/kb/knowledge-specifications/'.$revision.'/activate', ['project_key' => 'eng'])->assertOk()->assertJsonPath('effective.revision_id', $revision);
        Queue::assertNothingPushed();
        $this->proposals = [$this->contribution('00123', 'Ordine 00123 spedito.')];
        $doc = $this->document(['Ordine 00123 spedito.']);
        $this->postJson('/api/admin/kb/knowledge-specifications/'.$revision.'/preview', ['project_key' => 'eng', 'document_ids' => [$doc->id]])->assertOk()->assertJsonPath('persisted', false)->assertJsonPath('preview.0.contributions.0.judgment.status', 'accepted');
        $this->assertDatabaseCount('knowledge_observations', 0);
        $this->postJson('/api/admin/kb/knowledge-specifications/reprocess', ['project_key' => 'eng', 'selection' => ['document_ids' => [$doc->id]]])->assertAccepted();
        Queue::assertPushed(\App\Jobs\EnrichKnowledgeDocumentJob::class, 1);
        $this->postJson('/api/admin/kb/knowledge-specifications/disable', ['project_key' => 'eng'])->assertOk();
        $this->assertNull(app(KnowledgeEnrichment::class)->process($doc));
        $this->assertNotNull($doc->fresh());
    }

    public function test_graph_rebuild_preserves_entity_projection_and_lint_distinguishes_categories(): void
    {
        $this->activate();
        $this->proposals = [$this->contribution('00123', 'Ordine 00123 spedito.')];
        app(KnowledgeEnrichment::class)->process($this->document(['Ordine 00123 spedito.']));
        $canonical = $this->document(['# Canonical decision']);
        $canonical->forceFill(['is_canonical' => true, 'doc_id' => 'DEC-KNOWLEDGE', 'slug' => 'dec-knowledge', 'canonical_type' => 'decision', 'canonical_status' => 'accepted', 'frontmatter_json' => []])->save();
        $this->artisan('kb:rebuild-graph', ['--tenant' => 'test-tenant', '--project' => 'eng', '--sync' => true])->assertSuccessful();
        $this->assertSame(1, KbNode::withoutGlobalScope('document_category')->where('node_category', 'entity')->count());
        $this->assertTrue(app(\App\Services\Kb\Knowledge\KnowledgeGraphProjection::class)->lint('test-tenant', 'eng')['healthy']);
    }

    public function test_wave_and_shared_retrieval_find_verified_entity_passages_with_filters(): void
    {
        $this->activate();
        $this->proposals = [$this->contribution('00123', 'Ordine 00123 spedito.')];
        $doc = $this->document(['Ordine 00123 spedito.']);
        app(KnowledgeEnrichment::class)->process($doc);
        $this->actingAs($this->actor);
        $search = \Mockery::mock(\App\Services\Kb\KbSearchService::class);
        $search->shouldReceive('searchWithContext')->andReturn(new \App\Services\Kb\Retrieval\SearchResult(collect(), collect(), collect()));
        $this->app->instance(\App\Services\Kb\KbSearchService::class, $search);
        $retrieval = new \App\Services\Kb\Chat\ChatRetrievalService($search);
        $result = $retrieval->retrieve('00123', 'eng');
        $this->assertSame($doc->id, $result->primary->first()['document']['id']);
        $this->assertSame('Ordine 00123 spedito.', $result->primary->first()['chunk_text']);
        $this->assertCount(1, $retrieval->buildCitations($result));
    }

    public function test_mcp_transport_enforces_read_and_administration_scopes(): void
    {
        $this->activate();
        $this->proposals = [$this->contribution('00123', 'Ordine 00123 spedito.')];
        app(KnowledgeEnrichment::class)->process($this->document(['Ordine 00123 spedito.']));
        \App\Models\ProjectMembership::create(['tenant_id' => 'test-tenant', 'project_key' => 'eng', 'user_id' => $this->actor->id, 'role' => 'member']);
        $plain = 'knowledge-transport-test-token';
        \App\Models\McpTenantToken::create(['tenant_id' => 'test-tenant', 'label' => 'Acceptance', 'token_hash' => hash('sha256', $plain), 'token_last4' => 'oken', 'scopes_json' => ['mcp:read'], 'created_by' => $this->actor->id]);
        $response = $this->withHeaders(['Authorization' => 'Bearer '.$plain, 'Accept' => 'application/json, text/event-stream'])->postJson('/mcp/kb', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'search_knowledge_entities', 'arguments' => ['project_key' => 'eng', 'query' => '00123']],
        ])->assertOk();
        $this->assertStringContainsString('00123', $response->getContent());
        $this->withHeaders(['Authorization' => 'Bearer '.$plain, 'Accept' => 'application/json, text/event-stream'])->postJson('/mcp/kb', [
            'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'manage_knowledge_specification', 'arguments' => ['project_key' => 'eng', 'operation' => 'disable']],
        ])->assertForbidden()->assertJsonPath('required_scope', 'mcp:tools:write');
    }

    public function test_activation_during_extraction_prevents_obsolete_publication(): void
    {
        $this->activate();
        $doc = $this->document(['Ordine 00123 spedito.']);
        $fake = \Mockery::mock(KnowledgeExtractor::class)->makePartial();
        $fake->shouldReceive('propose')->andReturn(['contributions' => [$this->contribution('00123', 'Ordine 00123 spedito.')]]);
        $fake->shouldReceive('judge')->once()->andReturnUsing(function () { $this->activate(); return ['status' => 'accepted', 'support' => 'documented']; });
        $this->app->instance(KnowledgeExtractor::class, $fake);
        $run = app(KnowledgeEnrichment::class)->process($doc);
        $this->assertSame('superseded', $run->status);
        $this->assertDatabaseCount('knowledge_observations', 0);
    }

    public function test_sync_ingestion_registers_after_commit_and_unchanged_reingest_does_not_duplicate(): void
    {
        $this->activate();
        $cache = \Mockery::mock(\App\Services\Kb\EmbeddingCacheService::class);
        $cache->shouldReceive('generate')->andReturnUsing(fn ($texts) => new \App\Ai\EmbeddingsResponse(array_fill(0, count($texts), [0.1, 0.2, 0.3]), 'openai', 'test'));
        $this->app->instance(\App\Services\Kb\EmbeddingCacheService::class, $cache);
        $ingestor = app(\App\Services\Kb\DocumentIngestor::class);
        $doc = $ingestor->ingestMarkdown('eng', 'knowledge-sync.md', 'Order', "# Ordine\n\nOrdine 00123 spedito.");
        $again = $ingestor->ingestMarkdown('eng', 'knowledge-sync.md', 'Order', "# Ordine\n\nOrdine 00123 spedito.");
        $this->assertSame($doc->id, $again->id);
        $this->assertDatabaseCount('knowledge_extractions', 1);
        $this->assertGreaterThan(0, KnowledgeExtraction::first()->total_sections);
        $this->assertDatabaseCount('knowledge_observations', 0); // queued work has not run inline
        Queue::assertPushed(\App\Jobs\EnrichKnowledgeDocumentJob::class);
    }

    public function test_concurrent_retry_cannot_acquire_the_paid_extraction_lock(): void
    {
        $this->activate();
        config(['knowledge.sections_per_job' => 1]);
        $this->proposals = [$this->contribution('00123', 'Ordine 00123 spedito.')];
        $doc = $this->document(['Ordine 00123 spedito.', 'Seconda sezione.']);
        $run = app(KnowledgeEnrichment::class)->process($doc);
        $lock = \Illuminate\Support\Facades\Cache::lock('knowledge-extraction:'.$run->idempotency_key, 600);
        $this->assertTrue($lock->get());
        try {
            try { app(KnowledgeEnrichment::class)->process($doc); $this->fail('Concurrent paid work must be excluded.'); }
            catch (\RuntimeException $e) { $this->assertStringContainsString('already running', $e->getMessage()); }
            $this->assertSame(1, $this->calls);
        } finally { $lock->release(); }
        app(KnowledgeEnrichment::class)->process($doc);
        $this->assertSame(2, $this->calls);
        $this->assertDatabaseCount('knowledge_observations', 1);
    }

    public function test_migration_rollback_preserves_originals_and_document_graph(): void
    {
        $this->activate();
        $this->proposals = [$this->contribution('00123', 'Ordine 00123 spedito.')];
        $doc = $this->document(['Ordine 00123 spedito.']);
        app(KnowledgeEnrichment::class)->process($doc);
        KbNode::create(['tenant_id' => 'test-tenant', 'project_key' => 'eng', 'node_uid' => 'original', 'node_type' => 'decision', 'label' => 'Original decision']);
        $migration = require __DIR__.'/../../../../database/migrations/2026_11_11_000001_create_project_knowledge.php';
        $migration->down();
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('knowledge_entities'));
        $this->assertDatabaseHas('knowledge_documents', ['id' => $doc->id]);
        $this->assertDatabaseHas('kb_nodes', ['node_uid' => 'original']);
        $this->assertDatabaseMissing('kb_nodes', ['node_type' => 'knowledge_entity']);
        $migration->up();
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('knowledge_entities'));
    }

    public function test_semantic_abstention_records_partial_coverage_without_publishing_or_hiding_original(): void
    {
        $this->activate();
        $doc = $this->document(['Ordine 00123 chiuso, senza esito documentato.']);
        $fake = \Mockery::mock(KnowledgeExtractor::class)->makePartial();
        $fake->shouldReceive('propose')->once()->andReturn(['contributions' => [$this->contribution('00123', 'Ordine 00123 chiuso, senza esito documentato.', 'Risolto')]]);
        $fake->shouldReceive('judge')->once()->andReturn(['status' => 'abstained', 'reason' => 'decision_unavailable_or_uncertain', 'provider_status' => 402]);
        $this->app->instance(KnowledgeExtractor::class, $fake);
        $run = app(KnowledgeEnrichment::class)->process($doc);
        $this->assertSame('partial', $run->status);
        $this->assertSame(1, $run->abstained_sections);
        $this->assertDatabaseCount('knowledge_observations', 0);
        $this->assertSame('active', $doc->fresh()->status);
        app(KnowledgeEnrichment::class)->process($doc); // cached abstention does not rebill
    }

    public function test_entity_agent_tools_produce_citations_and_drop_revoked_context_on_resume(): void
    {
        $this->activate();
        $this->proposals = [$this->contribution('00123', 'Ordine 00123 spedito.')];
        $doc = $this->document(['Ordine 00123 spedito.']);
        app(KnowledgeEnrichment::class)->process($doc);
        $this->actingAs($this->actor);
        $run = \App\Models\AgentRun::create(['tenant_id' => 'test-tenant', 'run_id' => (string) \Illuminate\Support\Str::uuid(),
            'project_key' => 'eng', 'channel' => 'chat', 'actor_type' => 'user', 'actor_id' => (string) $this->actor->id, 'user_id' => $this->actor->id,
            'locale' => 'en', 'timezone' => 'UTC', 'status' => 'running', 'input_json' => [], 'result_json' => [], 'budget_json' => [], 'counters_json' => []]);
        $context = new \App\Agent\AgentExecutionContext($run->run_id, 'test-tenant', 'eng', 'chat', 'user', (string) $this->actor->id, 'en', 'UTC');
        $tools = app(\App\Agent\Tools\AgentToolRegistry::class)->forContext($context, $this->actor);
        $this->assertArrayHasKey('search_knowledge_entities', $tools);
        $this->assertArrayHasKey('read_knowledge_context', $tools);
        $tool = $tools['read_knowledge_context'];
        $result = app(\App\Agent\Tools\AgentServerToolRunner::class)->execute($tool, ['entity_id' => KnowledgeEntity::first()->id], $context, $run, new \App\Agent\Budget\AgentBudgetTracker($run, false));
        $this->assertSame('Ordine 00123 spedito.', $result->body['documents'][0]['evidence'][0]['content']);
        $this->assertNotNull($result->body['documents'][0]['evidence'][0]['knowledge_observation_id']);
        $evidence = app(\App\Agent\Evidence\AgentEvidenceFactory::class)->empty();
        $evidence->import(['documents' => $result->body['documents']]);
        $safe = app(\App\Agent\Evidence\AgentEvidenceAccess::class)->current($run, $context, $evidence->jsonSerialize());
        $this->assertCount(1, $safe['documents']);
        $doc->delete();
        $safe = app(\App\Agent\Evidence\AgentEvidenceAccess::class)->current($run, $context, $evidence->jsonSerialize());
        $this->assertSame([], $safe['documents']);
    }

    public function test_wave_search_reads_entity_originals_before_semantic_lookup(): void
    {
        $this->activate();
        $this->proposals = [$this->contribution('00123', 'Ordine 00123 spedito.')];
        $doc = $this->document(['Ordine 00123 spedito.']);
        app(KnowledgeEnrichment::class)->process($doc);
        $retrieval = \Mockery::mock(\App\Services\Kb\Chat\ChatRetrievalService::class)->makePartial();
        $retrieval->shouldReceive('retrieve')->once()->andReturn(new \App\Services\Kb\Retrieval\SearchResult(collect(), collect(), collect()));
        $this->app->instance(\App\Services\Kb\Chat\ChatRetrievalService::class, $retrieval);
        $run = new \App\Models\AgentRun(['result_json' => []]); $run->setRelation('user', $this->actor);
        $context = new \App\Agent\AgentExecutionContext('wave', 'test-tenant', 'eng', 'chat', 'user', (string) $this->actor->id, 'en', 'UTC');
        $result = app(\App\Agent\Waves\AgentWaveKnowledgeSearch::class)->search('00123', $run, $context, new \App\Services\Kb\Retrieval\RetrievalFilters(projectKeys: ['eng']));
        $this->assertSame($doc->id, $result->body['documents'][0]['document_id']);
        $this->assertSame('Ordine 00123 spedito.', $result->body['documents'][0]['evidence'][0]['content']);
        $this->assertTrue($result->body['documents'][0]['evidence'][0]['coverage']['scope'] === 'indexed_available_text');
    }

    public function test_relationship_endpoints_have_authorized_history_without_reassigning_subject_facts(): void
    {
        $this->activate();
        $quote = 'Cliente C123: ordine 00123 spedito.';
        $contribution = $this->contribution('00123', $quote, 'Spedito');
        $contribution['target'] = ['type' => 'customer', 'namespace' => 'customer', 'identifier' => 'C123', 'relation' => 'related_to'];
        $this->proposals = [$contribution];
        app(KnowledgeEnrichment::class)->process($this->document([$quote]));
        $reader = app(KnowledgeReadService::class);
        $result = $reader->search('test-tenant', 'eng', 'C123', $this->actor);
        $this->assertCount(1, $result['entities']);
        $context = $reader->context('test-tenant', 'eng', $result['entities'][0]['id'], $this->actor);
        $this->assertSame('00123', $context['timeline'][0]['subject']['identifier']);
        $this->assertSame('incoming', $context['relations'][0]['direction']);
        $this->assertSame('00123', $context['relations'][0]['entity']['identifier']);
        $this->assertStringContainsString('00123', $context['summary'][0]);
    }

    public function test_generated_canonical_prose_cannot_become_an_independent_confirmation(): void
    {
        $this->activate();
        $this->proposals = [$this->contribution('00123', 'Ordine 00123 spedito.')];
        $generated = $this->document(['Ordine 00123 spedito.']);
        $generated->forceFill(['is_canonical' => true, 'generation_source' => 'auto', 'canonical_type' => 'concept', 'slug' => 'generated', 'doc_id' => 'AUTO-GENERATED'])->save();
        $run = app(KnowledgeEnrichment::class)->process($generated);
        $this->assertSame('partial', $run->status);
        $this->assertSame(0, $this->calls);
        $this->assertDatabaseCount('knowledge_observations', 0);
        app(KnowledgeEnrichment::class)->process($this->document(['Ordine 00123 spedito.']));
        $this->assertDatabaseCount('knowledge_observations', 1);
    }

}
