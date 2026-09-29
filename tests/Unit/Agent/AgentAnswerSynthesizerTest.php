<?php

declare(strict_types=1);

namespace Tests\Unit\Agent;

use App\Agent\AgentAnswerSynthesizer;
use App\Agent\Artifacts\AgentTableArtifactFactory;
use App\Agent\AgentExecutionContext;
use App\Agent\AgentLoopOutcome;
use App\Agent\Evidence\AgentEvidenceFactory;
use App\Agent\Grounding\AgentClaimGroundingValidator;
use App\Agent\Tools\AgentToolDefinition;
use App\Ai\AiManager;
use App\Ai\AiResponse;
use App\Services\Widget\WidgetPiiMasker;
use Mockery;
use Tests\TestCase;

final class AgentAnswerSynthesizerTest extends TestCase
{
    public function test_accepting_the_offer_reads_the_previously_cited_email(): void
    {
        $content = "Oggetto: Reclamo SPD-51230\nLa consegna è avvenuta a Messina anziché a Catania.";
        $evidence = app(AgentEvidenceFactory::class)->empty();
        $evidence->addDocument([
            'document_id' => 252, 'title' => 'Reclamo', 'source_path' => 'mail/reclamo.eml', 'origin' => 'primary',
            'evidence' => [['content' => $content, 'evidence_hash' => 'email-hash']],
        ]);
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldNotReceive('chatWithHistory');
        $answer = (new AgentAnswerSynthesizer($ai, app(WidgetPiiMasker::class), app(AgentTableArtifactFactory::class), app(AgentClaimGroundingValidator::class)))
            ->synthesize('ok', $this->context(), new AgentLoopOutcome('answer', $evidence, []),
                json_encode(['previous_runs' => [['answer' => 'Vuoi leggere la fonte completa o chiedere un dettaglio preciso?']]], JSON_THROW_ON_ERROR),
                ['available' => true, 'language' => 'it', 'intent' => 'Conferma', 'mentions' => []]);

        $this->assertStringContainsString($content, $answer->answer);
        $this->assertSame([252], array_column($answer->citations, 'document_id'));
    }

    public function test_a_repeat_fallback_keeps_the_verified_citation_for_the_next_turn(): void
    {
        $content = 'La spedizione SPD-51230 è stata consegnata a Messina.';
        $evidence = app(AgentEvidenceFactory::class)->empty();
        $evidence->addDocument([
            'document_id' => 252, 'title' => 'Reclamo', 'source_path' => 'mail/reclamo.eml', 'origin' => 'primary',
            'evidence' => [['content' => $content, 'evidence_hash' => 'email-hash']],
        ]);
        $response = new AiResponse(content: '', provider: 'fake', model: 'fake-agent', toolCalls: [[
            'name' => 'submit_agent_answer', 'arguments' => [
                'completeness' => 'complete',
                'claims' => [[
                    'text' => $content, 'quote' => $content, 'document_id' => 252,
                    'tool_execution_id' => null, 'evidence_hash' => 'email-hash',
                ]],
                'limitations' => [], 'requires_selection' => false, 'render_table' => false,
            ],
        ]]);
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->twice()->andReturn($response);
        $answer = (new AgentAnswerSynthesizer($ai, app(WidgetPiiMasker::class), app(AgentTableArtifactFactory::class), app(AgentClaimGroundingValidator::class)))
            ->synthesize('Parlami di quella di Messina', $this->context(), new AgentLoopOutcome('answer', $evidence, []),
                json_encode(['previous_runs' => [['answer' => $content, 'locale' => 'it']]], JSON_THROW_ON_ERROR),
                ['available' => true, 'language' => null, 'intent' => 'Dettagli del reclamo', 'mentions' => ['Messina']]);

        $this->assertSame('it', $answer->locale);
        $this->assertStringContainsString('fonte completa', $answer->answer);
        $this->assertSame([252], array_column($answer->citations, 'document_id'));
    }


    public function test_it_detects_a_follow_up_that_rephrases_most_of_the_previous_answer(): void
    {
        $synthesizer = new AgentAnswerSynthesizer(
            Mockery::mock(AiManager::class), app(WidgetPiiMasker::class),
            app(AgentTableArtifactFactory::class), app(AgentClaimGroundingValidator::class),
        );
        $previous = 'La spedizione SPD-51230, partita il 9 agosto da Bergamo, è stata consegnata a un indirizzo di Messina invece che alla filiale di Catania. Laura Caruso ha segnalato l’errore e ha chiesto di aprire un reclamo urgente per recuperare la merce e consegnarla correttamente.';
        $current = 'In caso di errore di consegna, è necessario aprire un reclamo urgente. Laura Caruso ha segnalato che la spedizione SPD-51230, partita il 9 agosto da Bergamo, è stata consegnata a un indirizzo di Messina invece che alla filiale di Catania. Ha richiesto di recuperare la merce e consegnarla correttamente, poiché il cliente finale sta sollecitando.';

        $this->assertTrue((new \ReflectionMethod($synthesizer, 'sameAnswer'))->invoke($synthesizer, $current, $previous));
    }

    public function test_a_follow_up_repairs_a_verbatim_repeat_with_new_grounded_detail(): void
    {
        $content = 'La spedizione SPD-51230 è stata consegnata a Messina. I documenti di trasporto indicavano correttamente Catania.';
        $evidence = app(AgentEvidenceFactory::class)->empty();
        $evidence->addDocument([
            'document_id' => 252, 'title' => 'Reclamo', 'source_path' => 'mail/reclamo.eml', 'origin' => 'primary',
            'evidence' => [['content' => $content, 'evidence_hash' => 'email-hash']],
        ]);
        $prior = 'La spedizione SPD-51230 è stata consegnata a Messina.';
        $focused = 'I documenti di trasporto indicavano correttamente Catania: l’errore è avvenuto nella consegna.';
        $makeResponse = static fn (string $text, string $quote): AiResponse => new AiResponse(
            content: '', provider: 'fake', model: 'fake-agent', toolCalls: [[
                'name' => 'submit_agent_answer',
                'arguments' => [
                    'completeness' => 'complete',
                    'claims' => [[
                        'text' => $text, 'quote' => $quote, 'document_id' => 252,
                        'tool_execution_id' => null, 'evidence_hash' => 'email-hash',
                    ]],
                    'limitations' => [], 'requires_selection' => false, 'render_table' => false,
                ],
            ]],
        );
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->twice()->andReturn(
            $makeResponse($prior, 'La spedizione SPD-51230 è stata consegnata a Messina.'),
            $makeResponse($focused, 'I documenti di trasporto indicavano correttamente Catania.'),
        );

        $answer = (new AgentAnswerSynthesizer($ai, app(WidgetPiiMasker::class), app(AgentTableArtifactFactory::class), app(AgentClaimGroundingValidator::class)))
            ->synthesize('Parlami di quella di Messina', $this->context(), new AgentLoopOutcome('answer', $evidence, []),
                json_encode(['previous_runs' => [['answer' => $prior]]], JSON_THROW_ON_ERROR),
                ['available' => true, 'language' => 'it', 'mentions' => []]);

        $this->assertSame($focused, $answer->answer);
        $this->assertSame(252, $answer->citations[0]['document_id']);
    }

    public function test_it_reads_the_single_cited_email_without_summarizing_or_calling_the_model(): void
    {
        $content = "Oggetto: Consegna SPD-51230\nLa spedizione è arrivata a Messina anziché a Catania.";
        $evidence = app(AgentEvidenceFactory::class)->empty();
        $evidence->addDocument([
            'document_id' => 252,
            'title' => 'Reclamo consegna',
            'source_path' => 'mail/reclamo.eml',
            'origin' => 'primary',
            'evidence' => [[
                'content' => $content,
                'evidence_hash' => 'email-hash',
            ]],
        ]);
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldNotReceive('chatWithHistory');

        $answer = (new AgentAnswerSynthesizer($ai, app(WidgetPiiMasker::class), app(AgentTableArtifactFactory::class), app(AgentClaimGroundingValidator::class)))
            ->synthesize('Quella di Messina me la fai leggere?', $this->context(), new AgentLoopOutcome('answer', $evidence, []), null, [
                'available' => true, 'language' => 'it', 'intent' => 'Richiesta di lettura del reclamo', 'mentions' => ['Messina'],
            ]);

        $this->assertSame('complete', $answer->completeness);
        $this->assertStringContainsString($content, $answer->answer);
        $this->assertSame(252, $answer->citations[0]['document_id']);
        $this->assertSame('deterministic_source_read', $answer->grounding['model']);
    }

    public function test_it_returns_a_cautious_uncited_answer_for_an_unattested_entity(): void
    {
        $evidence = app(AgentEvidenceFactory::class)->empty();
        $evidence->addDocument([
            'document_id' => 90,
            'title' => 'Notifiche push',
            'source_path' => 'manual/push.md',
            'origin' => 'primary',
            'evidence' => [[
                'content' => 'Le notifiche push raggiungono i clienti nell’app.',
                'evidence_hash' => 'push-hash',
            ]],
        ]);
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->once()->andReturn(new AiResponse(
            content: '', provider: 'fake', model: 'fake-agent', toolCalls: [[
                'name' => 'submit_agent_answer',
                'arguments' => [
                    'completeness' => 'complete',
                    'claims' => [[
                        'text' => 'Figo invia notifiche push ai clienti.',
                        'quote' => 'Le notifiche push raggiungono i clienti nell’app.',
                        'document_id' => 90,
                        'tool_execution_id' => null,
                        'evidence_hash' => 'push-hash',
                    ]],
                    'limitations' => [], 'requires_selection' => false, 'render_table' => false,
                ],
            ]],
        ));

        $answer = (new AgentAnswerSynthesizer($ai, app(WidgetPiiMasker::class), app(AgentTableArtifactFactory::class), app(AgentClaimGroundingValidator::class)))
            ->synthesize('Figo e come funziona?', $this->context(), new AgentLoopOutcome('answer', $evidence, []), null, [
                'available' => true, 'language' => 'it', 'mentions' => ['Figo'],
            ]);

        $this->assertSame('insufficient', $answer->completeness);
        $this->assertSame([], $answer->citations);
        $this->assertStringContainsString('Figo', $answer->answer);
        $this->assertSame('unattested_entity', $answer->grounding['reason']);
    }

    /**
     * Regression for the "Fammi un riassunto sintetico dei manuali che
     * abbiamo" case: a catalog/overview request must be answerable as
     * prose (render_table=false) built from list_knowledge_documents
     * evidence — one claim per document, quoting its title — not blocked
     * by the claim-grounding contract just because the request word is
     * "riassunto" (summary) rather than a literal quote of any one document.
     */
    public function test_it_builds_a_multi_claim_overview_from_a_document_catalog_result(): void
    {
        $evidence = app(AgentEvidenceFactory::class)->empty();
        $catalogResult = [
            'count' => 2,
            'documents' => [
                ['id' => 1, 'title' => 'SizeCharts Manual', 'source_path' => 'docs/sizecharts.md', 'source_type' => 'markdown', 'is_canonical' => false, 'canonical_type' => null, 'generation_source' => 'auto', 'summary' => 'Guida alla gestione delle taglie prodotto.'],
                ['id' => 2, 'title' => 'Manuale Fatturazione', 'source_path' => 'docs/fatturazione.md', 'source_type' => 'markdown', 'is_canonical' => false, 'canonical_type' => null, 'generation_source' => 'auto', 'summary' => null],
            ],
        ];
        $tool = new AgentToolDefinition(
            name: 'list_knowledge_documents',
            displayName: 'Document catalog',
            description: 'List indexed documents by title.',
            kind: 'catalog',
            inputSchema: ['type' => 'object'],
            readOnly: true,
            idempotent: true,
            physicalMinimum: 0,
            physicalLikely: 0,
            physicalMaximum: 0,
            executorReference: 'catalog',
        );
        $evidence->addToolResult($tool, [], $catalogResult, 90);
        $catalogHash = hash('sha256', (string) json_encode($catalogResult, JSON_UNESCAPED_UNICODE));

        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->once()->andReturn(new AiResponse(
            content: '',
            provider: 'fake',
            model: 'fake-agent',
            toolCalls: [[
                'name' => 'submit_agent_answer',
                'arguments' => [
                    'completeness' => 'complete',
                    'claims' => [
                        [
                            'text' => '**SizeCharts Manual** — guida alla gestione delle taglie prodotto.',
                            'quote' => 'SizeCharts Manual',
                            'document_id' => null,
                            'tool_execution_id' => 90,
                            'evidence_hash' => $catalogHash,
                        ],
                        [
                            'text' => '**Manuale Fatturazione** — copre i processi di fatturazione.',
                            'quote' => 'Manuale Fatturazione',
                            'document_id' => null,
                            'tool_execution_id' => 90,
                            'evidence_hash' => $catalogHash,
                        ],
                    ],
                    'limitations' => [],
                    'requires_selection' => false,
                    'render_table' => false,
                ],
            ]],
        ));

        $answer = (new AgentAnswerSynthesizer(
            $ai,
            app(WidgetPiiMasker::class),
            app(AgentTableArtifactFactory::class),
            app(AgentClaimGroundingValidator::class),
        ))->synthesize('Fammi un riassunto sintetico dei manuali che abbiamo', $this->context(), new AgentLoopOutcome('answer', $evidence, []));

        $this->assertSame('complete', $answer->completeness);
        $this->assertNull($answer->artifact);
        $this->assertStringContainsString('SizeCharts Manual', $answer->answer);
        $this->assertStringContainsString('Manuale Fatturazione', $answer->answer);
        // One evidence entry (a single addToolResult() call) even though two
        // claims reference it — selectedTools() dedupes by the tool, not the claim.
        $this->assertSame([90], array_column($answer->toolSources, 'execution_id'));
    }

    /**
     * Reproduces the deterministic-refusal bug behind the repeated "Fammi un
     * riassunto sintetico dei manuali che abbiamo" reports: a
     * list_knowledge_documents result's OWN payload has a "documents" array
     * with per-row "id" fields — structurally similar enough to the real
     * evidence.documents[] that a claim can plausibly cite that inner id as
     * document_id instead of the tool's execution_id. That id was never
     * added via addDocument(), so AgentClaimGroundingValidator can never
     * find it and fails 'quote_not_in_chunk' — the SAME reason, every
     * time, regardless of how many searches actually ran. This pins the
     * failure mode explicitly (rather than only fixing the prompt text
     * that asks the model not to do this) so a regression here is caught
     * even though the prompt wording itself isn't directly testable.
     */
    public function test_citing_a_catalog_rows_inner_id_as_document_id_is_rejected(): void
    {
        $evidence = app(AgentEvidenceFactory::class)->empty();
        $catalogResult = [
            'count' => 1,
            'documents' => [
                ['id' => 1, 'title' => 'SizeCharts Manual', 'summary' => 'Guida taglie.'],
            ],
        ];
        $tool = new AgentToolDefinition(
            name: 'list_knowledge_documents',
            displayName: 'Document catalog',
            description: 'List indexed documents by title.',
            kind: 'catalog',
            inputSchema: ['type' => 'object'],
            readOnly: true,
            idempotent: true,
            physicalMinimum: 0,
            physicalLikely: 0,
            physicalMaximum: 0,
            executorReference: 'catalog',
        );
        $evidence->addToolResult($tool, [], $catalogResult, 91);
        $catalogHash = hash('sha256', (string) json_encode($catalogResult, JSON_UNESCAPED_UNICODE));

        $invalidResponse = new AiResponse(
            content: '',
            provider: 'fake',
            model: 'fake-agent',
            toolCalls: [[
                'name' => 'submit_agent_answer',
                'arguments' => [
                    'completeness' => 'complete',
                    'claims' => [[
                        'text' => '**SizeCharts Manual** — guida alla gestione delle taglie.',
                        'quote' => 'SizeCharts Manual',
                        // Wrong: the catalog row's own "id" (1), NOT the
                        // tool's execution_id (91) — exactly the mistake
                        // the fixed prompt now explicitly forbids.
                        'document_id' => 1,
                        'tool_execution_id' => null,
                        'evidence_hash' => $catalogHash,
                    ]],
                    'limitations' => [],
                    'requires_selection' => false,
                    'render_table' => false,
                ],
            ]],
        );
        $repairResponse = new AiResponse(
            content: '',
            provider: 'fake',
            model: 'fake-repair',
            toolCalls: [[
                'name' => 'repair_agent_claims',
                'arguments' => ['claims' => [[
                    'text' => '**SizeCharts Manual** — guida alla gestione delle taglie.',
                    'quote' => 'SizeCharts Manual',
                    'document_id' => 1,
                    'tool_execution_id' => null,
                    'evidence_hash' => $catalogHash,
                ]]],
            ]],
        );
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->twice()->andReturn($invalidResponse, $repairResponse);

        $answer = (new AgentAnswerSynthesizer(
            $ai,
            app(WidgetPiiMasker::class),
            app(AgentTableArtifactFactory::class),
            app(AgentClaimGroundingValidator::class),
        ))->synthesize('Fammi un riassunto sintetico dei manuali che abbiamo', $this->context(), new AgentLoopOutcome('answer', $evidence, []));

        $this->assertSame('insufficient', $answer->completeness);
        $this->assertSame('quote_not_in_chunk', $answer->grounding['reason']);
        $this->assertSame('rejected', $answer->grounding['repair']['status']);
    }

    public function test_it_repairs_a_claim_with_an_ambiguous_source_identity(): void
    {
        $evidence = app(AgentEvidenceFactory::class)->empty();
        $evidence->addDocument([
            'document_id' => 90,
            'title' => 'Profilo aziendale',
            'source_path' => 'company/profile.md',
            'origin' => 'primary',
            'evidence' => [[
                'content' => 'Rotta Sicura Logistics gestisce spedizioni B2B e B2C.',
                'evidence_hash' => 'company-hash',
            ]],
        ]);
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->twice()->andReturn(
            new AiResponse(content: '', provider: 'fake', model: 'fake-agent', toolCalls: [[
                'name' => 'submit_agent_answer',
                'arguments' => [
                    'completeness' => 'complete',
                    'claims' => [[
                        'text' => 'Rotta Sicura Logistics gestisce spedizioni B2B e B2C.',
                        'quote' => 'Rotta Sicura Logistics gestisce spedizioni B2B e B2C.',
                        'document_id' => 90,
                        'tool_execution_id' => 77,
                        'evidence_hash' => 'company-hash',
                    ]],
                    'limitations' => [], 'requires_selection' => false, 'render_table' => false,
                ],
            ]]),
            new AiResponse(content: '', provider: 'fake', model: 'fake-repair', toolCalls: [[
                'name' => 'repair_agent_claims',
                'arguments' => ['claims' => [[
                    'text' => 'Rotta Sicura Logistics gestisce spedizioni B2B e B2C.',
                    'quote' => 'Rotta Sicura Logistics gestisce spedizioni B2B e B2C.',
                    'document_id' => 90,
                    'tool_execution_id' => null,
                    'evidence_hash' => 'company-hash',
                ]]],
            ]]),
        );

        $answer = (new AgentAnswerSynthesizer(
            $ai,
            app(WidgetPiiMasker::class),
            app(AgentTableArtifactFactory::class),
            app(AgentClaimGroundingValidator::class),
        ))->synthesize('Cosa facciamo in questa azienda?', $this->context(), new AgentLoopOutcome('answer', $evidence, []));

        $this->assertSame('complete', $answer->completeness);
        $this->assertSame('Rotta Sicura Logistics gestisce spedizioni B2B e B2C.', $answer->answer);
        $this->assertSame([90], array_column($answer->citations, 'document_id'));
        $this->assertSame('repaired', $answer->grounding['repair']['status']);
        $this->assertSame('invalid_claim_source', $answer->grounding['repair']['initial_reason']);
        $this->assertSame('fake-repair', $answer->grounding['repair']['repair_model']);
    }

    public function test_it_repairs_a_paraphrased_quote_against_the_selected_chunk(): void
    {
        $evidence = app(AgentEvidenceFactory::class)->empty();
        $evidence->addDocument([
            'document_id' => 12,
            'title' => 'Politica resi',
            'source_path' => 'policy/returns.md',
            'origin' => 'primary',
            'evidence' => [[
                'content' => 'La giacenza standard è mantenuta per un massimo di 10 giorni lavorativi.',
                'evidence_hash' => 'returns-hash',
            ]],
        ]);
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->twice()->andReturn(
            new AiResponse(content: '', provider: 'fake', model: 'fake-agent', toolCalls: [[
                'name' => 'submit_agent_answer',
                'arguments' => [
                    'completeness' => 'complete',
                    'claims' => [[
                        'text' => 'La giacenza dura dieci giorni lavorativi.',
                        'quote' => 'La giacenza dura dieci giorni lavorativi.',
                        'document_id' => 12,
                        'tool_execution_id' => null,
                        'evidence_hash' => 'returns-hash',
                    ]],
                    'limitations' => [], 'requires_selection' => false, 'render_table' => false,
                ],
            ]]),
            new AiResponse(content: '', provider: 'fake', model: 'fake-repair', toolCalls: [[
                'name' => 'repair_agent_claims',
                'arguments' => ['claims' => [[
                    'text' => 'La giacenza standard è mantenuta per un massimo di 10 giorni lavorativi.',
                    'quote' => 'La giacenza standard è mantenuta per un massimo di 10 giorni lavorativi.',
                    'document_id' => 12,
                    'tool_execution_id' => null,
                    'evidence_hash' => 'returns-hash',
                ]]],
            ]]),
        );

        $answer = (new AgentAnswerSynthesizer(
            $ai,
            app(WidgetPiiMasker::class),
            app(AgentTableArtifactFactory::class),
            app(AgentClaimGroundingValidator::class),
        ))->synthesize('Cosa si fa in caso di un reso?', $this->context(), new AgentLoopOutcome('answer', $evidence, []));

        $this->assertSame('complete', $answer->completeness);
        $this->assertSame('quote_not_in_chunk', $answer->grounding['repair']['initial_reason']);
        $this->assertSame('repaired', $answer->grounding['repair']['status']);
    }

    public function test_it_repairs_a_short_voice_follow_up_without_treating_it_as_an_entity(): void
    {
        $evidence = app(AgentEvidenceFactory::class)->empty();
        $evidence->addDocument([
            'document_id' => 29,
            'title' => 'Rete hub',
            'source_path' => 'company/hubs.md',
            'origin' => 'primary',
            'evidence' => [[
                'content' => 'La rete operativa si articola su **tre hub regionali**, ciascuno con una specializzazione.',
                'evidence_hash' => 'hubs-hash',
            ]],
        ]);
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->twice()->andReturn(
            new AiResponse(content: '', provider: 'fake', model: 'fake-agent', toolCalls: [[
                'name' => 'submit_agent_answer',
                'arguments' => [
                    'completeness' => 'complete',
                    'claims' => [],
                    'limitations' => [], 'requires_selection' => false, 'render_table' => false,
                ],
            ]]),
            new AiResponse(content: '', provider: 'fake', model: 'fake-repair', toolCalls: [[
                'name' => 'repair_agent_claims',
                'arguments' => ['claims' => [[
                    'text' => 'La rete operativa si articola su **tre hub regionali**.',
                    'quote' => 'La rete operativa si articola su **tre hub regionali**.',
                    'document_id' => 29,
                    'tool_execution_id' => null,
                    'evidence_hash' => 'hubs-hash',
                ]]],
            ]]),
        );

        $answer = (new AgentAnswerSynthesizer(
            $ai,
            app(WidgetPiiMasker::class),
            app(AgentTableArtifactFactory::class),
            app(AgentClaimGroundingValidator::class),
        ))->synthesize('Trovato niente?', $this->context(), new AgentLoopOutcome('answer', $evidence, []));

        $this->assertSame('complete', $answer->completeness);
        $this->assertSame('La rete operativa si articola su **tre hub regionali**.', $answer->answer);
        $this->assertSame('repaired', $answer->grounding['repair']['status']);
    }

    public function test_selection_does_not_force_a_detail_result_into_a_table(): void
    {
        $evidence = app(AgentEvidenceFactory::class)->empty();
        $evidence->addDocument([
            'document_id' => 12,
            'title' => 'Policy ordini',
            'source_path' => 'policy/orders.md',
            'origin' => 'primary',
            'evidence' => [['content' => 'Gli ordini pagati sono definitivi.', 'evidence_hash' => 'doc-hash']],
        ]);
        $tool = new AgentToolDefinition(
            name: 'get_orders',
            displayName: 'Ordini ERP',
            description: 'Ordini aggiornati',
            kind: 'api',
            inputSchema: ['type' => 'object'],
            readOnly: true,
            idempotent: true,
            physicalMinimum: 1,
            physicalLikely: 1,
            physicalMaximum: 1,
            executorReference: 9,
        );
        $evidence->addToolResult($tool, ['order_id' => 'A-100'], [
            'data' => [
                'number' => 'A-100',
                'status' => 'paid',
                'line_items' => [
                    ['id' => 'LINE-1', 'name' => 'First item'],
                    ['id' => 'LINE-2', 'name' => 'Second item'],
                ],
            ],
        ], 55);

        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->once()->andReturn(new AiResponse(
            content: '',
            provider: 'fake',
            model: 'fake-agent',
            toolCalls: [[
                'name' => 'submit_agent_answer',
                'arguments' => [
                    'completeness' => 'complete',
                    'claims' => [
                        ['text' => 'Gli ordini pagati sono definitivi.', 'quote' => 'Gli ordini pagati sono definitivi.', 'document_id' => 12, 'tool_execution_id' => null, 'evidence_hash' => 'doc-hash'],
                        ['text' => 'L’ordine A-100 è paid.', 'quote' => '"number":"A-100","status":"paid"', 'document_id' => null, 'tool_execution_id' => 55, 'evidence_hash' => hash('sha256', json_encode(['data' => ['number' => 'A-100', 'status' => 'paid', 'line_items' => [['id' => 'LINE-1', 'name' => 'First item'], ['id' => 'LINE-2', 'name' => 'Second item']]]], JSON_UNESCAPED_UNICODE))],
                    ],
                    'limitations' => ['Non mostrare Bearer eyJabcdefghijk.abcdefghijklmnopqrstu'],
                    'requires_selection' => false,
                    'render_table' => false,
                ],
            ]],
        ));

        $answer = (new AgentAnswerSynthesizer(
            $ai,
            app(WidgetPiiMasker::class),
            app(AgentTableArtifactFactory::class),
            app(AgentClaimGroundingValidator::class),
        ))->synthesize(
            'Dammi il dettaglio dell’ordine selezionato',
            $this->context(),
            new AgentLoopOutcome('answer', $evidence, []),
            json_encode(['current_selection' => ['record' => ['id' => 77]]], JSON_THROW_ON_ERROR),
        );

        $this->assertSame('it-IT', $answer->locale);
        $this->assertSame('complete', $answer->completeness);
        $this->assertSame([12], array_column($answer->citations, 'document_id'));
        $this->assertSame([55], array_column($answer->toolSources, 'execution_id'));
        $this->assertArrayNotHasKey('result', $answer->toolSources[0]);
        $this->assertStringContainsString('A-100', $answer->answer);
        $this->assertStringNotContainsString('admin@example.com', $answer->answer);
        $this->assertStringContainsString('Bearer [TOKEN]', $answer->limitations[0]);
        $this->assertNull($answer->artifact);
    }

    public function test_it_marks_ambiguous_singular_results_as_a_selectable_table(): void
    {
        $evidence = app(AgentEvidenceFactory::class)->empty();
        $tool = new AgentToolDefinition(
            name: 'search_customers',
            displayName: 'Search customers',
            description: 'Search matching customers',
            kind: 'api',
            inputSchema: ['type' => 'object'],
            readOnly: true,
            idempotent: true,
            physicalMinimum: 1,
            physicalLikely: 1,
            physicalMaximum: 1,
            executorReference: 10,
        );
        $evidence->addToolResult($tool, ['query' => 'Riccardo Lorini'], ['items' => [
            ['id' => 101, 'name' => 'Riccardo Lorini'],
            ['id' => 102, 'name' => 'Riccardo Lorini'],
        ]], 56);

        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->once()->andReturn(new AiResponse(
            content: '',
            provider: 'fake',
            model: 'fake-agent',
            toolCalls: [[
                'name' => 'submit_agent_answer',
                'arguments' => [
                    'completeness' => 'complete',
                    'claims' => [],
                    'limitations' => [],
                    // The runtime ambiguity guard must override a mistaken model classification.
                    'requires_selection' => false,
                    'render_table' => true,
                ],
            ]],
        ));

        $answer = (new AgentAnswerSynthesizer(
            $ai,
            app(WidgetPiiMasker::class),
            app(AgentTableArtifactFactory::class),
            app(AgentClaimGroundingValidator::class),
        ))->synthesize(
            'Cerca Riccardo Lorini',
            $this->context(),
            new AgentLoopOutcome('answer', $evidence, [], 'ambiguous_selection_required'),
        );

        $this->assertTrue($answer->requiresSelection);
        $this->assertSame(
            'Ho trovato più risultati possibili: scegli una riga per continuare.',
            $answer->answer,
        );
        $this->assertSame('selection', data_get($answer->artifact, 'interaction_mode'));
        $this->assertSame(['101', '102'], array_column(data_get($answer->artifact, 'rows'), 'key'));
    }

    public function test_it_never_repeats_collection_rows_in_text_when_an_artifact_is_rendered(): void
    {
        $evidence = app(AgentEvidenceFactory::class)->empty();
        $tool = new AgentToolDefinition(
            name: 'list_orders',
            displayName: 'Orders list',
            description: 'Latest orders',
            kind: 'mcp',
            inputSchema: ['type' => 'object'],
            readOnly: true,
            idempotent: true,
            physicalMinimum: 1,
            physicalLikely: 1,
            physicalMaximum: 1,
            executorReference: 11,
        );
        $evidence->addToolResult($tool, [], ['orders' => [
            ['public_id' => 'ORDER-100', 'status' => 'paid', 'total' => 120],
            ['public_id' => 'ORDER-101', 'status' => 'pending', 'total' => 80],
        ]], 57);

        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithHistory')->once()->andReturn(new AiResponse(
            content: '',
            provider: 'fake',
            model: 'fake-agent',
            toolCalls: [[
                'name' => 'submit_agent_answer',
                'arguments' => [
                    'completeness' => 'complete',
                    'claims' => [],
                    'limitations' => [],
                    'requires_selection' => false,
                    'render_table' => true,
                ],
            ]],
        ));

        $answer = (new AgentAnswerSynthesizer(
            $ai,
            app(WidgetPiiMasker::class),
            app(AgentTableArtifactFactory::class),
            app(AgentClaimGroundingValidator::class),
        ))->synthesize(
            'Mostrami gli ultimi ordini',
            $this->context(),
            new AgentLoopOutcome('answer', $evidence, []),
        );

        $this->assertSame(
            'Ho organizzato i risultati nella tabella qui sotto: apri una riga per vedere i dettagli.',
            $answer->answer,
        );
        $this->assertStringNotContainsString('ORDER-100', $answer->answer);
        $this->assertSame('view', data_get($answer->artifact, 'interaction_mode'));
        $this->assertSame('ORDER-100', data_get($answer->artifact, 'rows.0.values.public_id'));
    }

    private function context(): AgentExecutionContext
    {
        return new AgentExecutionContext(
            runId: 'b03a7c27-daae-43cb-8ea2-fbe85cf66aaf',
            tenantId: 'acme',
            projectKey: 'crm',
            channel: 'chat',
            actorType: 'user',
            actorId: '1',
            locale: 'it-IT',
            timezone: 'Europe/Rome',
        );
    }
}
