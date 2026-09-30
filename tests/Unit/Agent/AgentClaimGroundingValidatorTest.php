<?php

declare(strict_types=1);

namespace Tests\Unit\Agent;

use App\Agent\Grounding\AgentClaimGroundingValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class AgentClaimGroundingValidatorTest extends TestCase
{
    /** A structured name at the start of the question still needs a source. */
    public function test_it_blocks_an_unattested_named_entity_even_when_context_is_related(): void
    {
        $result = $this->validator()->validate('Figo e come funziona?', $this->evidence(), [[
            'text' => 'Le notifiche push raggiungono i clienti nell’app.',
            'quote' => 'Le notifiche push raggiungono i clienti nell’app.',
            'document_id' => 7,
            'tool_execution_id' => null,
            'evidence_hash' => 'push-hash',
        ]], ['Figo']);

        $this->assertFalse($result['valid']);
        $this->assertSame('unattested_entity', $result['reason']);
        $this->assertSame(['Figo'], $result['terms']);
    }

    /**
     * Regression — "Parlami del pannello delle notifiche push" was wrongly
     * blocked with reason `unattested_entity` / term `Parlami`: the sentence-
     * initial capital on an ordinary imperative-plus-clitic verb ("tell me
     * about...") was being read as a proper-noun entity that had to be
     * attested in a claim quote, when it never names anything.
     */
    public function test_it_does_not_treat_a_sentence_opening_imperative_as_an_entity(): void
    {
        $result = $this->validator()->validate('Parlami del pannello delle notifiche push', $this->evidence(), [[
            'text' => 'Le notifiche push raggiungono i clienti nell’app.',
            'quote' => 'Le notifiche push raggiungono i clienti nell’app.',
            'document_id' => 7,
            'tool_execution_id' => null,
            'evidence_hash' => 'push-hash',
        ]]);

        $this->assertTrue($result['valid']);
        $this->assertSame([], $result['terms']);
    }

    #[DataProvider('sentenceOpeningImperativesProvider')]
    public function test_it_ignores_other_common_sentence_opening_imperatives(string $opener): void
    {
        $result = $this->validator()->validate("{$opener} il pannello delle notifiche push", $this->evidence(), [[
            'text' => 'Le notifiche push raggiungono i clienti nell’app.',
            'quote' => 'Le notifiche push raggiungono i clienti nell’app.',
            'document_id' => 7,
            'tool_execution_id' => null,
            'evidence_hash' => 'push-hash',
        ]]);

        $this->assertTrue($result['valid'], "Expected '{$opener}' not to be treated as an entity");
    }

    /** @return array<string, array{string}> */
    public static function sentenceOpeningImperativesProvider(): array
    {
        return [
            'raccontami' => ['Raccontami'],
            'spiegami' => ['Spiegami'],
            'descrivimi' => ['Descrivimi'],
            'mostrami' => ['Mostrami'],
            'dimmi' => ['Dimmi di'],
        ];
    }

    public function test_it_rejects_a_quote_that_is_not_in_the_selected_chunk(): void
    {
        $result = $this->validator()->validate('Come funzionano le notifiche?', $this->evidence(), [[
            'text' => 'Le notifiche sono programmate.',
            'quote' => 'Le notifiche sono programmate.',
            'document_id' => 7,
            'tool_execution_id' => null,
            'evidence_hash' => 'push-hash',
        ]]);

        $this->assertFalse($result['valid']);
        $this->assertSame('quote_not_in_chunk', $result['reason']);
    }

    public function test_it_accepts_a_quote_with_only_terminal_punctuation_changed(): void
    {
        $evidence = ['documents' => [[
            'document_id' => 29,
            'evidence' => [[
                'evidence_hash' => 'hubs-hash',
                'content' => 'La rete operativa si articola su **tre hub regionali**, ciascuno con una specializzazione.',
            ]],
        ]], 'api_tools' => []];

        $result = $this->validator()->validate('Di cosa parla questa azienda?', $evidence, [[
            'text' => 'La rete operativa si articola su **tre hub regionali**.',
            'quote' => 'La rete operativa si articola su **tre hub regionali**.',
            'document_id' => 29,
            'tool_execution_id' => null,
            'evidence_hash' => 'hubs-hash',
        ]]);

        $this->assertTrue($result['valid']);
        $this->assertSame([], $result['terms']);
    }

    public function test_it_removes_model_added_bold_only_when_plain_quote_exists_in_the_same_source(): void
    {
        $evidence = ['documents' => [[
            'document_id' => 273,
            'evidence' => [['evidence_hash' => 'source-hash', 'content' => 'Il cliente business Delta Forniture ha aperto il reclamo.']],
        ]], 'api_tools' => []];
        $result = $this->validator()->validate('Chi è il cliente?', $evidence, [[
            'text' => 'Il cliente è Delta Forniture.',
            'quote' => 'Il cliente business **Delta Forniture** ha aperto il reclamo.',
            'document_id' => 273, 'tool_execution_id' => null, 'evidence_hash' => 'source-hash',
        ]]);

        $this->assertTrue($result['valid']);
        $this->assertSame('Il cliente business Delta Forniture ha aperto il reclamo.', $result['claims'][0]['quote']);
    }

    public function test_it_does_not_treat_a_short_voice_follow_up_as_an_entity(): void
    {
        $result = $this->validator()->validate('Trovato niente?', $this->evidence(), [[
            'text' => 'Le notifiche push raggiungono i clienti nell’app.',
            'quote' => 'Le notifiche push raggiungono i clienti nell’app.',
            'document_id' => 7,
            'tool_execution_id' => null,
            'evidence_hash' => 'push-hash',
        ]]);

        $this->assertTrue($result['valid']);
        $this->assertSame([], $result['terms']);
    }

    #[DataProvider('existenceQuestionsProvider')]
    public function test_it_does_not_treat_an_existence_question_as_an_entity(string $question): void
    {
        $evidence = ['documents' => [[
            'document_id' => 187,
            'evidence' => [[
                'evidence_hash' => 'delivery-alert-hash',
                'content' => 'Il tentativo di consegna della spedizione RL-TRACK-9027 non è andato a buon fine.',
            ]],
        ]], 'api_tools' => []];

        $result = $this->validator()->validate($question, $evidence, [[
            'text' => 'Sì, c’è un avviso di mancata consegna.',
            'quote' => 'Il tentativo di consegna della spedizione RL-TRACK-9027 non è andato a buon fine.',
            'document_id' => 187,
            'tool_execution_id' => null,
            'evidence_hash' => 'delivery-alert-hash',
        ]]);

        $this->assertTrue($result['valid']);
        $this->assertSame([], $result['terms']);
    }

    /** @return array<string, array{string}> */
    public static function existenceQuestionsProvider(): array
    {
        return [
            'plural' => ['Esistono email di errori di spedizione?'],
            'singular' => ['Esiste una email di mancata consegna?'],
        ];
    }

    public function test_an_existence_question_still_requires_its_named_entity_to_be_attested(): void
    {
        $result = $this->validator()->validate('Esistono email di Figo?', $this->evidence(), [[
            'text' => 'Le notifiche push raggiungono i clienti nell’app.',
            'quote' => 'Le notifiche push raggiungono i clienti nell’app.',
            'document_id' => 7,
            'tool_execution_id' => null,
            'evidence_hash' => 'push-hash',
        ]], ['Figo']);

        $this->assertFalse($result['valid']);
        $this->assertSame('unattested_entity', $result['reason']);
        $this->assertSame(['Figo'], $result['terms']);
    }

    public function test_it_accepts_a_claim_bound_to_its_document_chunk(): void
    {
        $result = $this->validator()->validate('Come funzionano le notifiche?', $this->evidence(), [[
            'text' => 'Le notifiche push raggiungono i clienti nell’app.',
            'quote' => 'Le notifiche push raggiungono i clienti nell’app.',
            'document_id' => 7,
            'tool_execution_id' => null,
            'evidence_hash' => 'push-hash',
        ]]);

        $this->assertTrue($result['valid']);
        $this->assertSame('push-hash', $result['claims'][0]['evidence_hash']);
    }

    public function test_it_accepts_an_exact_structured_subset_of_the_same_tool_record(): void
    {
        $quote = '{"id":"PO-5582","observedAt":"2024-08-01","status":"accepted_for_dispatch","customerId":"RO-LONGO","productIds":["RO-LAMPO-24H"],"shipmentIds":["RL-2024-1120"]}';
        $result = $this->validator()->validate('Dettagli di PO-5582', $this->orderEvidence(), [[
            'text' => 'PO-5582 è stato accettato per la spedizione.',
            'quote' => $quote,
            'document_id' => null, 'tool_execution_id' => 44, 'evidence_hash' => 'order-hash',
        ]], ['PO-5582']);

        $this->assertTrue($result['valid']);
        $this->assertSame($quote, $result['claims'][0]['quote']);
    }

    public function test_structured_tool_quote_cannot_invent_a_field_or_cross_the_source_boundary(): void
    {
        $claim = [
            'text' => 'PO-5582 è stato annullato.',
            'quote' => '{"id":"PO-5582","status":"cancelled"}',
            'document_id' => null, 'tool_execution_id' => 44, 'evidence_hash' => 'order-hash',
        ];
        $this->assertSame('quote_not_in_tool_result', $this->validator()
            ->validate('Dettagli di PO-5582', $this->orderEvidence(), [$claim])['reason']);

        $claim['quote'] = '{"id":"PO-5582"}';
        $claim['evidence_hash'] = 'another-hash';
        $this->assertSame('quote_not_in_tool_result', $this->validator()
            ->validate('Dettagli di PO-5582', $this->orderEvidence(), [$claim])['reason']);

        $claim['evidence_hash'] = 'order-hash';
        $claim['tool_execution_id'] = 45;
        $this->assertSame('quote_not_in_tool_result', $this->validator()
            ->validate('Dettagli di PO-5582', $this->orderEvidence(), [$claim])['reason']);
    }

    private function validator(): AgentClaimGroundingValidator
    {
        return app(AgentClaimGroundingValidator::class);
    }

    /** @return array<string,mixed> */
    private function evidence(): array
    {
        return ['documents' => [[
            'document_id' => 7,
            'evidence' => [[
                'evidence_hash' => 'push-hash',
                'content' => 'Le notifiche push raggiungono i clienti nell’app.',
            ]],
        ]], 'api_tools' => []];
    }

    /** @return array<string,mixed> */
    private function orderEvidence(): array
    {
        return ['documents' => [], 'api_tools' => [[
            'execution_id' => 44, 'evidence_hash' => 'order-hash',
            'result' => ['records' => [[
                'id' => 'PO-5582', 'observedAt' => '2024-08-01',
                'status' => 'accepted_for_dispatch', 'customerId' => 'RO-LONGO',
                'productIds' => ['RO-LAMPO-24H'], 'shipmentIds' => ['RL-2024-1120'],
                'emailEvidence' => [['subject' => 'Conferma spedizione ordine urgente']],
            ]]],
        ]]];
    }
}
