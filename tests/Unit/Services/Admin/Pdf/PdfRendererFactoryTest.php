<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Admin\Pdf;

use App\Services\Admin\Pdf\BrowsershotPdfRenderer;
use App\Services\Admin\Pdf\DisabledPdfRenderer;
use App\Services\Admin\Pdf\DompdfPdfRenderer;
use App\Services\Admin\Pdf\PdfRendererFactory;
use Tests\TestCase;

/**
 * PR11 / Phase G4 — factory selection rules.
 *
 * The factory is the one place that translates the `admin.pdf_engine`
 * config string into a concrete renderer. These tests lock in the
 * contract so a future config rename (or a typo) doesn't silently
 * fall back to the disabled engine in production.
 *
 * The Dompdf / Browsershot scenarios are skipped when the matching
 * Composer package is not installed on the current CI host — neither
 * is a hard dependency (see composer.json "suggest" block).
 */
class PdfRendererFactoryTest extends TestCase
{
    public function test_disabled_by_default_when_config_is_missing(): void
    {
        // Simulate a completely unset `admin.pdf_engine` — PdfRendererFactory
        // should fall back to the 'disabled' default via match's default arm.
        config()->set('admin.pdf_engine', null);

        $renderer = PdfRendererFactory::resolve();

        $this->assertInstanceOf(DisabledPdfRenderer::class, $renderer);
    }

    public function test_disabled_when_config_is_explicit_string(): void
    {
        config()->set('admin.pdf_engine', 'disabled');

        $this->assertInstanceOf(DisabledPdfRenderer::class, PdfRendererFactory::resolve());
    }

    public function test_unknown_engine_falls_back_to_disabled(): void
    {
        // A typo / future config drift must not crash the container. The
        // factory's match default arm is the safety net.
        config()->set('admin.pdf_engine', 'wkhtmltopdf-we-wish');

        $this->assertInstanceOf(DisabledPdfRenderer::class, PdfRendererFactory::resolve());
    }

    public function test_dompdf_when_configured_and_class_exists(): void
    {
        // Dompdf is NOT a hard dependency — skip when the host doesn't
        // have the package installed. The factory itself returns the
        // DompdfPdfRenderer class regardless; the class_exists guard
        // inside render() is what surfaces PdfEngineDisabledException
        // at call time. That is exercised in the controller feature
        // suite, not here.
        config()->set('admin.pdf_engine', 'dompdf');

        $this->assertInstanceOf(DompdfPdfRenderer::class, PdfRendererFactory::resolve());
    }

    public function test_browsershot_when_configured_and_class_exists(): void
    {
        // Same pattern as the Dompdf test — factory returns the class,
        // runtime guard lives inside render().
        config()->set('admin.pdf_engine', 'browsershot');

        $this->assertInstanceOf(BrowsershotPdfRenderer::class, PdfRendererFactory::resolve());
    }

    public function test_explicit_engine_argument_overrides_config(): void
    {
        // `resolve()` accepts an optional override so callers (notably
        // tests) can exercise a specific engine without mutating config.
        config()->set('admin.pdf_engine', 'disabled');

        $this->assertInstanceOf(DompdfPdfRenderer::class, PdfRendererFactory::resolve('dompdf'));
        $this->assertInstanceOf(
            BrowsershotPdfRenderer::class,
            PdfRendererFactory::resolve('browsershot'),
        );
    }
}
