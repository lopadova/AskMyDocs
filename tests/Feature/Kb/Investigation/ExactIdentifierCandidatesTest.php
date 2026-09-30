<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\Investigation;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\User;
use App\Services\Kb\Investigation\KbSourceReader;
use App\Services\Kb\KbSearchService;
use App\Services\Kb\Retrieval\RetrievalFilters;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ExactIdentifierCandidatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set('local-tenant');
    }

    public function test_complete_identifiers_without_digits_are_candidates_but_prefix_collisions_are_not(): void
    {
        $match = $this->document('RO-LONGO, tracking RL-TRACK-9355.');
        $this->document('RO-LONGO-OTHER e RL-TRACK-93550 non sono gli stessi codici.');
        $candidates = app(KbSearchService::class)->exactIdentifierCandidates(['RO-LONGO', 'RL-TRACK-9355'], 'orders');

        $this->assertSame([$match->id], $candidates->pluck('document.id')->all());
        $this->assertSame(0.0, (float) $candidates->first()['vector_score']);
        $this->assertNotNull(app(KbSourceReader::class)->readCandidate($candidates->first(), 'orders'));
    }

    public function test_literal_lookup_keeps_tenant_project_status_and_current_filters(): void
    {
        $valid = $this->document('RL-TRACK-9355', ['source_type' => 'markdown']);
        $this->document('RL-TRACK-9355', ['tenant_id' => 'other-tenant']);
        $this->document('RL-TRACK-9355', ['project_key' => 'other-project']);
        $this->document('RL-TRACK-9355', ['status' => 'archived']);
        $this->document('RL-TRACK-9355', ['source_type' => 'text']);
        $deleted = $this->document('RL-TRACK-9355');
        $deleted->delete();
        $filters = new RetrievalFilters(projectKeys: ['orders'], sourceTypes: ['markdown']);
        $candidates = app(KbSearchService::class)->exactIdentifierCandidates(['RL-TRACK-9355'], 'orders', $filters);

        $this->assertSame([$valid->id], $candidates->pluck('document.id')->all());
        $this->assertTrue(app(KbSearchService::class)->exactIdentifierCandidates(['RL-TRACK-9355'], 'orders',
            new RetrievalFilters(projectKeys: ['other-project']))->isEmpty());
    }

    public function test_source_reader_rechecks_actor_acl_and_revocation_before_any_assessment(): void
    {
        $document = $this->document('RO-LONGO', ['source_acl_enforced_at' => now()]);
        $candidate = app(KbSearchService::class)->exactIdentifierCandidates(['RO-LONGO'], 'orders')->first();
        $actor = User::create(['name' => 'No access', 'email' => 'denied@example.test', 'password' => bcrypt('test')]);
        $reader = app(KbSourceReader::class);

        $this->assertNotNull($candidate);
        $this->assertNull($reader->readCandidate($candidate, 'orders', $actor));
        $this->assertNull($reader->readCandidate($candidate, 'orders'));
        $document->delete();
        $this->assertNull($reader->readCandidate($candidate, 'orders', $actor));
    }

    public function test_sql_wildcards_are_literals_and_not_a_way_to_broaden_identifier_search(): void
    {
        $match = $this->document('CODE_A%9');
        $this->document('CODEXA999');
        $this->assertSame([$match->id], app(KbSearchService::class)->exactIdentifierCandidates(['CODE_A%9'], 'orders')->pluck('document.id')->all());
        $this->assertTrue(app(KbSearchService::class)->exactIdentifierCandidates([], 'orders')->isEmpty());
    }

    private function document(string $text, array $attributes = []): KnowledgeDocument
    {
        $document = KnowledgeDocument::create($attributes + [
            'tenant_id' => 'local-tenant', 'project_key' => 'orders', 'source_type' => 'markdown',
            'title' => 'Email fixture', 'source_path' => 'mail/'.uniqid(), 'mime_type' => 'text/plain',
            'status' => 'active', 'document_hash' => hash('sha256', $text), 'version_hash' => bin2hex(random_bytes(16)),
        ]);
        KnowledgeChunk::create(['tenant_id' => $document->tenant_id, 'project_key' => $document->project_key,
            'knowledge_document_id' => $document->id, 'chunk_order' => 0, 'chunk_text' => $text,
            'chunk_hash' => hash('sha256', $text)]);
        return $document;
    }
}
