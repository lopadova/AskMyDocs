<?php

declare(strict_types=1);

namespace Tests\Unit\Widget;

use App\Models\WidgetSession;
use App\Services\Widget\AiTool\SearchKnowledgeBaseTool;
use App\Services\Widget\AiTool\WidgetAiToolInterface;
use App\Services\Widget\WidgetAiToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * M4.12 — Test unitari per WidgetAiToolRegistry (R23).
 *
 * Copre: registrazione FQCN valida, FQCN non-esistente rifiutato,
 * FQCN non-interfaccia rifiutato, supports() mutex (overlap nomi tool),
 * resolve + execute, openAiTools(), builtinNames().
 */
final class WidgetAiToolRegistryTest extends TestCase
{
    use RefreshDatabase;

    /** Registrazione FQCN valida (built-in SearchKnowledgeBaseTool) → OK. */
    public function test_register_builtin_accetta_fqcn_valido(): void
    {
        $registry = new WidgetAiToolRegistry();
        $registry->registerBuiltin(SearchKnowledgeBaseTool::class);

        $this->assertSame(['search_knowledge_base'], $registry->registeredNames());
        $this->assertSame(['search_knowledge_base'], $registry->builtinNames());
    }

    /** FQCN di una classe che non esiste → InvalidArgumentException. */
    public function test_register_rifiuta_fqcn_non_esistente(): void
    {
        $registry = new WidgetAiToolRegistry();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not exist');

        $registry->registerBuiltin('App\\Services\\Widget\\AiTool\\ToolCheNonEsiste');
    }

    /** FQCN di una classe che non implementa WidgetAiToolInterface → InvalidArgumentException. */
    public function test_register_rifiuta_fqcn_senza_interfaccia(): void
    {
        $registry = new WidgetAiToolRegistry();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must implement WidgetAiToolInterface');

        // stdClass esiste ma non implementa l'interfaccia
        $registry->registerBuiltin(\stdClass::class);
    }

    /** Due tool con lo stesso toolName() → mutex R23 violato → InvalidArgumentException. */
    public function test_register_rifiuta_overlap_toolname_mutex(): void
    {
        $registry = new WidgetAiToolRegistry();

        // Registra il primo tool built-in
        $registry->registerBuiltin(SearchKnowledgeBaseTool::class);

        // Crea un tool fittizio con lo stesso toolName()
        $duplicate = new class implements WidgetAiToolInterface {
            public function toolName(): string { return 'search_knowledge_base'; } // stesso nome!
            public function description(): string { return 'Duplicato'; }
            public function parametersSchema(): array { return []; }
            public function supports(array $aiTools, array $toolsEnabled, bool $isBuiltin): bool { return false; }
            public function execute(array $args, WidgetSession $session): array { return []; }
        };

        // Binding manuale nel container per far risolvere app($fqcn)
        $duplicateClass = get_class($duplicate);
        $this->app->instance($duplicateClass, $duplicate);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('name conflict');

        $registry->register($duplicateClass);
    }

    /** supports() ritorna true per tool built-in quando è in tools_enabled. */
    public function test_supports_builtin_abilitato_in_tools_enabled(): void
    {
        $registry = new WidgetAiToolRegistry();
        $registry->registerBuiltin(SearchKnowledgeBaseTool::class);

        $this->assertTrue(
            $registry->supports('search_knowledge_base', [], ['search_knowledge_base'])
        );
    }

    /** supports() ritorna false per tool built-in che NON è in tools_enabled. */
    public function test_supports_builtin_non_abilitato(): void
    {
        $registry = new WidgetAiToolRegistry();
        $registry->registerBuiltin(SearchKnowledgeBaseTool::class);

        $this->assertFalse(
            $registry->supports('search_knowledge_base', [], [])
        );
    }

    /** supports() per tool non-registrato ma presente in ai_tools → true (fallback M5+). */
    public function test_supports_tool_non_registrato_in_ai_tools(): void
    {
        $registry = new WidgetAiToolRegistry();

        // Tool non registrato nel registry ma elencato in ai_tools → fallback true
        $this->assertTrue(
            $registry->supports('custom_tool', ['custom_tool'], [])
        );
    }

    /** execute() su tool non-registrato → InvalidArgumentException. */
    public function test_execute_rifiuta_tool_non_registrato(): void
    {
        $registry = new WidgetAiToolRegistry();

        $session = WidgetSession::factory()->make();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('is not a registered tool');

        $registry->execute('tool_inesistente', ['query' => 'test'], $session);
    }

    /** openAiTools() ritorna solo i tool abilitati nel formato OpenAI function-calling. */
    public function test_openai_tools_filtra_per_enabled(): void
    {
        $registry = new WidgetAiToolRegistry();
        $registry->registerBuiltin(SearchKnowledgeBaseTool::class);

        // Con tools_enabled vuoto → nessun tool
        $this->assertEmpty($registry->openAiTools([]));

        // Con tools_enabled che include search_knowledge_base → 1 tool
        $tools = $registry->openAiTools(['search_knowledge_base']);
        $this->assertCount(1, $tools);
        $this->assertSame('function', $tools[0]['type']);
        $this->assertSame('search_knowledge_base', $tools[0]['function']['name']);
        $this->assertArrayHasKey('description', $tools[0]['function']);
        $this->assertArrayHasKey('parameters', $tools[0]['function']);
    }

    /** execute() con componentType non in whitelist → fallback a ui-alert. */
    public function test_execute_componenttype_non_whitelist_diventa_ui_alert(): void
    {
        // Creo un tool fittizio che ritorna un componentType non ammesso
        $badTool = new class implements WidgetAiToolInterface {
            public function toolName(): string { return 'bad_component'; }
            public function description(): string { return 'Tool con component non ammesso'; }
            public function parametersSchema(): array { return []; }
            public function supports(array $aiTools, array $toolsEnabled, bool $isBuiltin): bool { return true; }
            public function execute(array $args, WidgetSession $session): array {
                return [
                    'artifact' => [
                        'componentType' => 'ui-hologram', // non in whitelist
                        'componentProps' => ['title' => 'Non ammesso'],
                    ],
                    'has_results' => true,
                    'interaction_mode' => 'view',
                ];
            }
        };

        $badClass = get_class($badTool);
        $this->app->instance($badClass, $badTool);

        $registry = new WidgetAiToolRegistry();
        $registry->register($badClass);

        $session = WidgetSession::factory()->make();
        $result = $registry->execute('bad_component', [], $session);

        // Il registry DEVE rimpiazzare il componentType non ammesso con ui-alert
        $this->assertSame('ui-alert', $result['artifact']['componentType']);
        $this->assertSame('error', $result['artifact']['componentProps']['level']);
    }
}