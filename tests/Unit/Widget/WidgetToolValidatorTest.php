<?php

declare(strict_types=1);

namespace Tests\Unit\Widget;

use App\Services\Widget\WidgetToolCatalog;
use App\Services\Widget\WidgetToolValidator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * M4.2 — Verifica le regole di validazione specifiche della spec §5.4:
 *   - set_locale deve essere in locales_available (regola 6)
 *   - goto_step step deve esistere nello snapshot
 * Più tutte le regole base (field, target, navigate_to, whitelist).
 */
final class WidgetToolValidatorTest extends TestCase
{
    private WidgetToolValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new WidgetToolValidator(new WidgetToolCatalog);
    }

    // ── Snapshots di base riusabili ──────────────────────────────────────────

    private function baseSnapshot(): array
    {
        return [
            'fields' => [
                ['name' => 'email', 'type' => 'text'],
                ['name' => 'country', 'type' => 'select'],
                ['name' => 'q', 'type' => 'combobox-async'],
                ['name' => 'accept_terms', 'type' => 'checkbox'],
                ['name' => 'plan', 'type' => 'radio'],
            ],
            'actions' => [
                ['verb' => 'submit', 'label' => 'Salva'],
                ['verb' => 'delete', 'label' => 'Elimina'],
            ],
            'regions' => [
                ['id' => 'wizard', 'steps' => [
                    ['id' => 'step1', 'label' => 'Dati anagrafici', 'active' => true],
                    ['id' => 'step2', 'label' => 'Preferenze'],
                    ['id' => 'step3', 'label' => 'Conferma'],
                ]],
                ['id' => 'sidebar'],
            ],
            'locales_available' => ['it', 'en', 'de'],
            'page_outline' => [
                'inputs_unannotated' => [
                    ['name' => 'extra_field', 'testid' => 'tf-extra'],
                ],
                'buttons_unannotated' => [
                    ['id' => 'btn-cancel', 'testid' => 'btn-cancel', 'text' => 'Annulla'],
                ],
            ],
        ];
    }

    private function allToolsEnabled(): array
    {
        return array_keys((new WidgetToolCatalog)->definitions());
    }

    // ── Regola 1: whitelist ──────────────────────────────────────────────────

    #[Test]
    public function disabled_tool_is_rejected(): void
    {
        $result = $this->validator->validate(
            'click', ['target' => 'submit'],
            $this->baseSnapshot(),
            [],  // nessun tool abilitato
        );
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('not enabled', $result['error']);
    }

    #[Test]
    public function undefined_tool_is_rejected(): void
    {
        $result = $this->validator->validate(
            'nonexistent', [],
            $this->baseSnapshot(),
            ['nonexistent'],
        );
        $this->assertFalse($result['ok']);
    }

    // ── Regola 2: field ───────────────────────────────────────────────────────

    #[Test]
    public function field_tool_rejects_missing_field(): void
    {
        $result = $this->validator->validate(
            'type', ['field' => 'nonexistent', 'value' => 'x'],
            $this->baseSnapshot(),
            $this->allToolsEnabled(),
        );
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('does not exist', $result['error']);
    }

    #[Test]
    public function field_tool_accepts_existing_field(): void
    {
        $result = $this->validator->validate(
            'type', ['field' => 'email', 'value' => 'test@example.com'],
            $this->baseSnapshot(),
            $this->allToolsEnabled(),
        );
        $this->assertTrue($result['ok']);
    }

    #[Test]
    public function field_tool_accepts_unannotated_input_by_name(): void
    {
        $result = $this->validator->validate(
            'type', ['field' => 'extra_field', 'value' => 'x'],
            $this->baseSnapshot(),
            $this->allToolsEnabled(),
        );
        $this->assertTrue($result['ok']);
    }

    #[Test]
    public function field_tool_accepts_unannotated_input_by_testid(): void
    {
        $result = $this->validator->validate(
            'type', ['field' => 'tf-extra', 'value' => 'x'],
            $this->baseSnapshot(),
            $this->allToolsEnabled(),
        );
        $this->assertTrue($result['ok']);
    }

    // ── Regola 3: target ──────────────────────────────────────────────────────

    #[Test]
    public function target_tool_rejects_missing_target(): void
    {
        $result = $this->validator->validate(
            'click', ['target' => 'nonexistent'],
            $this->baseSnapshot(),
            $this->allToolsEnabled(),
        );
        $this->assertFalse($result['ok']);
    }

    #[Test]
    public function click_accepts_action_verb(): void
    {
        $result = $this->validator->validate(
            'click', ['target' => 'submit'],
            $this->baseSnapshot(),
            $this->allToolsEnabled(),
        );
        $this->assertTrue($result['ok']);
    }

    #[Test]
    public function click_accepts_unannotated_button_by_id(): void
    {
        $result = $this->validator->validate(
            'click', ['target' => 'btn-cancel'],
            $this->baseSnapshot(),
            $this->allToolsEnabled(),
        );
        $this->assertTrue($result['ok']);
    }

    #[Test]
    public function click_accepts_unannotated_button_by_text(): void
    {
        $result = $this->validator->validate(
            'click', ['target' => 'Annulla'],
            $this->baseSnapshot(),
            $this->allToolsEnabled(),
        );
        $this->assertTrue($result['ok']);
    }

    // ── Regola 5: set_locale (spec §5.4 regola 6) ────────────────────────────

    #[Test]
    public function set_locale_accepts_locale_in_available(): void
    {
        $result = $this->validator->validate(
            'set_locale', ['locale' => 'en'],
            $this->baseSnapshot(),
            $this->allToolsEnabled(),
        );
        $this->assertTrue($result['ok']);
    }

    #[Test]
    public function set_locale_rejects_locale_not_in_available(): void
    {
        $result = $this->validator->validate(
            'set_locale', ['locale' => 'fr'],
            $this->baseSnapshot(),
            $this->allToolsEnabled(),
        );
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('not in locales_available', $result['error']);
    }

    #[Test]
    public function set_locale_rejects_empty_locale(): void
    {
        $result = $this->validator->validate(
            'set_locale', ['locale' => ''],
            $this->baseSnapshot(),
            $this->allToolsEnabled(),
        );
        $this->assertFalse($result['ok']);
    }

    #[Test]
    public function set_locale_allows_any_when_locales_available_empty(): void
    {
        // Se locales_available è vuoto, il validator non può filtrare
        $snapshot = $this->baseSnapshot();
        $snapshot['locales_available'] = [];

        $result = $this->validator->validate(
            'set_locale', ['locale' => 'ja'],
            $snapshot,
            $this->allToolsEnabled(),
        );
        $this->assertTrue($result['ok'], 'Senza locales_available, ogni locale è accettato.');
    }

    // ── Regola 6: goto_step step esistente ───────────────────────────────────

    #[Test]
    public function goto_step_accepts_existing_step_in_region(): void
    {
        $result = $this->validator->validate(
            'goto_step', ['step' => 'step2', 'mode' => 'jump'],
            $this->baseSnapshot(),
            $this->allToolsEnabled(),
        );
        $this->assertTrue($result['ok']);
    }

    #[Test]
    public function goto_step_rejects_nonexistent_step(): void
    {
        $result = $this->validator->validate(
            'goto_step', ['step' => 'step99'],
            $this->baseSnapshot(),
            $this->allToolsEnabled(),
        );
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('does not exist', $result['error']);
    }

    #[Test]
    public function goto_step_rejects_empty_step(): void
    {
        $result = $this->validator->validate(
            'goto_step', ['step' => ''],
            $this->baseSnapshot(),
            $this->allToolsEnabled(),
        );
        $this->assertFalse($result['ok']);
    }

    #[Test]
    public function goto_step_accepts_region_id_as_fallback(): void
    {
        // In pagine semplici, la region stessa è lo step
        $result = $this->validator->validate(
            'goto_step', ['step' => 'sidebar'],
            $this->baseSnapshot(),
            $this->allToolsEnabled(),
        );
        $this->assertTrue($result['ok']);
    }

    // ── navigate_to allowlist ───────────────────────────────────────────────

    #[Test]
    public function navigate_to_allows_relative_path(): void
    {
        $result = $this->validator->validate(
            'navigate_to', ['url' => '/admin/settings'],
            $this->baseSnapshot(),
            $this->allToolsEnabled(),
        );
        $this->assertTrue($result['ok']);
    }

    #[Test]
    public function navigate_to_rejects_external_url_without_allowlist(): void
    {
        $result = $this->validator->validate(
            'navigate_to', ['url' => 'https://evil.com'],
            $this->baseSnapshot(),
            $this->allToolsEnabled(),
            [],
        );
        $this->assertFalse($result['ok']);
    }

    #[Test]
    public function navigate_to_allows_external_url_in_allowlist(): void
    {
        $result = $this->validator->validate(
            'navigate_to', ['url' => 'https://trusted.example.com/page'],
            $this->baseSnapshot(),
            $this->allToolsEnabled(),
            ['https://trusted.example.com'],
        );
        $this->assertTrue($result['ok']);
    }

    #[Test]
    public function navigate_to_rejects_protocol_relative(): void
    {
        $result = $this->validator->validate(
            'navigate_to', ['url' => '//evil.com'],
            $this->baseSnapshot(),
            $this->allToolsEnabled(),
        );
        $this->assertFalse($result['ok']);
    }

    // ── M5.11: navigate_to dangerous schemes (R19) ──────────────────────────

    #[Test]
    public function navigate_to_rejects_javascript_scheme(): void
    {
        $result = $this->validator->validate(
            'navigate_to', ['url' => 'javascript:alert(1)'],
            $this->baseSnapshot(),
            $this->allToolsEnabled(),
        );
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('not allowed', $result['error']);
    }

    #[Test]
    public function navigate_to_rejects_javascript_scheme_mixed_case(): void
    {
        $result = $this->validator->validate(
            'navigate_to', ['url' => 'JaVaScRiPt:alert(1)'],
            $this->baseSnapshot(),
            $this->allToolsEnabled(),
        );
        $this->assertFalse($result['ok']);
    }

    #[Test]
    public function navigate_to_rejects_data_scheme(): void
    {
        $result = $this->validator->validate(
            'navigate_to', ['url' => 'data:text/html,<script>alert(1)</script>'],
            $this->baseSnapshot(),
            $this->allToolsEnabled(),
        );
        $this->assertFalse($result['ok']);
    }

    #[Test]
    public function navigate_to_rejects_vbscript_scheme(): void
    {
        $result = $this->validator->validate(
            'navigate_to', ['url' => 'vbscript:MsgBox(1)'],
            $this->baseSnapshot(),
            $this->allToolsEnabled(),
        );
        $this->assertFalse($result['ok']);
    }

    #[Test]
    public function navigate_to_rejects_ftp_scheme_even_in_allowlist(): void
    {
        // Only http/https allowed after parse (M5.11 R19)
        $result = $this->validator->validate(
            'navigate_to', ['url' => 'ftp://files.example.com'],
            $this->baseSnapshot(),
            $this->allToolsEnabled(),
            ['ftp://files.example.com'],  // allowlist doesn't matter, scheme blocked
        );
        $this->assertFalse($result['ok']);
    }

    #[Test]
    public function navigate_to_rejects_protocol_relative_with_path(): void
    {
        $result = $this->validator->validate(
            'navigate_to', ['url' => '//evil.com/steal-cookies'],
            $this->baseSnapshot(),
            $this->allToolsEnabled(),
        );
        $this->assertFalse($result['ok']);
    }

    // ── M4 tool specifici ───────────────────────────────────────────────────

    #[Test]
    public function combobox_search_requires_existing_field(): void
    {
        $result = $this->validator->validate(
            'combobox_search', ['field' => 'q', 'query' => 'test'],
            $this->baseSnapshot(),
            $this->allToolsEnabled(),
        );
        $this->assertTrue($result['ok']);
    }

    #[Test]
    public function combobox_search_rejects_missing_field(): void
    {
        $result = $this->validator->validate(
            'combobox_search', ['field' => 'missing', 'query' => 'test'],
            $this->baseSnapshot(),
            $this->allToolsEnabled(),
        );
        $this->assertFalse($result['ok']);
    }

    #[Test]
    public function combobox_set_requires_existing_field(): void
    {
        $result = $this->validator->validate(
            'combobox_set', ['field' => 'q', 'value' => 'opt1'],
            $this->baseSnapshot(),
            $this->allToolsEnabled(),
        );
        $this->assertTrue($result['ok']);
    }

    #[Test]
    public function toggle_requires_existing_field(): void
    {
        $result = $this->validator->validate(
            'toggle', ['field' => 'accept_terms', 'on' => true],
            $this->baseSnapshot(),
            $this->allToolsEnabled(),
        );
        $this->assertTrue($result['ok']);
    }

    #[Test]
    public function radio_requires_existing_field(): void
    {
        $result = $this->validator->validate(
            'radio', ['field' => 'plan', 'value' => 'pro'],
            $this->baseSnapshot(),
            $this->allToolsEnabled(),
        );
        $this->assertTrue($result['ok']);
    }

    // ── Tool senza needs particolari passano sempre ─────────────────────────

    #[Test]
    public function wait_for_passes_without_needs(): void
    {
        $result = $this->validator->validate(
            'wait_for', ['condition' => 'element visible', 'timeout_ms' => 3000],
            $this->baseSnapshot(),
            $this->allToolsEnabled(),
        );
        $this->assertTrue($result['ok']);
    }

    #[Test]
    public function show_recap_passes_without_needs(): void
    {
        $result = $this->validator->validate(
            'show_recap', ['summary' => 'Riepilogo', 'rows' => []],
            $this->baseSnapshot(),
            $this->allToolsEnabled(),
        );
        $this->assertTrue($result['ok']);
    }

    #[Test]
    public function move_cursor_requires_existing_target(): void
    {
        $result = $this->validator->validate(
            'move_cursor', ['target' => 'submit'],
            $this->baseSnapshot(),
            $this->allToolsEnabled(),
        );
        $this->assertTrue($result['ok']);
    }

    #[Test]
    public function move_cursor_rejects_missing_target(): void
    {
        $result = $this->validator->validate(
            'move_cursor', ['target' => 'nonexistent'],
            $this->baseSnapshot(),
            $this->allToolsEnabled(),
        );
        $this->assertFalse($result['ok']);
    }

    #[Test]
    public function tour_step_does_not_require_target_validation(): void
    {
        // tour_step ha needs=[], highlight_target non è validato come action target
        $result = $this->validator->validate(
            'tour_step', [
                'step_index' => 0,
                'step_total' => 3,
                'highlight_target' => 'any-css-selector',
                'message' => 'Click Salva',
            ],
            $this->baseSnapshot(),
            $this->allToolsEnabled(),
        );
        $this->assertTrue($result['ok']);
    }

    // ── Snapshot con regioni senza steps ─────────────────────────────────────

    #[Test]
    public function goto_step_with_region_no_steps_fallback(): void
    {
        // Regione senza steps[], la regione stessa è lo step
        $snapshot = ['regions' => [['id' => 'main']], 'fields' => [], 'actions' => [], 'page_outline' => []];

        $result = $this->validator->validate(
            'goto_step', ['step' => 'main'],
            $snapshot,
            $this->allToolsEnabled(),
        );
        $this->assertTrue($result['ok']);
    }

    #[Test]
    public function goto_step_region_not_found(): void
    {
        $snapshot = ['regions' => [['id' => 'main']], 'fields' => [], 'actions' => [], 'page_outline' => []];

        $result = $this->validator->validate(
            'goto_step', ['step' => 'missing'],
            $snapshot,
            $this->allToolsEnabled(),
        );
        $this->assertFalse($result['ok']);
    }
}