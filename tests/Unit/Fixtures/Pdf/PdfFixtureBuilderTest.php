<?php

declare(strict_types=1);

namespace Tests\Unit\Fixtures\Pdf;

use PHPUnit\Framework\TestCase;
use Tests\Fixtures\Pdf\PdfFixtureBuilder;

/**
 * Sanity tests for the inline PDF fixture builder used by PdfConverterTest +
 * PdfIngestionTest. The builder is itself test infrastructure but a faulty
 * builder would silently weaken every PDF assertion downstream — worth its
 * own coverage.
 */
final class PdfFixtureBuilderTest extends TestCase
{
    public function test_throws_invalid_argument_for_empty_page_array(): void
    {
        // A PDF with zero pages produces an invalid `/Kids []` list and
        // a Pages object that smalot cannot parse. The builder must
        // reject empty input fail-loud rather than emit a malformed PDF
        // that future tests would mistake for a valid edge-case fixture.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/at least one page/');

        PdfFixtureBuilder::build([]);
    }

    public function test_emits_pdf_header_marker(): void
    {
        $bytes = PdfFixtureBuilder::buildSinglePage('hello');
        $this->assertStringStartsWith("%PDF-1.4\n", $bytes);
    }

    public function test_emits_eof_marker(): void
    {
        $bytes = PdfFixtureBuilder::buildSinglePage('hello');
        $this->assertStringEndsWith("%%EOF\n", $bytes);
    }

    public function test_three_page_sample_contains_distinctive_per_page_text(): void
    {
        // Round-trip via the same byte string — assertions further down the
        // pipeline (PdfConverterTest, PdfIngestionTest) rely on these exact
        // sentinel strings. Sanity-check them at the source.
        $bytes = PdfFixtureBuilder::buildThreePageSample();
        $this->assertStringContainsString('Page 1: Lorem ipsum about A.', $bytes);
        $this->assertStringContainsString('Page 2: Lorem ipsum about B.', $bytes);
        $this->assertStringContainsString('Page 3: Lorem ipsum about C.', $bytes);
    }
}
