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
    #[DataProvider('languages')]
    public function test_interprets_multilingual_questions_once(string $question, string $language, ?string $supportedLanguage): void
    {
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithProvider')->once()->withArgs(static function ($provider, $prompt, $messages, $options): bool {
            return $provider === 'openrouter'
                && $options['model'] === 'openai/gpt-4o-mini'
                && $options['response_format']['type'] === 'json_schema'
                && $options['response_format']['json_schema']['strict'] === true
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

    public function test_invalid_mention_or_json_falls_back_without_entity_gate(): void
    {
        foreach ([
            '{broken',
            json_encode([
                'language' => 'it', 'intent' => 'Explain shipment',
                'kb_queries' => ['spedizione SPD-51230'],
                'mentions' => [['text' => 'SPD', 'type' => 'identifier']],
                'references_previous_turn' => true,
            ]),
            json_encode([
                'language' => 'it', 'intent' => 'Explain shipment',
                'kb_queries' => ['spedizione SPD-51230'],
                'mentions' => [['text' => 'SPD-99999', 'type' => 'identifier']],
                'references_previous_turn' => true,
            ]),
        ] as $content) {
            $ai = Mockery::mock(AiManager::class);
            $ai->shouldReceive('chatWithProvider')->once()->andReturn(new AiResponse($content, 'fake', 'fake'));
            $result = (new ChatQuestionPreprocessor($ai))->interpret('Parlami di questa SPD-51230');
            $this->assertFalse($result->available);
            $this->assertSame([], $result->mentionTexts());
            $this->assertSame(['Parlami di questa SPD-51230'], $result->kbQueries);
        }
    }

    public function test_provider_failure_uses_bounded_fallback(): void
    {
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chatWithProvider')->once()->andThrow(new \RuntimeException('Unavailable'));
        $result = (new ChatQuestionPreprocessor($ai))->interpret('Esistono email di Figo?');
        $this->assertFalse($result->available);
        $this->assertSame([], $result->mentions);
    }

    private function response(array $payload): AiResponse
    {
        return new AiResponse(json_encode($payload, JSON_THROW_ON_ERROR), 'fake', 'fake');
    }
}
