<?php

declare(strict_types=1);

namespace Tests\Unit\Agent;

use App\Agent\Grounding\AgentClaimGroundingValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class AgentClaimGroundingValidatorTest extends TestCase
{
    /**
     * The denylist fix in candidateTerms() (below tests) must stay a
     * denylist, not a "skip whatever word opens the question" shortcut — a
     * genuinely unattested entity can be the very first word, and that must
     * still be caught.
     */
    public function test_it_blocks_an_unattested_named_entity_even_when_context_is_related(): void
    {
        $result = $this->validator()->validate('Figo e come funziona?', $this->evidence(), [[
            'text' => 'Le notifiche push raggiungono i clienti nell’app.',
            'quote' => 'Le notifiche push raggiungono i clienti nell’app.',
            'document_id' => 7,
            'tool_execution_id' => null,
            'evidence_hash' => 'push-hash',
        ]]);

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
}
