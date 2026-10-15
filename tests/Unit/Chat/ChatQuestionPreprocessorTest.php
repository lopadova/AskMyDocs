<?php

declare(strict_types=1);

namespace Tests\Unit\Chat;

use App\Ai\AiManager;
use App\Ai\AiResponse;
use App\Services\Chat\ChatQuestionPreprocessor;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ChatQuestionPreprocessorTest extends TestCase
{
    public function test_parent_cannot_disable_research_required_by_a_mixed_task(): void
    {
        $focus = ['topic' => 'order', 'identifiers' => ['ORDER-B'], 'aspect' => 'current', 'fields' => ['status']];
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithProvider')->twice()->andReturn($this->response([
            'language' => 'it', 'intent' => 'Stato attuale ORDER-B', 'action' => 'research', 'kb_queries' => [],
            'mentions' => [['text' => 'ORDER-B', 'type' => 'identifier']], 'references_previous_turn' => false,
            'context_mode' => 'new', 'requires_research' => false, 'transition' => 'new', 'focus' => $focus,
            'subquestions' => [[...$focus, 'question' => 'Qual è lo stato attuale di ORDER-B?', 'kb_queries' => ['ORDER-B'],
                'context_mode' => 'new', 'requires_research' => true]],
            'resolved_references' => [], 'needs_clarification' => false, 'clarification' => '',
        ]));
        $result = (new ChatQuestionPreprocessor($ai))->interpret('Stato attuale di ORDER-B', reasoningContext: []);
        $this->assertFalse($result->available);
        $this->assertSame('invalid_focus_contract', $result->failureReason);
        $this->assertFalse($result->needsResearch());
    }

    #[DataProvider('languages')]
    public function test_interprets_multilingual_questions_once(string $question, string $language, ?string $supportedLanguage): void
    {
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithProvider')->once()->withArgs(static function ($provider, $prompt, $messages, $options): bool {
            return $provider === 'openrouter'
                && $options['model'] === 'openai/gpt-4o-mini'
                && $options['response_format']['type'] === 'json_schema'
                && $options['response_format']['json_schema']['strict'] === true
                && in_array('action', $options['response_format']['json_schema']['schema']['required'], true)
                && $options['response_format']['json_schema']['schema']['properties']['action']['enum'] === \App\Services\Chat\QuestionAction::schema()['enum']
                && $options['response_format']['json_schema']['schema']['additionalProperties'] === false;
        })->andReturn($this->response([
            'language' => $language,
            'intent' => 'Find delivery emails',
            'kb_queries' => ['delivery error emails'],
            'mentions' => [],
            'references_previous_turn' => false,
        ]));

        $result = (new ChatQuestionPreprocessor($ai))->interpret($question);

        $this->assertTrue($result->available);
        $this->assertSame($supportedLanguage, $result->language);
        $this->assertSame(['delivery error emails'], $result->kbQueries);
        $this->assertSame([], $result->mentions);
    }

    public static function languages(): array
    {
        return [
            'italian' => ['Esistono email di errori di spedizione?', 'it', 'it'],
            'english' => ['Are there emails about delivery errors?', 'en', 'en'],
            'french unsupported locale' => ['Y a-t-il des e-mails sur les erreurs de livraison ?', 'fr', null],
        ];
    }

    public function test_preserves_complete_identifier_and_follow_up_flag(): void
    {
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithProvider')->once()->andReturn($this->response([
            'language' => 'it', 'intent' => 'Explain shipment',
            'kb_queries' => ['spedizione SPD-51230 dettagli'],
            'mentions' => [['text' => 'SPD-51230', 'type' => 'identifier']],
            'references_previous_turn' => true,
        ]));

        $result = (new ChatQuestionPreprocessor($ai))->interpret('Parlami di questa SPD-51230');

        $this->assertSame(['SPD-51230'], $result->mentionTexts());
        $this->assertTrue($result->referencesPreviousTurn);
    }

    public function test_a_literal_mention_is_not_an_unregistered_context_reference_when_focus_is_generic(): void
    {
        $focus = ['topic' => 'invoice', 'identifiers' => [], 'aspect' => 'requests', 'fields' => ['*']];
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithProvider')->once()->andReturn($this->response([
            'language' => 'it', 'intent' => 'Richieste di Ada Rossi per la fattura', 'action' => 'research',
            'kb_queries' => ['Ada Rossi fattura'], 'mentions' => [['text' => 'Ada Rossi', 'type' => 'name']],
            'references_previous_turn' => false, 'context_mode' => 'new', 'requires_research' => true,
            'transition' => 'new', 'focus' => $focus, 'subquestions' => [$focus + [
                'question' => 'Quali richieste ha fatto Ada Rossi per la fattura?', 'kb_queries' => ['Ada Rossi fattura'],
                'context_mode' => 'new', 'requires_research' => true]],
            'resolved_references' => ['Ada Rossi'], 'needs_clarification' => false, 'clarification' => '',
        ]));
        $result = (new ChatQuestionPreprocessor($ai))->interpret('Quali richieste ha fatto Ada Rossi per la fattura?', reasoningContext: []);

        $this->assertTrue($result->available);
        $this->assertSame(['Ada Rossi'], $result->mentionTexts());
        $this->assertSame([], $result->resolvedReferences);
    }

    public function test_numeric_references_redacted_as_phones_are_hidden_from_the_provider_and_restored_before_validation(): void
    {
        $identifier = '3331234567';
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithProvider')->once()->andReturnUsing(function ($provider, $prompt, $messages) use ($identifier) {
            $input = json_decode($messages[0]['content'], true);
            $this->assertStringNotContainsString($identifier, $input['question']);
            $this->assertStringContainsString('opaque_reference_0', $input['question']);
            $focus = ['topic' => 'order', 'identifiers' => ['opaque_reference_0'], 'aspect' => 'status', 'fields' => ['status']];

            return $this->response(['language' => 'it', 'intent' => 'Stato ordine opaque_reference_0', 'action' => 'research',
                'kb_queries' => ['opaque_reference_0'], 'mentions' => [['text' => 'opaque_reference_0', 'type' => 'identifier']],
                'references_previous_turn' => false, 'context_mode' => 'new', 'requires_research' => true,
                'transition' => 'new', 'focus' => $focus, 'subquestions' => [$focus + [
                    'question' => 'Stato di opaque_reference_0?', 'kb_queries' => ['opaque_reference_0'],
                    'context_mode' => 'new', 'requires_research' => true]],
                'resolved_references' => [], 'needs_clarification' => false, 'clarification' => '']);
        });
        $result = (new ChatQuestionPreprocessor($ai))->interpret('Stato ordine '.$identifier, reasoningContext: []);

        $this->assertTrue($result->available);
        $this->assertSame([$identifier], $result->targetIdentifiers());
        $this->assertSame([$identifier], $result->kbQueries);
        $this->assertSame([$identifier], $result->mentionTexts());
    }

    public function test_a_numeric_code_attached_to_a_field_label_is_validated_without_changing_record_prefixes(): void
    {
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithProvider')->once()->andReturnUsing(function ($provider, $prompt, $messages) {
            $input = json_decode($messages[0]['content'], true);
            $this->assertSame('Stato spedizione 6664456131 per U123 e ORDER-123?', $input['question']);
            $focus = ['topic' => 'shipment', 'identifiers' => ['6664456131', 'U123', 'ORDER-123'], 'aspect' => 'status', 'fields' => ['status']];
            return $this->response(['language' => 'it', 'intent' => 'Stato spedizione', 'action' => 'research',
                'kb_queries' => ['6664456131 U123 ORDER-123'],
                'mentions' => array_map(fn ($id) => ['text' => $id, 'type' => 'identifier'], $focus['identifiers']),
                'references_previous_turn' => false, 'context_mode' => 'new', 'requires_research' => true,
                'transition' => 'new', 'focus' => $focus, 'subquestions' => [$focus + [
                    'question' => 'Stato spedizione 6664456131 per U123 e ORDER-123?', 'kb_queries' => ['6664456131 U123 ORDER-123'],
                    'context_mode' => 'new', 'requires_research' => true]],
                'resolved_references' => [], 'needs_clarification' => false, 'clarification' => '']);
        });
        $result = (new ChatQuestionPreprocessor($ai))->interpret('Stato spedizione6664456131 per U123 e ORDER-123?', reasoningContext: []);
        $this->assertTrue($result->available);
        $this->assertSame(['6664456131', 'U123', 'ORDER-123'], $result->targetIdentifiers());
    }

    public function test_quoted_titles_and_literal_clocks_remain_searchable_without_inventing_references(): void
    {
        $focus = ['topic' => 'email', 'identifiers' => ['«ORDER NOTICE»', '9 ottobre 2026 alle 21:43'], 'aspect' => 'order', 'fields' => ['orderNumber']];
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithProvider')->once()->andReturn($this->response([
            'language' => 'it', 'intent' => 'Ordine della notifica', 'action' => 'research', 'kb_queries' => ['ORDER NOTICE 21:43'],
            'mentions' => [['text' => '«ORDER NOTICE»', 'type' => 'name'], ['text' => '9 ottobre 2026 alle 21:43', 'type' => 'identifier']],
            'references_previous_turn' => false, 'context_mode' => 'new', 'requires_research' => true,
            'transition' => 'new', 'focus' => $focus, 'subquestions' => [$focus + ['question' => 'Quale ordine nella notifica?', 'kb_queries' => ['ORDER NOTICE 21:43'], 'context_mode' => 'new', 'requires_research' => true]],
            'resolved_references' => [], 'needs_clarification' => false, 'clarification' => '',
        ]));
        $result = (new ChatQuestionPreprocessor($ai))->interpret('La notifica «ORDER NOTICE» del 9 ottobre 2026 alle 21:43 riguarda quale ordine?', reasoningContext: []);
        $this->assertTrue($result->available);
        $this->assertSame(['ORDER NOTICE', '9 ottobre 2026 alle 21:43'], $result->targetIdentifiers());
        $this->assertContains('21:43', $result->mentionTexts());
    }

    public function test_a_lowercase_name_remains_a_verbatim_mention_and_language_names_are_normalized(): void
    {
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithProvider')->once()->andReturn($this->response([
            'language' => 'Italian', 'intent' => 'Dettagli della consegna a Messina',
            'kb_queries' => ['consegna Messina'],
            'mentions' => [['text' => 'Messina', 'type' => 'name']],
            'references_previous_turn' => true,
        ]));

        $result = (new ChatQuestionPreprocessor($ai))->interpret('parlami di quella di messina');

        $this->assertSame('it', $result->language);
        $this->assertSame(['messina'], $result->mentionTexts());
        $this->assertTrue($result->referencesPreviousTurn);
    }

    public function test_a_user_literal_cannot_collide_with_an_opaque_numeric_reference(): void
    {
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithProvider')->once()->andReturnUsing(function ($provider, $prompt, $messages) {
            $input = json_decode($messages[0]['content'], true);
            $this->assertStringContainsString('opaque_reference_1', $input['question']);

            return $this->response(['language' => 'it', 'intent' => 'Spiega opaque_reference_0',
                'kb_queries' => ['opaque_reference_0'], 'mentions' => [['text' => 'opaque_reference_0', 'type' => 'identifier']],
                'references_previous_turn' => false]);
        });
        $result = (new ChatQuestionPreprocessor($ai))->interpret('Spiega opaque_reference_0, ordine 3331234567');

        $this->assertTrue($result->available);
        $this->assertSame(['opaque_reference_0'], $result->mentionTexts());
    }

    public function test_invalid_json_falls_back_without_entity_gate(): void
    {
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithProvider')->twice()->andReturn(new AiResponse('{broken', 'fake', 'fake'));
        $result = (new ChatQuestionPreprocessor($ai))->interpret('Parlami di questa SPD-51230');
        $this->assertFalse($result->available);
        $this->assertSame([], $result->mentionTexts());
    }

    public function test_non_verbatim_mentions_are_dropped_without_losing_language_or_follow_up(): void
    {
        foreach ([
            json_encode([
                'language' => 'it', 'intent' => 'Explain shipment', 'action' => 'research',
                'kb_queries' => ['spedizione SPD-51230'],
                'mentions' => [['text' => 'SPD', 'type' => 'identifier']],
                'references_previous_turn' => true,
            ]),
            json_encode([
                'language' => 'it', 'intent' => 'Explain shipment', 'action' => 'research',
                'kb_queries' => ['spedizione SPD-51230'],
                'mentions' => [['text' => 'SPD-99999', 'type' => 'identifier']],
                'references_previous_turn' => true,
            ]),
        ] as $content) {
            $ai = Mockery::mock(AiManager::class);
            $ai->shouldReceive('chatWithProvider')->once()->andReturn(new AiResponse($content, 'fake', 'fake'));
            $result = (new ChatQuestionPreprocessor($ai))->interpret('Parlami di questa SPD-51230');
            $this->assertTrue($result->available);
            $this->assertSame([], $result->mentionTexts());
            $this->assertSame(['spedizione SPD-51230'], $result->kbQueries);
            $this->assertSame('it', $result->language);
            $this->assertTrue($result->referencesPreviousTurn);
        }
    }

    public function test_context_only_identifier_does_not_invalidate_a_valid_follow_up(): void
    {
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithProvider')->once()->andReturn($this->response([
            'language' => 'it', 'intent' => 'Richiesta di lettura del reclamo',
            'kb_queries' => ['Reclamo consegna a indirizzo errato'],
            'mentions' => [
                ['text' => 'Messina', 'type' => 'name'],
                ['text' => 'SPD-51230', 'type' => 'identifier'],
            ],
            'references_previous_turn' => true,
        ]));

        $result = (new ChatQuestionPreprocessor($ai))->interpret(
            'Quella di Messina me la fai leggere?',
            'Earlier source mentions SPD-51230',
        );

        $this->assertTrue($result->available);
        $this->assertSame('it', $result->language);
        $this->assertSame(['Messina'], $result->mentionTexts());
        $this->assertTrue($result->referencesPreviousTurn);
    }

    public function test_provider_failure_uses_bounded_fallback(): void
    {
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithProvider')->once()->andThrow(new \RuntimeException('Unavailable'));
        $result = (new ChatQuestionPreprocessor($ai))->interpret('Esistono email di Figo?');
        $this->assertFalse($result->available);
        $this->assertSame([], $result->mentions);
    }

    public function test_one_contract_repair_receives_server_feedback_and_preserves_the_original_request(): void
    {
        $focus = ['topic' => 'customer', 'identifiers' => ['Ada Rossi'], 'aspect' => 'case', 'fields' => ['*']];
        $valid = ['language' => 'it', 'intent' => 'Il caso di Ada Rossi', 'action' => 'research',
            'kb_queries' => ['Ada Rossi'], 'mentions' => [['text' => 'Ada Rossi', 'type' => 'name']],
            'references_previous_turn' => false, 'context_mode' => 'new', 'requires_research' => true,
            'transition' => 'new', 'focus' => $focus, 'subquestions' => [$focus + [
                'question' => 'Che cosa è successo ad Ada Rossi?', 'kb_queries' => ['Ada Rossi'],
                'context_mode' => 'new', 'requires_research' => true]],
            'resolved_references' => [], 'needs_clarification' => false, 'clarification' => ''];
        $invalid = [...$valid, 'references_previous_turn' => true, 'context_mode' => 'conversation'];
        $calls = [];
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithProvider')->twice()->withArgs(function ($provider, $prompt, $messages) use (&$calls): bool {
            $calls[] = json_decode($messages[0]['content'], true);

            return true;
        })->andReturn($this->response($invalid), $this->response($valid));

        $result = (new ChatQuestionPreprocessor($ai))->interpret('Che cosa è successo ad Ada Rossi?', reasoningContext: []);

        $this->assertTrue($result->available);
        $this->assertSame(['Ada Rossi'], $result->targetIdentifiers());
        $this->assertArrayNotHasKey('validation_error', $calls[0]);
        $this->assertSame('invalid_focus_contract', $calls[1]['validation_error']);
        $this->assertSame($calls[0]['question'], $calls[1]['question']);
    }

    #[DataProvider('assistantConversations')]
    public function test_assistant_conversation_has_no_research_tasks(string $question, string $action, string $topic): void
    {
        $focus = ['topic' => $topic, 'identifiers' => [], 'aspect' => $action, 'fields' => []];
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithProvider')->once()->andReturn($this->response([
            'language' => 'it', 'intent' => $question, 'action' => $action, 'kb_queries' => [], 'mentions' => [],
            'references_previous_turn' => false, 'transition' => 'new', 'focus' => $focus, 'subquestions' => [],
            'resolved_references' => [], 'needs_clarification' => false, 'clarification' => '',
        ]));
        $result = (new ChatQuestionPreprocessor($ai))->interpret($question, reasoningContext: []);
        $this->assertTrue($result->available);
        $this->assertTrue($result->isAssistantConversation());
        $this->assertSame([], $result->kbQueries);
        $this->assertSame([], $result->subquestions);
        $this->assertTrue(\App\Services\Chat\QuestionUnderstanding::fromArray($result->toArray())->isAssistantConversation());
    }

    public static function assistantConversations(): array
    {
        return [
            ['Ciao', 'greeting', 'assistant'], ['Hello!', 'greeting', 'assistant'],
            ['Hai accesso ai documenti?', 'capabilities', 'documents'],
            ['Can you read orders?', 'capabilities', 'orders'], ['What can you do?', 'capabilities', 'assistant'],
        ];
    }

    public function test_a_social_prefix_does_not_shortcut_a_business_request(): void
    {
        $focus = ['topic' => 'order', 'identifiers' => ['ORDER-A'], 'aspect' => 'status', 'fields' => ['status']];
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithProvider')->once()->andReturn($this->response([
            'language' => 'it', 'intent' => 'Stato ordine ORDER-A', 'action' => 'research', 'kb_queries' => ['ORDER-A stato'],
            'mentions' => [['text' => 'ORDER-A', 'type' => 'identifier']], 'references_previous_turn' => false,
            'transition' => 'new', 'focus' => $focus, 'subquestions' => [$focus], 'resolved_references' => [],
            'needs_clarification' => false, 'clarification' => '',
        ]));
        $result = (new ChatQuestionPreprocessor($ai))->interpret('Ciao, dimmi lo stato di ORDER-A', reasoningContext: []);
        $this->assertTrue($result->available);
        $this->assertFalse($result->isAssistantConversation());
        $this->assertSame(['ORDER-A'], $result->targetIdentifiers());
    }

    public function test_named_followup_resolves_a_registered_person_instead_of_copying_generic_services(): void
    {
        $focus = ['topic' => 'person request', 'identifiers' => ['Valentina Rinaldi'], 'aspect' => 'next_steps', 'fields' => []];
        $task = $focus + ['question' => "Come gestire la richiesta di accesso GestiClaim di Valentina Rinaldi?",
            'kb_queries' => ['Valentina Rinaldi richiesta accesso GestiClaim']];
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithProvider')->once()->withArgs(function ($provider, $prompt, $messages) {
            $this->assertStringContainsString('explicit subject overrides the old generic task', $prompt);
            $context = json_decode($messages[0]['content'], true)['reasoning_context'];
            $this->assertSame(['Valentina Rinaldi'], $context['known_identifiers']);
            $this->assertSame('read_source', $context['dialogue'][0]['action']);
            return true;
        })->andReturn($this->response([
            'language' => 'it', 'intent' => $task['question'], 'action' => 'refine', 'kb_queries' => $task['kb_queries'],
            'mentions' => [['text' => 'Rinaldi', 'type' => 'name']], 'references_previous_turn' => true,
            'transition' => 'continue', 'focus' => $focus, 'subquestions' => [$task],
            'resolved_references' => ['Valentina Rinaldi'], 'needs_clarification' => false, 'clarification' => '',
        ]));
        $result = (new ChatQuestionPreprocessor($ai))->interpret('Cosa possiamo fare con Rinaldi', reasoningContext: [
            'known_identifiers' => ['Valentina Rinaldi'], 'focus' => ['topic' => 'generic logistics services'],
            'dialogue' => [['role' => 'assistant', 'action' => 'read_source',
                'sources_shown' => [['document_id' => 226, 'title' => 'Domanda su accesso portale GestiClaim per reclami']]]],
        ]);
        $this->assertTrue($result->available);
        $this->assertSame(['Rinaldi'], $result->mentionTexts());
        $this->assertSame(['Valentina Rinaldi'], $result->targetIdentifiers());
        $this->assertSame(['Valentina Rinaldi'], $result->resolvedReferences);
        $this->assertSame('refine', $result->toArray()['action']);
        $this->assertFalse($result->asksToReadSource());
    }

    public function test_mixed_turn_preserves_context_and_new_research_as_independent_routes(): void
    {
        $email = ['topic' => 'email', 'identifiers' => ['Valentina Rinaldi'], 'aspect' => 'explanation', 'fields' => [],
            'question' => "Spiega l'email di Valentina Rinaldi", 'kb_queries' => [], 'context_mode' => 'conversation', 'requires_research' => false];
        $order = ['topic' => 'order', 'identifiers' => ['ORDER-B'], 'aspect' => 'status', 'fields' => ['status'],
            'question' => 'Trova lo stato di ORDER-B', 'kb_queries' => ['ORDER-B stato'], 'context_mode' => 'new', 'requires_research' => true];
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithProvider')->once()->andReturn($this->response([
            'language' => 'it', 'intent' => 'Spiegazione email e ricerca ordine', 'action' => 'research',
            'kb_queries' => ['ORDER-B stato'], 'mentions' => [['text' => 'ORDER-B', 'type' => 'identifier']],
            'references_previous_turn' => true, 'context_mode' => 'mixed', 'requires_research' => true,
            'transition' => 'continue', 'focus' => array_intersect_key($email, array_flip(['topic', 'identifiers', 'aspect', 'fields'])),
            'subquestions' => [$email, $order], 'resolved_references' => ['Valentina Rinaldi'], 'needs_clarification' => false, 'clarification' => '',
        ]));
        $result = (new ChatQuestionPreprocessor($ai))->interpret('Spiega quella email e trova ORDER-B', reasoningContext: ['known_identifiers' => ['Valentina Rinaldi']]);
        $this->assertTrue($result->available);
        $this->assertSame('mixed', $result->toArray()['context_mode']);
        $this->assertTrue($result->needsResearch());
        $this->assertFalse($result->forSubquestion(0)->needsResearch());
        $this->assertSame([], $result->forSubquestion(0)->kbQueries);
        $this->assertTrue($result->forSubquestion(1)->needsResearch());
        $this->assertFalse($result->forSubquestion(1)->referencesPreviousTurn);
        $this->assertSame(['ORDER-B stato'], $result->forSubquestion(1)->kbQueries);
    }

    public function test_missing_target_is_clarified_without_creating_search_queries(): void
    {
        $focus = ['topic' => 'order', 'identifiers' => [], 'aspect' => 'target', 'fields' => []];
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithProvider')->once()->andReturn($this->response([
            'language' => 'it', 'intent' => 'Chiarire quale ordine', 'action' => 'clarify', 'kb_queries' => [], 'mentions' => [],
            'references_previous_turn' => true, 'context_mode' => 'conversation', 'requires_research' => false,
            'transition' => 'continue', 'focus' => $focus, 'subquestions' => [$focus + [
                'question' => 'Quale ordine vuoi controllare?', 'kb_queries' => [], 'context_mode' => 'conversation', 'requires_research' => false]],
            'resolved_references' => [], 'needs_clarification' => true, 'clarification' => 'Quale ordine vuoi controllare?',
        ]));
        $result = (new ChatQuestionPreprocessor($ai))->interpret('Controllalo', reasoningContext: []);
        $this->assertTrue($result->available);
        $this->assertTrue($result->needsClarification);
        $this->assertFalse($result->needsResearch());
        $this->assertSame([], $result->kbQueries);
    }

    private function response(array $payload): AiResponse
    {
        $payload += ['action' => 'research'];
        return new AiResponse(json_encode($payload, JSON_THROW_ON_ERROR), 'fake', 'fake');
    }

    public function test_semantic_source_action_is_preserved_in_any_language_without_literal_mentions(): void
    {
        foreach (['Aprila', "Fammi vedere l'email di conferma", 'Montre-la', 'Open it', 'Muéstramela', '見せて'] as $question) {
            $focus = ['topic' => 'email', 'identifiers' => ['TRACK-55'], 'aspect' => 'source_text', 'fields' => []];
            $ai = Mockery::mock(AiManager::class);
            $ai->shouldReceive('chatWithProvider')->once()->withArgs(function ($provider, $prompt, $messages) {
                $this->assertStringContainsString('Downstream code will use action, not keywords', $prompt);
                $this->assertStringContainsString('exactly one action and target clear', $prompt);
                return true;
            })->andReturn($this->response([
                'language' => 'it', 'intent' => 'Testo della conferma associata a TRACK-55', 'action' => 'read_source',
                'kb_queries' => ['TRACK-55 conferma'], 'mentions' => [], 'references_previous_turn' => true,
                'transition' => 'continue', 'focus' => $focus, 'subquestions' => [$focus],
                'resolved_references' => ['TRACK-55'], 'needs_clarification' => false, 'clarification' => '',
            ]));
            $result = (new ChatQuestionPreprocessor($ai))->interpret($question, reasoningContext: ['known_identifiers' => ['TRACK-55']]);
            $this->assertTrue($result->asksToReadSource(), $question);
            $this->assertSame([], $result->mentionTexts());
            $this->assertSame('read_source', $result->toArray()['action']);
            $restored = \App\Services\Chat\QuestionUnderstanding::fromArray($result->toArray());
            $this->assertTrue($restored->forSubquestion(0)->asksToReadSource());
        }
    }

    public function test_missing_or_invalid_model_action_falls_back_without_keyword_classification(): void
    {
        foreach ([[], ['action' => 'execute_tool'], ['action' => ['read_source']]] as $extra) {
            $ai = Mockery::mock(AiManager::class);
            $ai->shouldReceive('chatWithProvider')->twice()->andReturn(new AiResponse(json_encode([
                'language' => 'it', 'intent' => 'Leggere la fonte completa', 'kb_queries' => ['conferma'],
                'mentions' => [], 'references_previous_turn' => true, ...$extra,
            ]), 'fake', 'test'));
            $result = (new ChatQuestionPreprocessor($ai))->interpret('Leggere la fonte completa');
            $this->assertFalse($result->available);
            $this->assertFalse($result->asksToReadSource());
            $this->assertSame('research', $result->toArray()['action']);
            $this->assertSame('invalid_schema', $result->failureReason);
        }
    }

    public function test_inconsistent_action_and_transition_are_not_silently_reinterpreted(): void
    {
        $focus = ['topic' => 'email', 'identifiers' => [], 'aspect' => 'text', 'fields' => []];
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithProvider')->twice()->andReturn($this->response([
            'language' => 'it', 'intent' => 'Testo della conferma', 'action' => 'read_source',
            'kb_queries' => ['conferma'], 'mentions' => [], 'references_previous_turn' => true,
            'transition' => 'recap', 'focus' => $focus, 'subquestions' => [$focus],
            'resolved_references' => [], 'needs_clarification' => false, 'clarification' => '',
        ]));
        $result = (new ChatQuestionPreprocessor($ai))->interpret('Aprila', reasoningContext: []);
        $this->assertFalse($result->available);
        $this->assertSame('invalid_action_contract', $result->failureReason);
    }

    public function test_ambiguous_acceptance_is_a_clarification_not_a_source_read(): void
    {
        $focus = ['topic' => 'email', 'identifiers' => [], 'aspect' => 'text', 'fields' => []];
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithProvider')->once()->andReturn($this->response([
            'language' => 'it', 'intent' => 'Chiarire cosa aprire', 'action' => 'clarify',
            'kb_queries' => ['conferma'], 'mentions' => [], 'references_previous_turn' => true,
            'transition' => 'continue', 'focus' => $focus, 'subquestions' => [$focus],
            'resolved_references' => [], 'needs_clarification' => true, 'clarification' => 'Quale email vuoi aprire?',
        ]));
        $result = (new ChatQuestionPreprocessor($ai))->interpret('ok', reasoningContext: []);
        $this->assertTrue($result->available);
        $this->assertTrue($result->needsClarification);
        $this->assertFalse($result->asksToReadSource());
        $this->assertSame('clarify', $result->forSubquestion(0)->toArray()['action']);
    }

    public function test_recheck_is_a_distinct_action_and_old_snapshots_never_infer_source_reading(): void
    {
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithProvider')->once()->andReturn($this->response([
            'language' => 'it', 'intent' => 'Verificare il riferimento alla conferma', 'action' => 'recheck',
            'kb_queries' => ['Conferma spedizione ordine urgente'], 'mentions' => [], 'references_previous_turn' => true,
        ]));
        $result = (new ChatQuestionPreprocessor($ai))->interpret('Sicuro?', 'An email confirmation was mentioned.');
        $this->assertSame('recheck', $result->toArray()['action']);
        $old = \App\Services\Chat\QuestionUnderstanding::fromArray(['available' => true, 'intent' => 'Leggere la fonte', 'transition' => 'continue']);
        $this->assertFalse($old->asksToReadSource());
        $this->assertSame('research', $old->toArray()['action']);
        $this->assertSame('recap', \App\Services\Chat\QuestionUnderstanding::fromArray(['available' => true, 'transition' => 'recap'])->toArray()['action']);
    }

    public function test_customer_followup_moves_focus_without_claiming_context_codes_were_typed(): void
    {
        $focus = ['topic' => 'customer', 'identifiers' => ['CUSTOMER-X'], 'aspect' => 'identity', 'fields' => ['name']];
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithProvider')->once()->withArgs(fn ($provider, $prompt) => str_contains($prompt, 'OLD focus'))
            ->andReturn($this->response(['language' => 'en', 'intent' => 'identify customer', 'kb_queries' => ['CUSTOMER-X'],
                'mentions' => [], 'references_previous_turn' => true, 'transition' => 'continue', 'focus' => $focus,
                'subquestions' => [$focus], 'resolved_references' => ['CUSTOMER-X'], 'needs_clarification' => false, 'clarification' => '']));
        $result = (new ChatQuestionPreprocessor($ai))->interpret('Who is the customer?', reasoningContext: [
            'known_identifiers' => ['TRACK-55', 'CUSTOMER-X'],
            'verified_relations' => [['from' => 'TRACK-55', 'field' => 'customerId', 'to' => 'CUSTOMER-X']],
        ]);
        $this->assertTrue($result->available);
        $this->assertSame([], $result->mentions);
        $this->assertSame(['CUSTOMER-X'], $result->resolvedReferences);
        $this->assertSame('customer', $result->focus['topic']);
    }

    public function test_empty_active_subquestions_do_not_silently_reuse_the_old_focus(): void
    {
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithProvider')->twice()->andReturn($this->response([
            'language' => 'en', 'intent' => 'identify customer', 'kb_queries' => ['customer'], 'mentions' => [],
            'references_previous_turn' => true, 'transition' => 'continue',
            'focus' => ['topic' => 'shipment', 'identifiers' => ['TRACK-55'], 'aspect' => 'details', 'fields' => []],
            'subquestions' => [], 'resolved_references' => ['TRACK-55'], 'needs_clarification' => false, 'clarification' => '',
        ]));
        $result = (new ChatQuestionPreprocessor($ai))->interpret('Who is the customer?', reasoningContext: ['known_identifiers' => ['TRACK-55']]);
        $this->assertFalse($result->available);
        $this->assertSame(['Who is the customer?'], $result->kbQueries);
    }

    public function test_primary_focus_is_not_lost_when_only_secondary_request_is_in_subquestions(): void
    {
        $hub = ['topic' => 'hub', 'identifiers' => ['HUB-AA-01'], 'aspect' => 'details', 'fields' => []];
        $shipment = ['topic' => 'shipment', 'identifiers' => ['TRACK-55'], 'aspect' => 'details', 'fields' => []];
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithProvider')->once()->andReturn($this->response([
            'language' => 'it', 'intent' => 'Informazioni su hub e spedizione', 'kb_queries' => ['HUB-AA-01', 'TRACK-55'],
            'mentions' => [['text' => 'HUB-AA-01', 'type' => 'identifier'], ['text' => 'TRACK-55', 'type' => 'identifier']],
            'references_previous_turn' => false, 'transition' => 'new', 'focus' => $hub, 'subquestions' => [$shipment],
            'resolved_references' => [], 'needs_clarification' => false, 'clarification' => '',
        ]));
        $result = (new ChatQuestionPreprocessor($ai))->interpret('Informazioni su HUB-AA-01 e TRACK-55', reasoningContext: []);
        $this->assertTrue($result->available);
        $this->assertSame(['hub', 'shipment'], array_column($result->subquestions, 'topic'));
        $this->assertSame(['HUB-AA-01'], $result->subquestions[0]['kb_queries']);
        $this->assertSame(['TRACK-55'], $result->subquestions[1]['kb_queries']);
        $this->assertSame(['HUB-AA-01', 'TRACK-55'], $result->targetIdentifiers());
    }

    public function test_invalid_focus_preserves_independently_validated_language_and_reports_reason(): void
    {
        $focus = ['topic' => 'hub', 'identifiers' => ['INVENTED'], 'aspect' => 'details', 'fields' => []];
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithProvider')->twice()->andReturn($this->response([
            'language' => 'it', 'intent' => 'Dettagli hub', 'kb_queries' => ['INVENTED'], 'mentions' => [],
            'references_previous_turn' => true, 'transition' => 'continue', 'focus' => $focus, 'subquestions' => [$focus],
            'resolved_references' => ['INVENTED'], 'needs_clarification' => false, 'clarification' => '',
        ]));
        $result = (new ChatQuestionPreprocessor($ai))->interpret("parti dall'hub", reasoningContext: ['known_identifiers' => ['HUB-AA-01']]);
        $this->assertFalse($result->available);
        $this->assertSame('it', $result->language);
        $this->assertSame("parti dall'hub", $result->kbQueries[0]);
        $this->assertSame('invalid_focus_contract', $result->toArray()['failure_reason']);
        $this->assertSame([], $result->resolvedReferences);
    }

    public function test_four_independent_questions_keep_all_search_seeds(): void
    {
        $subs = $mentions = $queries = [];
        for ($i = 0; $i < 4; $i++) {
            $subs[] = ['topic' => 'item', 'identifiers' => ['ITEM-'.$i], 'aspect' => 'details', 'fields' => ['*'],
                'question' => 'Details of ITEM-'.$i, 'kb_queries' => ['ITEM-'.$i]];
            $mentions[] = ['text' => 'ITEM-'.$i, 'type' => 'identifier'];
            $queries[] = 'ITEM-'.$i;
        }
        $focus = array_intersect_key($subs[0], array_flip(['topic', 'identifiers', 'aspect', 'fields']));
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithProvider')->once()->andReturn($this->response([
            'language' => 'en', 'intent' => 'Four independent questions', 'kb_queries' => $queries, 'mentions' => $mentions,
            'references_previous_turn' => false, 'transition' => 'new', 'focus' => $focus, 'subquestions' => $subs,
            'resolved_references' => [], 'needs_clarification' => false, 'clarification' => '',
        ]));
        $result = (new ChatQuestionPreprocessor($ai))->interpret(implode(' and ', $queries), reasoningContext: []);
        $this->assertTrue($result->available);
        $this->assertCount(4, $result->subquestions);
        $this->assertSame($queries, $result->kbQueries);
    }

    public function test_literal_task_ids_misclassified_as_context_are_reconciled_without_accepting_invented_ids(): void
    {
        $hub = ['topic' => 'hub', 'identifiers' => ['HUB-A'], 'aspect' => 'details', 'fields' => ['*']];
        $order = ['topic' => 'order', 'identifiers' => ['ORDER-B'], 'aspect' => 'details', 'fields' => ['*'],
            'question' => 'Details of ORDER-B', 'kb_queries' => ['ORDER-B']];
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithProvider')->once()->andReturn($this->response([
            'language' => 'en', 'intent' => 'Hub and order details', 'kb_queries' => ['HUB-A', 'ORDER-B'], 'mentions' => [],
            'references_previous_turn' => false, 'transition' => 'new', 'focus' => $hub, 'subquestions' => [$order],
            'resolved_references' => ['HUB-A', 'ORDER-B'], 'needs_clarification' => false, 'clarification' => '',
        ]));
        $result = (new ChatQuestionPreprocessor($ai))->interpret('details of hub-a and ORDER-B', reasoningContext: []);
        $this->assertTrue($result->available);
        $this->assertSame(['hub-a', 'ORDER-B'], $result->mentionTexts());
        $this->assertSame([], $result->resolvedReferences);
        $this->assertCount(2, $result->subquestions);
        $this->assertSame(['hub-a'], $result->subquestions[0]['identifiers']);
        $this->assertSame(['HUB-A'], $result->subquestions[0]['kb_queries']);
    }

    public function test_chat_53_keeps_a_fifth_question_outside_company_knowledge_as_an_independent_task(): void
    {
        $subs = [
            ['topic' => 'hub', 'identifiers' => ['HUB-MI-07'], 'aspect' => 'details', 'fields' => ['*'],
                'question' => 'Quali sono i dettagli di HUB-MI-07?', 'kb_queries' => ['HUB-MI-07 dettagli']],
            ['topic' => 'shipment', 'identifiers' => ['RL-TRACK-9355'], 'aspect' => 'details', 'fields' => ['*'],
                'question' => 'Quali sono i dettagli di RL-TRACK-9355?', 'kb_queries' => ['RL-TRACK-9355 dettagli']],
            ['topic' => 'shipment', 'identifiers' => ['RL-TRACK-9355'], 'aspect' => 'owner', 'fields' => ['customerId'],
                'question' => 'Di chi è RL-TRACK-9355?', 'kb_queries' => ['RL-TRACK-9355 cliente']],
            ['topic' => 'hub', 'identifiers' => ['HUB-MI-07'], 'aspect' => 'rules', 'fields' => [],
                'question' => 'Quali regole valgono in HUB-MI-07?', 'kb_queries' => ['HUB-MI-07 regole']],
            ['topic' => 'weather', 'identifiers' => ['Firenze'], 'aspect' => 'today', 'fields' => [],
                'question' => 'Che tempo fa oggi a Firenze?', 'kb_queries' => ['Firenze meteo oggi']],
        ];
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithProvider')->once()->withArgs(function ($provider, $prompt) {
            $this->assertStringContainsString('Preserve EVERY explicit user request', $prompt);
            $this->assertStringContainsString('Tool availability is decided later', $prompt);
            $this->assertStringContainsString('all FIVE tasks', $prompt);
            return true;
        })->andReturn($this->response([
            'language' => 'it', 'intent' => 'Dettagli hub, spedizione, cliente, regole e meteo',
            'kb_queries' => ['HUB-MI-07', 'RL-TRACK-9355', 'Firenze meteo oggi'],
            'mentions' => [['text' => 'HUB-MI-07', 'type' => 'identifier'], ['text' => 'RL-TRACK-9355', 'type' => 'identifier'], ['text' => 'Firenze', 'type' => 'name']],
            'references_previous_turn' => false, 'transition' => 'new',
            'focus' => array_intersect_key($subs[0], array_flip(['topic', 'identifiers', 'aspect', 'fields'])),
            'subquestions' => $subs, 'resolved_references' => [], 'needs_clarification' => false, 'clarification' => '',
        ]));
        $result = (new ChatQuestionPreprocessor($ai))->interpret(
            'Dammi informazioni su HUB-MI-07 e RL-TRACK-9355, di chi è la spedizione, le regole dell’hub e il meteo di oggi a Firenze.',
            profile: ['context' => 'Azienda di logistica'], reasoningContext: []);

        $this->assertTrue($result->available);
        $this->assertCount(5, $result->subquestions);
        $weather = $result->forSubquestion(4);
        $this->assertSame('Che tempo fa oggi a Firenze?', $weather->intent);
        $this->assertSame(['Firenze meteo oggi'], $weather->kbQueries);
        $this->assertSame(['Firenze'], $weather->targetIdentifiers());
    }
}
