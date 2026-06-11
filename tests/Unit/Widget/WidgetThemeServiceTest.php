<?php

declare(strict_types=1);

namespace Tests\Unit\Widget;

use App\Services\Widget\WidgetThemeService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tema grafico del widget: default, normalizzazione autoritativa e merge.
 *
 * Il focus è la SICUREZZA (R19): il tema confluisce in CSS dentro lo Shadow DOM
 * del sito ospite, quindi ogni valore ostile (colore non-hex, url non-https,
 * meta-caratteri) deve degradare al default e non propagarsi mai.
 */
final class WidgetThemeServiceTest extends TestCase
{
    private WidgetThemeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new WidgetThemeService;
    }

    #[Test]
    public function defaults_are_complete_and_colors_are_hex(): void
    {
        $d = $this->service->defaults();

        // Un campione delle chiavi attese.
        foreach (['accent', 'background', 'fontFamily', 'fontSize', 'launcherSide', 'panelWidth'] as $key) {
            $this->assertArrayHasKey($key, $d);
        }
        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $d['accent']);
        $this->assertSame('system', $d['fontFamily']);
        $this->assertSame(14, $d['fontSize']);
    }

    #[Test]
    public function sanitize_fills_defaults_for_empty_input(): void
    {
        $this->assertSame($this->service->defaults(), $this->service->sanitize([]));
    }

    #[Test]
    public function sanitize_keeps_valid_values(): void
    {
        $out = $this->service->sanitize([
            'accent' => '#FF8800',
            'fontFamily' => 'inter',
            'fontSize' => 16,
            'launcherShape' => 'circle',
            'launcherSide' => 'left',
            'panelWidth' => 420,
        ]);

        $this->assertSame('#ff8800', $out['accent']); // normalizzato lowercase
        $this->assertSame('inter', $out['fontFamily']);
        $this->assertSame(16, $out['fontSize']);
        $this->assertSame('circle', $out['launcherShape']);
        $this->assertSame('left', $out['launcherSide']);
        $this->assertSame(420, $out['panelWidth']);
    }

    #[Test]
    public function sanitize_rejects_css_injection_in_a_color(): void
    {
        $out = $this->service->sanitize([
            'accent' => '#fff; } body { display: none } .x{color:red',
            'background' => 'red',
            'foreground' => 'url(javascript:alert(1))',
        ]);

        $defaults = $this->service->defaults();
        $this->assertSame($defaults['accent'], $out['accent']);
        $this->assertSame($defaults['background'], $out['background']);
        $this->assertSame($defaults['foreground'], $out['foreground']);
    }

    #[Test]
    public function sanitize_clamps_numbers_into_range(): void
    {
        $tooBig = $this->service->sanitize(['fontSize' => 999, 'panelWidth' => 9999, 'panelRadius' => 500]);
        $this->assertSame(18, $tooBig['fontSize']);
        $this->assertSame(480, $tooBig['panelWidth']);
        $this->assertSame(24, $tooBig['panelRadius']);

        $tooSmall = $this->service->sanitize(['fontSize' => 1, 'panelHeight' => 10, 'panelRadius' => -50]);
        $this->assertSame(12, $tooSmall['fontSize']);
        $this->assertSame(420, $tooSmall['panelHeight']);
        $this->assertSame(0, $tooSmall['panelRadius']);
    }

    #[Test]
    public function sanitize_rejects_non_allowlisted_font_and_enum(): void
    {
        $out = $this->service->sanitize([
            'fontFamily' => 'Comic Sans; }',
            'launcherShape' => 'hexagon',
            'launcherIcon' => '<svg onload=alert(1)>',
        ]);

        $this->assertSame('system', $out['fontFamily']);
        $this->assertSame('pill', $out['launcherShape']);
        $this->assertSame('chat', $out['launcherIcon']);
    }

    #[Test]
    public function sanitize_accepts_https_image_url_but_rejects_unsafe_ones(): void
    {
        $this->assertSame(
            'https://cdn.example.com/logo.png',
            $this->service->sanitize(['headerLogoUrl' => 'https://cdn.example.com/logo.png'])['headerLogoUrl'],
        );

        // http (non-https), data:, e url con meta-caratteri → scartati.
        $this->assertSame('', $this->service->sanitize(['headerLogoUrl' => 'http://cdn.example.com/x.png'])['headerLogoUrl']);
        $this->assertSame('', $this->service->sanitize(['launcherIconUrl' => 'javascript:alert(1)'])['launcherIconUrl']);
        $this->assertSame('', $this->service->sanitize(['launcherIconUrl' => 'https://x.com/a").evil("'])['launcherIconUrl']);
    }

    #[Test]
    public function sanitize_strips_control_chars_and_caps_label_length(): void
    {
        $out = $this->service->sanitize([
            'launcherLabel' => "Ask\x00\x1F me",
            'panelTitle' => str_repeat('x', 200),
        ]);

        $this->assertSame('Ask me', $out['launcherLabel']);
        $this->assertSame(60, mb_strlen($out['panelTitle']));
    }

    #[Test]
    public function resolve_null_returns_defaults_and_merges_stored(): void
    {
        $this->assertSame($this->service->defaults(), $this->service->resolve(null));

        $resolved = $this->service->resolve(['accent' => '#123456']);
        $this->assertSame('#123456', $resolved['accent']);
        // Gli altri campi restano sui default.
        $this->assertSame($this->service->defaults()['background'], $resolved['background']);
    }

    #[Test]
    public function mode_defaults_to_helper_and_sanitizes_to_allowlist(): void
    {
        // Default canonico: launcher flottante.
        $this->assertSame('helper', $this->service->defaults()['mode']);

        // Valore valido conservato; input assente o fuori allowlist → helper.
        $this->assertSame('inline', $this->service->sanitize(['mode' => 'inline'])['mode']);
        $this->assertSame('helper', $this->service->sanitize(['mode' => 'floating'])['mode']);
        $this->assertSame('helper', $this->service->sanitize([])['mode']);

        // resolve fonde lo stored sui default.
        $this->assertSame('inline', $this->service->resolve(['mode' => 'inline'])['mode']);
    }
}
