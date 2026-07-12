<?php

declare(strict_types=1);

namespace Tests\Unit\ApiConnectors;

use App\Ai\AiManager;
use App\Ai\AiResponse;
use App\ApiConnectors\AiResponseAnalyst;
use Mockery;
use Padosoft\AskMyDocsConnectorApi\Contracts\ResponseAnalyst;
use Tests\TestCase;

/**
 * The host adapter binding the API-connector workbench "Analisi" onto AiManager.
 * It narrates the reduced structure, is best-effort (a provider error / empty
 * reply → null), and skips the model entirely when there is nothing to analyze.
 */
final class AiResponseAnalystTest extends TestCase
{
    public function test_it_narrates_the_reduced_structure_via_ai_manager(): void
    {
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chat')->once()->andReturn(
            new AiResponse(content: '  A collection of items lives under `data`.  ', provider: 'fake', model: 'x'),
        );

        $out = (new AiResponseAnalyst($ai))->analyze([
            'method' => 'GET',
            'url' => 'https://api.example.com/catalog',
            'reduced' => ['data' => [['id' => 1]]],
            'notes' => [],
        ]);

        $this->assertSame('A collection of items lives under `data`.', $out);
    }

    public function test_it_returns_null_on_a_provider_error(): void
    {
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chat')->andThrow(new \RuntimeException('provider down'));

        $out = (new AiResponseAnalyst($ai))->analyze([
            'method' => 'GET', 'url' => 'https://x', 'reduced' => ['a' => 1], 'notes' => [],
        ]);

        $this->assertNull($out);
    }

    public function test_it_skips_the_model_when_there_is_nothing_to_analyze(): void
    {
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldNotReceive('chat');

        $out = (new AiResponseAnalyst($ai))->analyze([
            'method' => 'GET', 'url' => 'https://x', 'reduced' => null, 'notes' => [],
        ]);

        $this->assertNull($out);
    }

    public function test_suggest_configuration_parses_and_sanitizes_the_ai_json(): void
    {
        $ai = Mockery::mock(AiManager::class);
        $ai->shouldReceive('chat')->once()->andReturn(new AiResponse(
            content: '{"tool_name":"list_users","tool_description":"List users.",'
                .'"parameters":[{"name":"q","location":"bogus","source":"llm","type":"string","required":true},'
                .'{"name":"","location":"query"}],"pagination":{"type":"page","page_param":"page"}}',
            provider: 'fake',
            model: 'x',
        ));

        $out = (new AiResponseAnalyst($ai))->suggestConfiguration([
            'method' => 'GET', 'url' => 'https://x', 'reduced' => ['a' => 1],
        ]);

        $this->assertSame('list_users', $out['tool_name']);
        // Blank-name param dropped; invalid location coerced to 'query'.
        $this->assertCount(1, $out['parameters']);
        $this->assertSame('query', $out['parameters'][0]['location']);
        $this->assertTrue($out['parameters'][0]['required']);
        $this->assertSame('page', $out['pagination']['type']);
    }

    public function test_the_container_binds_the_ai_backed_analyst(): void
    {
        $this->assertInstanceOf(AiResponseAnalyst::class, app(ResponseAnalyst::class));
    }
}
