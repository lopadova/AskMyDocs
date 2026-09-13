<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Kb\Ocr;

use App\Services\Kb\Ocr\PdfTextLayerProbe;
use Tests\Fixtures\Pdf\PdfFixtureBuilder;
use Tests\TestCase;

/**
 * ADR 0029 §1 — the text-layer verdict is decided PER PAGE over the whole
 * probe window, so a typed cover over scanned body pages is `mixed` (routed
 * to OCR), not `present` (which would silently lose the scanned pages).
 */
final class PdfTextLayerProbeTest extends TestCase
{
    private const COVER = 'Cover page with plenty of typed text on it.';

    public function test_a_typed_cover_over_scanned_pages_is_mixed_not_present(): void
    {
        $probe = app(PdfTextLayerProbe::class)->probe(PdfFixtureBuilder::build([self::COVER, '   ', ' '], [2, 3]));

        $this->assertSame(PdfTextLayerProbe::MIXED, $probe['verdict']);
        $this->assertSame(1, $probe['text_pages']);
        $this->assertSame([2, 3], $probe['scanned_pages']);
        $this->assertSame(3, $probe['pages_total']);
        $this->assertTrue($probe['pages_exact']);
    }

    public function test_a_blank_separator_page_never_makes_a_text_pdf_a_scan(): void
    {
        // Page 2 has neither text nor an image: a separator, not a scanned page.
        $probe = app(PdfTextLayerProbe::class)->probe(PdfFixtureBuilder::build([self::COVER, '   ', self::COVER]));

        $this->assertSame(PdfTextLayerProbe::PRESENT, $probe['verdict']);
        $this->assertSame([], $probe['scanned_pages']);
    }

    public function test_no_text_page_at_all_is_empty(): void
    {
        $this->assertSame(PdfTextLayerProbe::EMPTY, app(PdfTextLayerProbe::class)->probe(PdfFixtureBuilder::build(['   ', ' ']))['verdict']);
        $this->assertSame(PdfTextLayerProbe::EMPTY, app(PdfTextLayerProbe::class)->probe(PdfFixtureBuilder::build(['   ', ' '], [1, 2]))['verdict']);
    }

    /** A positive KB_OCR_PROBE_PAGES bounds the window — the documented trade-off: a scanned page beyond it is not seen. */
    public function test_a_bounded_window_does_not_see_pages_beyond_it(): void
    {
        $pdf = PdfFixtureBuilder::build([self::COVER, '   '], [2]);

        config(['kb.ocr.text_layer_probe.pages' => 1]);
        $this->assertSame(PdfTextLayerProbe::PRESENT, app(PdfTextLayerProbe::class)->probe($pdf)['verdict']);
        $this->assertSame(1, app(PdfTextLayerProbe::class)->probe($pdf)['pages_probed']);

        config(['kb.ocr.text_layer_probe.pages' => 0]);
        $this->assertSame(PdfTextLayerProbe::MIXED, app(PdfTextLayerProbe::class)->probe($pdf)['verdict']);
        $this->assertSame(2, app(PdfTextLayerProbe::class)->probe($pdf)['pages_probed']);
    }

    /** The window never exceeds the OCR page cap: a page the cap would refuse cannot change the verdict. */
    public function test_the_window_is_capped_at_the_ocr_page_limit(): void
    {
        config(['kb.ocr.text_layer_probe.pages' => 0, 'kb.ocr.max_pages' => 2]);
        $pdf = PdfFixtureBuilder::build([self::COVER, self::COVER, '   '], [3]);

        $probe = app(PdfTextLayerProbe::class)->probe($pdf);

        $this->assertSame(2, $probe['pages_probed']);
        $this->assertSame(3, $probe['pages_total']);
        $this->assertSame(PdfTextLayerProbe::PRESENT, $probe['verdict']);
    }
}
