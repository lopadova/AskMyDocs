<?php

declare(strict_types=1);

namespace Tests\Unit\Widget;

use App\Services\Widget\WidgetToolCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * M4.1 — Verifica che il catalogo tool contenga tutti i tool previsti dalla
 * spec KITT §5 con gli attributi corretti (side, needs, parameters).
 */
final class WidgetToolCatalogTest extends TestCase
{
    private WidgetToolCatalog $catalog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->catalog = new WidgetToolCatalog;
    }

    // ────────────────────────── Tool FE (DOM) ──────────────────────────

    /** Tool DOM che richiedono field nello snapshot. */
    public static function fieldTools(): array
    {
        return [
            'type'            => ['type'],
            'select'          => ['select'],
            'combobox_search' => ['combobox_search'],
            'combobox_set'    => ['combobox_set'],
            'toggle'          => ['toggle'],
            'radio'           => ['radio'],
        ];
    }

    /** Tool DOM che richiedono target nello snapshot. */
    public static function targetTools(): array
    {
        return [
            'click'       => ['click'],
            'move_cursor' => ['move_cursor'],
        ];
    }

    /** Tool DOM senza needs particolari (neé field né target). */
    public static function contextTools(): array
    {
        return [
            'set_locale'   => ['set_locale'],
            'goto_step'    => ['goto_step'],
            'wait_for'     => ['wait_for'],
            'show_recap'   => ['show_recap'],
            'read_page'    => ['read_page'],
            'navigate_to'  => ['navigate_to'],
            'submit_form'  => ['submit_form'],
            'scroll_to'    => ['scroll_to'],
            'tour_step'    => ['tour_step'],
        ];
    }

    /** Tool conversazionali. */
    public static function conversationTools(): array
    {
        return [
            'ask_user'       => ['ask_user'],
            'report_done'    => ['report_done'],
            'report_blocked' => ['report_blocked'],
        ];
    }

    /** Tool BE (AiTool). */
    public static function beTools(): array
    {
        return [
            'search_knowledge_base' => ['search_knowledge_base'],
        ];
    }

    #[Test]
    public function definitions_contains_all_fe_dom_tools(): void
    {
        $expected = [
            'click', 'type', 'select', 'scroll_to', 'navigate_to',
            'submit_form', 'read_page',                              // M2
            'combobox_search', 'combobox_set', 'toggle', 'radio',  // M4.1
            'set_locale', 'goto_step', 'wait_for',                  // M4.1
            'tour_step', 'move_cursor', 'show_recap',              // M4.1
        ];

        $defs = $this->catalog->definitions();
        foreach ($expected as $tool) {
            $this->assertArrayHasKey($tool, $defs, "Tool '{$tool}' mancante dal catalogo.");
        }
    }

    #[Test]
    public function definitions_contains_conversation_tools(): void
    {
        $defs = $this->catalog->definitions();
        foreach (['ask_user', 'report_done', 'report_blocked'] as $tool) {
            $this->assertArrayHasKey($tool, $defs, "Tool '{$tool}' mancante dal catalogo.");
        }
    }

    #[Test]
    public function definitions_contains_be_tools(): void
    {
        $defs = $this->catalog->definitions();
        $this->assertArrayHasKey('search_knowledge_base', $defs);
    }

    #[Test]
    public function total_tool_count_is_21_plus_be(): void
    {
        // 17 DOM + 3 conversazionali + 1 BE = 21
        $defs = $this->catalog->definitions();
        $this->assertCount(21, $defs, 'Il catalogo deve contenere esattamente 21 tool.');
    }

    #[Test]
    #[DataProvider('fieldTools')]
    public function field_tools_have_side_fe_and_need_field(string $tool): void
    {
        $def = $this->catalog->definition($tool);
        $this->assertNotNull($def);
        $this->assertSame('fe', $def['side']);
        $this->assertContains('field', $def['needs']);
    }

    #[Test]
    #[DataProvider('targetTools')]
    public function target_tools_have_side_fe_and_need_target(string $tool): void
    {
        $def = $this->catalog->definition($tool);
        $this->assertNotNull($def);
        $this->assertSame('fe', $def['side']);
        $this->assertContains('target', $def['needs']);
    }

    #[Test]
    #[DataProvider('contextTools')]
    public function context_tools_are_side_fe(string $tool): void
    {
        $def = $this->catalog->definition($tool);
        $this->assertNotNull($def);
        $this->assertSame('fe', $def['side']);
    }

    #[Test]
    #[DataProvider('conversationTools')]
    public function conversation_tools_are_side_fe(string $tool): void
    {
        $def = $this->catalog->definition($tool);
        $this->assertNotNull($def);
        $this->assertSame('fe', $def['side']);
        // I tool conversazionali non hanno needs field/target
        $this->assertNotContains('field', $def['needs']);
        $this->assertNotContains('target', $def['needs']);
    }

    #[Test]
    #[DataProvider('beTools')]
    public function be_tools_have_side_be(string $tool): void
    {
        $def = $this->catalog->definition($tool);
        $this->assertNotNull($def);
        $this->assertSame('be', $def['side']);
    }

    #[Test]
    public function submit_form_requires_confirm(): void
    {
        $def = $this->catalog->definition('submit_form');
        $this->assertTrue($def['confirm'], 'submit_form deve avere confirm=true.');
    }

    #[Test]
    public function navigate_to_requires_confirm(): void
    {
        $def = $this->catalog->definition('navigate_to');
        $this->assertTrue($def['confirm'], 'navigate_to deve avere confirm=true.');
    }

    #[Test]
    public function click_does_not_require_confirm(): void
    {
        $def = $this->catalog->definition('click');
        $this->assertFalse($def['confirm'], 'click NON deve avere confirm=true.');
    }

    // ────────────────────── Schema parametri (spot check) ──────────────────────

    #[Test]
    public function set_locale_has_locale_param(): void
    {
        $params = $this->catalog->definition('set_locale')['parameters'];
        $props = (array) $params['properties'];
        $this->assertArrayHasKey('locale', $props);
        $this->assertContains('locale', $params['required']);
    }

    #[Test]
    public function goto_step_has_step_and_optional_mode(): void
    {
        $params = $this->catalog->definition('goto_step')['parameters'];
        $props = (array) $params['properties'];
        $this->assertArrayHasKey('step', $props);
        $this->assertArrayHasKey('mode', $props);
        $this->assertContains('step', $params['required']);
        $this->assertNotContains('mode', $params['required']);
    }

    #[Test]
    public function toggle_has_optional_on_param(): void
    {
        $params = $this->catalog->definition('toggle')['parameters'];
        $props = (array) $params['properties'];
        $this->assertArrayHasKey('field', $props);
        $this->assertArrayHasKey('on', $props);
        $this->assertContains('field', $params['required']);
        $this->assertNotContains('on', $params['required']);
    }

    #[Test]
    public function combobox_set_has_optional_query(): void
    {
        $params = $this->catalog->definition('combobox_set')['parameters'];
        $props = (array) $params['properties'];
        $this->assertArrayHasKey('field', $props);
        $this->assertArrayHasKey('value', $props);
        $this->assertArrayHasKey('query', $props);
        $this->assertContains('field', $params['required']);
        $this->assertContains('value', $params['required']);
        $this->assertNotContains('query', $params['required']);
    }

    #[Test]
    public function show_recap_has_rows_array_with_name_label_value(): void
    {
        $params = $this->catalog->definition('show_recap')['parameters'];
        $props = (array) $params['properties'];
        $this->assertArrayHasKey('summary', $props);
        $this->assertArrayHasKey('rows', $props);
        $this->assertSame('array', $props['rows']['type']);

        // Ogni row è un oggetto {name, label, value}
        $rowProps = (array) $props['rows']['items']['properties'];
        $this->assertArrayHasKey('name', $rowProps);
        $this->assertArrayHasKey('label', $rowProps);
        $this->assertArrayHasKey('value', $rowProps);
    }

    #[Test]
    public function tour_step_has_required_params(): void
    {
        $params = $this->catalog->definition('tour_step')['parameters'];
        $required = $params['required'];
        $this->assertContains('step_index', $required);
        $this->assertContains('step_total', $required);
        $this->assertContains('highlight_target', $required);
        $this->assertContains('message', $required);
    }

    // ────────────────────── Utility: isDefined / openAiTools ──────────────────────

    #[Test]
    public function is_defined_returns_true_for_existing_tool(): void
    {
        $this->assertTrue($this->catalog->isDefined('click'));
        $this->assertTrue($this->catalog->isDefined('set_locale'));
    }

    #[Test]
    public function is_defined_returns_false_for_unknown_tool(): void
    {
        $this->assertFalse($this->catalog->isDefined('nonexistent_tool'));
    }

    #[Test]
    public function open_ai_tools_filters_by_enabled_list(): void
    {
        $tools = $this->catalog->openAiTools(['click', 'type', 'fake_tool']);
        $names = array_map(fn (array $t) => $t['function']['name'], $tools);
        $this->assertSame(['click', 'type'], $names);
    }

    #[Test]
    public function open_ai_tools_returns_empty_for_empty_enabled(): void
    {
        $this->assertSame([], $this->catalog->openAiTools([]));
    }

    #[Test]
    public function every_tool_has_required_definition_keys(): void
    {
        $required = ['side', 'confirm', 'needs', 'description', 'parameters'];
        foreach ($this->catalog->definitions() as $name => $def) {
            foreach ($required as $key) {
                $this->assertArrayHasKey($key, $def, "Tool '{$name}' mancante chiave '{$key}'.");
            }
        }
    }

    #[Test]
    public function every_tool_parameter_schema_has_type_object(): void
    {
        foreach ($this->catalog->definitions() as $name => $def) {
            $params = $def['parameters'];
            $this->assertSame(
                'object',
                $params['type'] ?? null,
                "Tool '{$name}' parameters deve avere type=object."
            );
        }
    }
}