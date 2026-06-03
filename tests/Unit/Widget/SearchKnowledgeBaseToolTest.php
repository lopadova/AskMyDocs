<?php

declare(strict_types=1);

namespace Tests\Unit\Widget;

use App\Models\WidgetSession;
use App\Services\Kb\Chat\ChatRetrievalService;
use App\Services\Kb\Retrieval\SearchResult;
use App\Services\Widget\AiTool\SearchKnowledgeBaseTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * M4.12 — Test unitari per SearchKnowledgeBaseTool.
 *
 * Copre: query vuota → has_results=false + ui-alert, nessun risultato RAG →
 * has_results=false + ui-alert, risultati RAG → has_results=true + ui-data-table,
 * supports() con isBuiltin=true/false, metadata toolName/description/parametersSchema.
 */
final class SearchKnowledgeBaseToolTest extends TestCase
{
    use RefreshDatabase;

    /** Query vuota → artifact ui-alert con level=warning, has_results=false. */
    public function test_execute_query_vuota_ritorna_alert_warning(): void
    {
        $retrieval = Mockery::mock(ChatRetrievalService::class);
        // Non viene mai chiamato per query vuota

        $tool = new SearchKnowledgeBaseTool($retrieval);
        $session = WidgetSession::factory()->make(['project_key' => 'docs-v3']);

        $result = $tool->execute(['query' => ''], $session);

        $this->assertFalse($result['has_results']);
        $this->assertSame('ui-alert', $result['artifact']['componentType']);
        $this->assertSame('warning', $result['artifact']['componentProps']['level']);
    }

    /** Nessun risultato RAG → artifact ui-alert con level=info, has_results=false. */
    public function test_execute_nessun_risultato_ritorna_alert_info(): void
    {
        // SearchResult reale con primary vuoto
        $searchResult = new SearchResult(
            primary: new Collection(),
            expanded: new Collection(),
            rejected: new Collection(),
        );

        $retrieval = Mockery::mock(ChatRetrievalService::class);
        $retrieval->shouldReceive('retrieve')
            ->once()
            ->with('query inesistente', 'docs-v3', null)
            ->andReturn($searchResult);

        $tool = new SearchKnowledgeBaseTool($retrieval);
        $session = WidgetSession::factory()->make(['project_key' => 'docs-v3']);

        $result = $tool->execute(['query' => 'query inesistente'], $session);

        $this->assertFalse($result['has_results']);
        $this->assertSame('ui-alert', $result['artifact']['componentType']);
        $this->assertSame('info', $result['artifact']['componentProps']['level']);
    }

    /** Risultati RAG trovati → artifact ui-data-table, has_results=true, interaction_mode=selection. */
    public function test_execute_con_risultati_ritorna_data_table(): void
    {
        // Chunk fittizio con le proprietà attese dal tool
        $chunk = (object) [
            'id' => 'chunk-1',
            'title' => 'Guida Setup',
            'source' => 'docs',
            'similarity' => 0.92,
            'relevance_score' => null,
            'content' => str_repeat('Contenuto del documento. ', 20),
        ];

        $collection = collect([$chunk]);

        // SearchResult reale con primary non vuoto
        $searchResult = new SearchResult(
            primary: $collection,
            expanded: new Collection(),
            rejected: new Collection(),
        );

        $retrieval = Mockery::mock(ChatRetrievalService::class);
        $retrieval->shouldReceive('retrieve')
            ->once()
            ->with('setup', 'docs-v3', null)
            ->andReturn($searchResult);

        $tool = new SearchKnowledgeBaseTool($retrieval);
        $session = WidgetSession::factory()->make(['project_key' => 'docs-v3']);

        $result = $tool->execute(['query' => 'setup'], $session);

        $this->assertTrue($result['has_results']);
        $this->assertSame('ui-data-table', $result['artifact']['componentType']);
        $this->assertSame('selection', $result['interaction_mode']);
        $this->assertCount(1, $result['artifact']['componentProps']['rows']);
        $this->assertSame('Guida Setup', $result['artifact']['componentProps']['rows'][0]['title']);
    }

    /** supports() con isBuiltin=true → controlla tools_enabled. */
    public function test_supports_builtin_abilitato_quando_in_tools_enabled(): void
    {
        $retrieval = Mockery::mock(ChatRetrievalService::class);
        $tool = new SearchKnowledgeBaseTool($retrieval);

        $this->assertTrue($tool->supports([], ['search_knowledge_base'], true));
        $this->assertFalse($tool->supports([], [], true));
    }

    /** supports() con isBuiltin=false → controlla ai_tools. */
    public function test_supports_custom_abilitato_quando_in_ai_tools(): void
    {
        $retrieval = Mockery::mock(ChatRetrievalService::class);
        $tool = new SearchKnowledgeBaseTool($retrieval);

        $this->assertTrue($tool->supports(['search_knowledge_base'], [], false));
        $this->assertFalse($tool->supports([], [], false));
    }

    /** toolName() e description() ritornano valori stabili. */
    public function test_metadata_tool(): void
    {
        $retrieval = Mockery::mock(ChatRetrievalService::class);
        $tool = new SearchKnowledgeBaseTool($retrieval);

        $this->assertSame('search_knowledge_base', $tool->toolName());
        $this->assertStringContainsString('knowledge base', $tool->description());

        // parametersSchema() ritorna un array con 'properties' e 'required'
        $schema = $tool->parametersSchema();
        $this->assertArrayHasKey('required', $schema);
        // 'properties' è castato a (object) nel tool → stdClass in PHP
        $this->assertObjectHasProperty('query', $schema['properties']);
    }
}