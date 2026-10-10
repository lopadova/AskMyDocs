<?php

declare(strict_types=1);

namespace Tests\Feature\Connectors;

use App\Ai\AiManager;
use App\Ai\AiResponse;
use App\Ai\EmbeddingsResponse;
use App\Connectors\Freshdesk\FreshdeskCaseStudyLineage;
use App\Connectors\Freshdesk\OpenRouterCaseStudyGenerator;
use App\Decisions\DecisionException;
use App\Models\KnowledgeDocument;
use App\Services\Kb\DocumentIngestor;
use App\Services\Kb\EmbeddingCacheService;
use App\Services\Kb\Investigation\KbSourceReader;
use App\Services\Kb\KbSearchService;
use App\Services\Kb\Pipeline\SourceDocument;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorFreshdesk\CaseStudies\CaseStudy;
use Padosoft\AskMyDocsConnectorFreshdesk\CaseStudies\CaseStudyInput;
use Padosoft\AskMyDocsConnectorFreshdesk\CaseStudies\CaseStudyResult;
use Padosoft\AskMyDocsConnectorFreshdesk\Sync\SourceState;
use Padosoft\AskMyDocsConnectorFreshdesk\Sync\SyncRun;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class FreshdeskCaseStudyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set('case-tenant');
        config(['connector-freshdesk.case_studies.enabled' => true, 'kb.sources.disk' => 'local',
            'kb.conversion_artifacts.enabled' => false, 'reasoning.threshold' => 0.8, 'ai.providers.openrouter.key' => 'test-key']);
        Queue::fake();
        Storage::fake('local');
        Http::preventStrayRequests();
    }

    private function input(): CaseStudyInput
    {
        $text = 'Il servizio non risponde. Abbiamo riavviato il servizio. Il cliente conferma che funziona.';

        return new CaseStudyInput('case-tenant', 1, '42', 'support', 'Servizio bloccato', 'https://example.freshdesk.com/a/tickets/42', [
            ['key' => 'conversation:11', 'conversation_id' => '11', 'created_at' => '2026-10-09T10:00:00Z', 'text' => $text, 'sha256' => hash('sha256', $text)],
        ]);
    }

    private function candidate(): array
    {
        return ['field' => 'intervention', 'text' => 'Riavvio del servizio.',
            'evidence' => [['source_key' => 'conversation:11', 'quote' => 'Abbiamo riavviato il servizio.']]];
    }

    private function fields(): array
    {
        $fields = array_fill_keys(CaseStudyResult::FIELDS, ['status' => 'not_documented', 'text' => '', 'evidence' => []]);
        $fields['intervention'] = ['status' => 'documented', 'text' => $this->candidate()['text'], 'evidence' => $this->candidate()['evidence']];

        return $fields;
    }

    private function ai(array $response): AiManager
    {
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithProvider')->once()->withArgs(fn ($provider, $system, $messages, $options) => $provider === 'openrouter'
            && $options['temperature'] === 0 && $options['response_format']['type'] === 'json_object')
            ->andReturn(new AiResponse(json_encode($response, JSON_THROW_ON_ERROR), 'openrouter', 'test-model', finishReason: 'stop'));

        return $ai;
    }

    public static function semanticDecisions(): array
    {
        return [
            'explicitly performed' => [0.99, 0.01, 'documented'],
            'suggestion only' => [0.01, 0.01, 'not_documented'],
            'uncertain support' => [0.5, 0.01, 'not_documented'],
            'uncertain conflict' => [0.99, 0.5, 'not_documented'],
        ];
    }

    #[DataProvider('semanticDecisions')]
    public function test_semantic_verification_abstains_unless_support_and_no_conflict_are_confident(float $support, float $conflict, string $status): void
    {
        Http::fake(fn ($request) => Http::response(['id' => 'decision-1', 'model' => 'typesafe/jev-1.13', 'answers' => [
            'intervention_supported' => ['type' => 'noul', 'noul' => $support],
            'intervention_conflicts' => ['type' => 'noul', 'noul' => $conflict],
        ], 'usage' => ['cost' => 0.001]]));
        $generator = new OpenRouterCaseStudyGenerator($this->ai(['fields' => $this->fields()]));
        $result = $generator->compile($this->input(), [$this->candidate()]);
        $this->assertSame($status, $result->fields['intervention']['status']);
        $this->assertSame('not_documented', $result->fields['cause']['status']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'https://openrouter.ai/api/alpha/decisions' && $request->method() === 'POST');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'freshdesk.com'));
    }

    public function test_conflicting_original_observations_are_retained_without_asserting_a_resolution(): void
    {
        $other = ['field' => 'intervention', 'text' => 'Il servizio non risponde.', 'evidence' => [['source_key' => 'conversation:11', 'quote' => 'Il servizio non risponde.']]];
        Http::fake(fn () => Http::response(['model' => 'typesafe/jev-1.13', 'answers' => [
            'intervention_supported' => ['type' => 'noul', 'noul' => 0.99], 'intervention_conflicts' => ['type' => 'noul', 'noul' => 0.99],
        ]]));
        $result = (new OpenRouterCaseStudyGenerator($this->ai(['fields' => $this->fields()])))->compile($this->input(), [$this->candidate(), $other]);
        $this->assertSame('conflicting', $result->fields['intervention']['status']);
        $this->assertCount(2, $result->fields['intervention']['evidence']);
        $this->assertStringNotContainsString('Riavvio del servizio.', $result->markdown($this->input()));
    }

    public function test_literal_but_unextracted_quote_cannot_be_used_in_synthesis(): void
    {
        $fields = $this->fields();
        $fields['intervention']['evidence'][0]['quote'] = 'Il cliente conferma che funziona.';
        $this->expectException(\UnexpectedValueException::class);
        (new OpenRouterCaseStudyGenerator($this->ai(['fields' => $fields])))->compile($this->input(), [$this->candidate()]);
    }

    public function test_unavailable_verifier_does_not_publish_unverified_fields(): void
    {
        Http::fake(fn () => Http::response([], 503));
        $this->expectException(DecisionException::class);
        (new OpenRouterCaseStudyGenerator($this->ai(['fields' => $this->fields()])))->compile($this->input(), [$this->candidate()]);
    }

    public function test_extraction_rejects_invented_quotes_before_semantic_checks(): void
    {
        $candidate = $this->candidate();
        $candidate['evidence'][0]['quote'] = 'Abbiamo cambiato un componente mai menzionato.';
        $this->expectException(\UnexpectedValueException::class);
        (new OpenRouterCaseStudyGenerator($this->ai(['candidates' => [$candidate]])))->extract($this->input(), $this->input()->sources);
    }

    public static function extractionDecisions(): array
    {
        return [
            'documented action' => [0.99, 'Abbiamo riavviato il servizio.', 1],
            'quotation qualified as example' => [0.01, 'Il piano riporta "Abbiamo riavviato il servizio." Questa frase è un esempio: nessun intervento eseguito.', 0],
            'uncertain meaning' => [0.5, 'Abbiamo riavviato il servizio.', 0],
        ];
    }

    #[DataProvider('extractionDecisions')]
    public function test_extracted_facts_are_checked_against_original_context(float $support, string $original, int $expected): void
    {
        $source = array_replace($this->input()->sources[0], ['text' => $original, 'sha256' => hash('sha256', $original)]);
        $input = CaseStudyInput::fromArray(array_replace($this->input()->toArray(), ['sources' => [$source]]));
        Http::fake(fn () => Http::response(['model' => 'typesafe/jev-1.13', 'answers' => ['fact_0' => ['type' => 'noul', 'noul' => $support]]]));
        $facts = (new OpenRouterCaseStudyGenerator($this->ai(['candidates' => [$this->candidate()]])))->extract($input, $input->sources);
        $this->assertCount($expected, $facts);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'https://openrouter.ai/api/alpha/decisions'
            && $request['state']['original_sources'][0]['text'] === $original);
    }

    private function fixture(): array
    {
        $installation = ConnectorInstallation::create(['tenant_id' => 'case-tenant', 'connector_name' => 'freshdesk', 'label' => 'support',
            'project_key' => 'support', 'status' => 'active', 'config_json' => ['connection' => ['domain' => 'example.freshdesk.com']]]);
        $input = CaseStudyInput::fromArray(array_replace($this->input()->toArray(), ['installationId' => $installation->id]));
        $run = SyncRun::create(['tenant_id' => 'case-tenant', 'installation_id' => $installation->id, 'mode' => 'incremental', 'status' => 'completed',
            'checkpoint' => [], 'counts' => [], 'scan_started_at' => now()]);
        $state = SourceState::create(['tenant_id' => 'case-tenant', 'installation_id' => $installation->id, 'kind' => 'ticket', 'remote_id' => '42',
            'fingerprint' => str_repeat('a', 64), 'last_seen_run_id' => $run->id, 'relative_path' => 'support/connectors/freshdesk/ticket/42.md']);
        $result = new CaseStudyResult($this->fields());
        $case = CaseStudy::create(['tenant_id' => 'case-tenant', 'installation_id' => $installation->id, 'ticket_id' => '42',
            'source_fingerprint' => $state->fingerprint, 'private_notes_included' => true, 'status' => 'ready', 'snapshot' => $input->toArray(),
            'checkpoint' => [], 'result' => ['fields' => $result->fields, 'usage' => []]]);
        $metadata = ['connector' => 'freshdesk', 'source_kind' => 'case_study', 'installation_id' => $installation->id,
            'freshdesk_case_id' => $case->id, 'case_source_fingerprint' => $state->fingerprint,
            'case_result_hash' => hash('sha256', json_encode($case->result, JSON_THROW_ON_ERROR))];
        $source = new SourceDocument('support/connectors/freshdesk/case_study/42.md', CaseStudyResult::MIME, $result->markdown($input), $input->sourceUrl, '42', 'freshdesk', $metadata);

        return [$source, $state, $installation, $case];
    }

    private function embeddings(?callable $during = null): void
    {
        $cache = Mockery::mock(EmbeddingCacheService::class);
        $cache->shouldReceive('generate')->andReturnUsing(function ($texts) use ($during) {
            $during?->__invoke();

            return new EmbeddingsResponse(array_fill(0, count($texts), [1.0, 0.0, 0.0]), 'test', 'test-model');
        });
        $this->app->instance(EmbeddingCacheService::class, $cache);
    }

    public function test_atomic_case_chunk_is_indexed_as_auto_and_source_reader_opens_original_quotes(): void
    {
        [$source, $state] = $this->fixture();
        $this->embeddings();
        $document = app(DocumentIngestor::class)->ingest('support', $source, 'Caso #42');
        $this->assertSame(FreshdeskCaseStudyLineage::SOURCE_TYPE, $document->source_type);
        $this->assertSame('auto', $document->generation_source);
        $chunks = $document->chunks()->orderBy('chunk_order')->get();
        $this->assertCount(2, $chunks);
        foreach (['**Problema:**', '**Causa:**', '**Intervento:**', '**Esito:**'] as $label) {
            $this->assertStringContainsString($label, $chunks[0]->chunk_text);
        }
        $candidate = ['document' => ['id' => $document->id], 'chunk_id' => $chunks[0]->id];
        $read = app(KbSourceReader::class)->readCandidate($candidate, 'support');
        $this->assertStringContainsString('> Abbiamo riavviato il servizio.', $read['excerpt']);
        $this->assertStringNotContainsString('Riavvio del servizio.', $read['excerpt']);
        $this->assertSame('original_freshdesk_quotes', $read['evidence_origin']);
        $state->update(['fingerprint' => str_repeat('b', 64)]);
        $this->assertNull(app(KbSourceReader::class)->readCandidate($candidate, 'support'));
        $this->assertCount(0, app(KbSearchService::class)->filterByFolderGlobs($chunks->load('document'), []));
        Http::assertNothingSent();
    }

    public function test_updated_parent_during_embedding_is_rejected_at_commit(): void
    {
        [$source, $state] = $this->fixture();
        $this->embeddings(fn () => $state->update(['fingerprint' => str_repeat('b', 64)]));
        try {
            app(DocumentIngestor::class)->ingest('support', $source, 'Caso #42');
            $this->fail('Stale case was persisted.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('stale', $exception->getMessage());
            $this->assertSame(0, KnowledgeDocument::count());
        }
    }

    public function test_reconfiguration_revokes_case_and_cross_tenant_metadata_cannot_authorize_ingestion(): void
    {
        [$source, , $installation] = $this->fixture();
        $lineage = app(FreshdeskCaseStudyLineage::class);
        $this->assertNotNull($lineage->assertValid($source->metadata, 'support'));
        $installation->update(['config_json' => ['connection' => ['domain' => 'example.freshdesk.com'], 'include_private_notes' => false]]);
        try {
            $lineage->assertValid($source->metadata, 'support');
            $this->fail('Revoked note policy accepted.');
        } catch (\RuntimeException) {
            $this->assertTrue(true);
        }
        app(TenantContext::class)->set('other-tenant');
        $this->expectException(\RuntimeException::class);
        $lineage->assertValid($source->metadata, 'support');
    }

    public function test_account_case_switch_blocks_retrieval_and_restores_it_without_reingestion(): void
    {
        [$source, , $installation] = $this->fixture();
        $this->embeddings();
        $document = app(DocumentIngestor::class)->ingest('support', $source, 'Caso #42');
        $chunks = $document->chunks()->with('document')->get();
        $candidate = ['document' => ['id' => $document->id], 'chunk_id' => $chunks[0]->id];
        $installation->update(['config_json' => array_replace_recursive($installation->config_json, ['case_studies' => ['enabled' => false]])]);
        $this->assertNull(app(KbSourceReader::class)->readCandidate($candidate, 'support'));
        $this->assertCount(0, app(KbSearchService::class)->filterByFolderGlobs($chunks, []));
        $this->assertSame(1, KnowledgeDocument::count());
        $installation->update(['config_json' => array_replace_recursive($installation->config_json, ['case_studies' => ['enabled' => true], 'ingestion' => ['enabled' => false]])]);
        $this->assertNotNull(app(KbSourceReader::class)->readCandidate($candidate, 'support'));
        $this->assertCount(2, app(KbSearchService::class)->filterByFolderGlobs($chunks, []));
        Http::assertNothingSent();
    }

    public function test_case_file_cannot_override_its_verified_ledger_facts(): void
    {
        [$source] = $this->fixture();
        $source = new SourceDocument($source->sourcePath, $source->mimeType, $source->bytes.' invented fact', null, null, 'freshdesk', $source->metadata);
        $this->expectException(\RuntimeException::class);
        app(DocumentIngestor::class)->ingest('support', $source, 'Forged case');
    }
}
