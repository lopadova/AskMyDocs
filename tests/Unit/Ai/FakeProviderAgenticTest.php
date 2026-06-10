<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Ai\Providers\FakeProvider;
use PHPUnit\Framework\TestCase;

/**
 * Lock-in del function-calling SCRIPTATO del FakeProvider (R13 / M4.14):
 * l'E2E agentico del widget gira contro il vero orchestratore solo perché
 * il FakeProvider emette tool_call deterministiche. Questi test fissano la
 * sequenza così una regressione del provider non rompe l'E2E in modo opaco.
 */
final class FakeProviderAgenticTest extends TestCase
{
    private function provider(): FakeProvider
    {
        return new FakeProvider();
    }

    /** @param  list<array{role: string, content: string}>  $messages */
    private function call(array $messages, bool $withTools = true): \App\Ai\AiResponse
    {
        $options = $withTools
            ? ['tools' => [['type' => 'function', 'function' => ['name' => 'type']]], 'tool_choice' => 'auto']
            : [];

        return $this->provider()->chatWithHistory('system', $messages, $options);
    }

    public function test_no_tools_returns_canned_answer(): void
    {
        $response = $this->call([['role' => 'user', 'content' => 'Compila il profilo']], withTools: false);

        $this->assertSame([], $response->toolCalls);
        $this->assertSame(FakeProvider::ANSWER, $response->content);
    }

    public function test_non_agentic_message_with_tools_still_answers(): void
    {
        // "Posso lavorare da remoto?" non è un trigger agentico → risposta testo.
        $response = $this->call([['role' => 'user', 'content' => 'Posso lavorare da remoto?']]);

        $this->assertSame([], $response->toolCalls);
        $this->assertSame(FakeProvider::ANSWER, $response->content);
    }

    public function test_profile_scenario_emits_type_then_click_then_report_done(): void
    {
        $turn1 = $this->call([
            ['role' => 'user', 'content' => 'Compila il profilo per me'],
        ]);
        $this->assertSame('type', $turn1->toolCalls[0]['name']);
        $this->assertStringContainsString('full-name', $turn1->toolCalls[0]['arguments']);

        $turn2 = $this->call([
            ['role' => 'user', 'content' => 'Compila il profilo per me'],
            ['role' => 'assistant', 'content' => '[azione] type {"field":"full-name","value":"Mario Rossi"}'],
            ['role' => 'user', 'content' => '[risultato] type ok=true {}'],
        ]);
        $this->assertSame('click', $turn2->toolCalls[0]['name']);

        $turn3 = $this->call([
            ['role' => 'user', 'content' => 'Compila il profilo per me'],
            ['role' => 'assistant', 'content' => '[azione] type {}'],
            ['role' => 'user', 'content' => '[risultato] type ok=true {}'],
            ['role' => 'assistant', 'content' => '[azione] click {}'],
            ['role' => 'user', 'content' => '[risultato] click ok=true {}'],
        ]);
        $this->assertSame('report_done', $turn3->toolCalls[0]['name']);
    }

    public function test_search_scenario_emits_search_then_answers(): void
    {
        $turn1 = $this->call([
            ['role' => 'user', 'content' => 'Qual è la policy sul remote work?'],
        ]);
        $this->assertSame('search_knowledge_base', $turn1->toolCalls[0]['name']);

        // Dopo la reiniezione del risultato del tool → risposta testuale.
        $turn2 = $this->call([
            ['role' => 'user', 'content' => 'Qual è la policy sul remote work?'],
            ['role' => 'assistant', 'content' => '[azione] search_knowledge_base {"query":"policy"}'],
            ['role' => 'user', 'content' => '[risultato] search_knowledge_base ok=true {}'],
        ]);
        $this->assertSame([], $turn2->toolCalls);
        $this->assertSame(FakeProvider::ANSWER, $turn2->content);
    }
}
