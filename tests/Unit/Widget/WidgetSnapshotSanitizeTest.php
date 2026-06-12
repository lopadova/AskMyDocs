<?php

declare(strict_types=1);

namespace Tests\Unit\Widget;

use App\Services\Widget\WidgetSnapshotValidator;
use PHPUnit\Framework\TestCase;

/**
 * M5.7 — Ri-sanitizzazione server-side dei testi dello snapshot.
 *
 * Il BE non si fida del FE: ogni campo testuale deve essere ripulito da
 * markup, code fence e zero-width chars prima di comporre il prompt.
 */
final class WidgetSnapshotSanitizeTest extends TestCase
{
    private WidgetSnapshotValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new WidgetSnapshotValidator;
    }

    // ─── sanitizeText ───────────────────────────────────────────────

    public function test_sanitize_text_strips_angle_brackets(): void
    {
        // <> → space, then whitespace is collapsed + trimmed
        $this->assertSame(
            'click button',
            $this->validator->sanitizeText('click <button>'),
        );
    }

    public function test_sanitize_text_strips_code_fences(): void
    {
        // ``` → 3 spaces, then whitespace is collapsed + trimmed
        $this->assertSame(
            'code block end',
            $this->validator->sanitizeText('code ```block``` end ```'),
        );
    }

    public function test_sanitize_text_removes_zero_width_chars(): void
    {
        // Zero-width removed (not replaced), then whitespace collapsed
        $input = "hello\u{200B}world\u{FEFF}!";
        $this->assertSame('helloworld!', $this->validator->sanitizeText($input));
    }

    /**
     * BUG5 — UTF-8 invalido NON deve crashare: preg_replace con /u ritorna null
     * su byte non-UTF-8 e, senza `?? ''`, la seconda preg_replace e trim()
     * riceverebbero null → TypeError fatale. Qui un byte 0x80 isolato (sequenza
     * di continuazione senza lead byte) deve essere gestito senza eccezioni.
     */
    public function test_sanitize_text_does_not_crash_on_invalid_utf8(): void
    {
        $input = "valido \x80 testo";

        // Non deve sollevare TypeError: il risultato è una stringa (anche se
        // il troncamento esatto dipende dalla gestione PCRE dei byte invalidi).
        $this->assertIsString($this->validator->sanitizeText($input));
    }

    public function test_sanitize_text_collapses_whitespace(): void
    {
        $this->assertSame(
            'a b c',
            $this->validator->sanitizeText('  a   b   c  '),
        );
    }

    public function test_sanitize_text_passes_clean_text_unchanged(): void
    {
        $this->assertSame('Hello world', $this->validator->sanitizeText('Hello world'));
    }

    // ─── sanitizeSnapshot ───────────────────────────────────────────

    public function test_sanitize_snapshot_cleans_fields(): void
    {
        $snapshot = [
            'page' => ['url' => 'https://test.com', 'title' => '<script>alert(1)</script>'],
            'regions' => [],
            'fields' => [
                ['name' => 'email', 'label' => 'Email', 'type' => 'text', 'value' => '```secret```'],
            ],
            'actions' => [
                ['verb' => 'submit', 'label' => '<b>Save</b>', 'enabled' => true],
            ],
            'messages' => [],
            'locales_available' => ['en'],
            'page_outline' => ['headings' => [], 'buttons_unannotated' => [], 'inputs_unannotated' => []],
        ];

        $result = $this->validator->sanitizeSnapshot($snapshot);

        // Titolo pagina ripulito
        $this->assertSame('script alert(1) /script', $result['page']['title']);

        // Valore campo ripulito
        $this->assertSame('secret', $result['fields'][0]['value']);

        // Label azione ripulita
        $this->assertSame('b Save /b', $result['actions'][0]['label']);

        // Campi non testuali passano invariati
        $this->assertTrue($result['actions'][0]['enabled']);
        $this->assertSame('text', $result['fields'][0]['type']);
    }

    public function test_sanitize_snapshot_cleans_messages(): void
    {
        $snapshot = [
            'page' => ['url' => 'https://test.com', 'title' => 'Page'],
            'regions' => [],
            'fields' => [],
            'actions' => [],
            'messages' => [
                ['text' => 'Error: ```code``` injection <img src=x>'],
            ],
            'locales_available' => [],
            'page_outline' => ['headings' => [], 'buttons_unannotated' => [], 'inputs_unannotated' => []],
        ];

        $result = $this->validator->sanitizeSnapshot($snapshot);

        $this->assertSame('Error: code injection img src=x', $result['messages'][0]['text']);
    }

    public function test_sanitize_snapshot_cleans_page_outline(): void
    {
        $snapshot = [
            'page' => ['url' => 'https://test.com', 'title' => 'Page'],
            'regions' => [],
            'fields' => [],
            'actions' => [],
            'messages' => [],
            'locales_available' => [],
            'page_outline' => [
                'headings' => [['text' => '<h1>Title</h1>']],
                'buttons_unannotated' => [],
                'inputs_unannotated' => [],
            ],
        ];

        $result = $this->validator->sanitizeSnapshot($snapshot);

        $this->assertSame('h1 Title /h1', $result['page_outline']['headings'][0]['text']);
    }

    public function test_sanitize_snapshot_handles_empty_collections(): void
    {
        $snapshot = [
            'page' => ['url' => 'https://test.com', 'title' => 'Clean'],
            'regions' => [],
            'fields' => [],
            'actions' => [],
            'messages' => [],
            'locales_available' => [],
            'page_outline' => ['headings' => [], 'buttons_unannotated' => [], 'inputs_unannotated' => []],
        ];

        $result = $this->validator->sanitizeSnapshot($snapshot);

        $this->assertSame('Clean', $result['page']['title']);
    }

    public function test_sanitize_snapshot_handles_missing_sections_gracefully(): void
    {
        $snapshot = [];

        $result = $this->validator->sanitizeSnapshot($snapshot);

        // Nessun errore, snapshot invariato tranne le sezioni mancanti
        $this->assertArrayNotHasKey('page', $result);
    }
}