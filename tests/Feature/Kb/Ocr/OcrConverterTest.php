<?php

declare(strict_types=1);

namespace Tests\Feature\Kb\Ocr;

use App\Services\Kb\Converters\OcrConverter;
use App\Services\Kb\Converters\PdfConverter;
use App\Services\Kb\Ocr\Drivers\FakeOcrDriver;
use App\Services\Kb\Ocr\OcrFigureStore;
use App\Services\Kb\Ocr\PdfTextLayerProbe;
use App\Services\Kb\Pipeline\PipelineRegistry;
use App\Services\Kb\Pipeline\SourceDocument;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Fixtures\Pdf\PdfFixtureBuilder;
use Tests\TestCase;

/**
 * v8.36 / ADR 0029 — the converter side of OCR, in BOTH flag states (R43).
 */
final class OcrConverterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('kb');
        config([
            'kb.sources.disk' => 'kb',
            'kb.sources.path_prefix' => '',
            'kb.ocr.driver' => 'fake',
            'kb.ocr.fake.pages' => null,
            'ai-finops.metering' => false,
        ]);
    }

    private function image(string $path = 'docs/scan.png', array $metadata = []): SourceDocument
    {
        return new SourceDocument(
            sourcePath: $path,
            mimeType: 'image/png',
            bytes: (string) base64_decode(FakeOcrDriver::PNG_1X1, true),
            externalUrl: null,
            externalId: null,
            connectorType: 'local',
            metadata: $metadata,
        );
    }

    private function imageWithBytes(string $path, string $bytes): SourceDocument
    {
        return new SourceDocument(
            sourcePath: $path,
            mimeType: 'image/png',
            bytes: $bytes,
            externalUrl: null,
            externalId: null,
            connectorType: 'local',
            metadata: [],
        );
    }

    private function pdf(string $bytes, array $metadata = []): SourceDocument
    {
        return new SourceDocument(
            sourcePath: 'docs/scan.pdf',
            mimeType: 'application/pdf',
            bytes: $bytes,
            externalUrl: null,
            externalId: null,
            connectorType: 'local',
            metadata: $metadata,
        );
    }

    // ── OFF (the shipped default) ────────────────────────────────────────────

    public function test_off_the_converter_claims_no_mime_and_the_registry_refuses_images(): void
    {
        config(['kb.ocr.enabled' => false]);
        $converter = $this->app->make(OcrConverter::class);

        foreach (['image/png', 'image/jpeg', 'image/tiff', 'image/webp', 'application/pdf', 'text/markdown'] as $mime) {
            $this->assertFalse($converter->supports($mime), $mime);
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No converter registered for MIME type: image/png');
        $this->app->make(PipelineRegistry::class)->resolveConverter('image/png');
    }

    public function test_off_a_scanned_pdf_keeps_todays_empty_document_behaviour(): void
    {
        config(['kb.ocr.enabled' => false]);
        $converted = $this->app->make(PdfConverter::class)->convert($this->pdf(PdfFixtureBuilder::build(['   ', ' '])));

        $this->assertSame('', $converted->markdown);
        $this->assertArrayNotHasKey('text_layer_probe', $converted->extractionMeta);
        $this->assertArrayNotHasKey('ocr', $converted->extractionMeta);
    }

    // ── ON ───────────────────────────────────────────────────────────────────

    public function test_on_the_converter_claims_exactly_the_image_mimes(): void
    {
        config(['kb.ocr.enabled' => true]);
        $converter = $this->app->make(OcrConverter::class);

        foreach (['image/png', 'image/jpeg', 'IMAGE/TIFF; q=1', 'image/webp'] as $mime) {
            $this->assertTrue($converter->supports($mime), $mime);
        }
        foreach (['application/pdf', 'image/gif', 'text/markdown', ''] as $mime) {
            $this->assertFalse($converter->supports($mime), $mime);
        }
        $this->assertInstanceOf(OcrConverter::class, $this->app->make(PipelineRegistry::class)->resolveConverter('image/png'));
        $this->assertInstanceOf(PdfConverter::class, $this->app->make(PipelineRegistry::class)->resolveConverter('application/pdf'));
    }

    /** Content-addressed run key of the 1x1 PNG every test image carries. */
    private function pngRun(): string
    {
        return OcrFigureStore::runKeyFor((string) base64_decode(FakeOcrDriver::PNG_1X1, true), 'fake', 'fake;figures=1');
    }

    public function test_on_an_image_becomes_page_markdown_with_figures_on_disk(): void
    {
        config(['kb.ocr.enabled' => true]);
        $run = $this->pngRun();
        $converted = $this->app->make(OcrConverter::class)->convert($this->image());

        $this->assertStringContainsString("# scan.png\n\n## Page 1\n\nFake OCR output of scan.png.", $converted->markdown);
        $this->assertStringContainsString('![Figure 1.1](images/fig-1-1.png)', $converted->markdown);
        Storage::disk('kb')->assertExists("docs/scan.png.ocr/{$run}/images/fig-1-1.png");

        $meta = $converted->extractionMeta;
        $this->assertSame('ocr-converter', $meta['converter']);
        $this->assertSame('ocr', $meta['provenance']);
        $this->assertSame('ocr', $meta['extraction_strategy']);
        $this->assertSame('fake', $meta['ocr']['driver']);
        $this->assertSame('image', $meta['ocr']['reason']);
        $this->assertSame(1, $meta['page_count']);
        $this->assertSame(0.9, $meta['ocr']['pages'][0]['confidence']);
        $this->assertSame(1, $meta['ocr']['figures']);
        $this->assertSame("docs/scan.png.ocr/{$run}", $meta['ocr']['figures_dir']);
        $this->assertSame($run, $meta['ocr']['run']);
        $this->assertCount(1, $converted->mediaItems);
        $this->assertSame("docs/scan.png.ocr/{$run}/images/fig-1-1.png", $converted->mediaItems[0]['path']);
    }

    public function test_on_figures_can_be_disabled_without_touching_the_text(): void
    {
        config(['kb.ocr.enabled' => true, 'kb.ocr.figures.enabled' => false]);
        $converted = $this->app->make(OcrConverter::class)->convert($this->image());

        $this->assertStringNotContainsString('images/fig-1-1.png', $converted->markdown);
        $this->assertStringContainsString('Fake OCR output of scan.png.', $converted->markdown);
        $runWithoutFigures = OcrFigureStore::runKeyFor((string) base64_decode(FakeOcrDriver::PNG_1X1, true), 'fake', 'fake;figures=0');
        Storage::disk('kb')->assertMissing("docs/scan.png.ocr/{$runWithoutFigures}/images/fig-1-1.png");
        $this->assertSame([], $converted->mediaItems);
    }

    public function test_on_a_scanned_pdf_is_routed_to_ocr_by_the_text_layer_probe(): void
    {
        config(['kb.ocr.enabled' => true]);
        $converted = $this->app->make(PdfConverter::class)->convert($this->pdf(PdfFixtureBuilder::build(['   ', ' '])));

        $this->assertSame(PdfTextLayerProbe::EMPTY, $converted->extractionMeta['text_layer_probe']);
        $this->assertSame('scanned_pdf', $converted->extractionMeta['ocr']['reason']);
        $this->assertSame('pdf-converter', $converted->extractionMeta['converter']);
        $this->assertSame('ocr', $converted->extractionMeta['provenance']);
        $this->assertStringContainsString('## Page 1', $converted->markdown);
        $this->assertStringContainsString('Fake OCR output of scan.pdf.', $converted->markdown);
    }

    /**
     * A parser failure is not "no text": an `unreadable` probe tries the
     * pdftotext fallback first and keeps the text path when it yields text.
     * The binary is stubbed through KB_PDFTOTEXT_BIN (poppler is not a test
     * dependency).
     */
    public function test_on_an_unreadable_pdf_with_pdftotext_text_keeps_the_text_layer_path(): void
    {
        config(['kb.ocr.enabled' => true]);
        $stub = tempnam(sys_get_temp_dir(), 'pdftotext_stub_');
        file_put_contents($stub, "#!/bin/sh\nprintf 'Readable text that smalot could not parse but poppler can.\\f'\n");
        chmod($stub, 0755);
        config(['kb.pdf.pdftotext_bin' => $stub]);
        try {
            $converted = $this->app->make(PdfConverter::class)->convert($this->pdf('%PDF-1.4 not really a pdf'));
        } finally {
            unlink($stub);
        }

        $this->assertSame('pdftotext', $converted->extractionMeta['extraction_strategy']);
        $this->assertSame(PdfTextLayerProbe::UNREADABLE.':pdftotext', $converted->extractionMeta['text_layer_probe']);
        $this->assertArrayNotHasKey('ocr', $converted->extractionMeta);
        $this->assertStringContainsString('Readable text that smalot could not parse', $converted->markdown);
    }

    public function test_on_an_unreadable_pdf_goes_to_ocr_only_when_pdftotext_finds_no_text_either(): void
    {
        config(['kb.ocr.enabled' => true]);
        $stub = tempnam(sys_get_temp_dir(), 'pdftotext_stub_');
        file_put_contents($stub, "#!/bin/sh\nprintf '  \\f \\f'\n");
        chmod($stub, 0755);
        config(['kb.pdf.pdftotext_bin' => $stub]);
        try {
            $converted = $this->app->make(PdfConverter::class)->convert($this->pdf('%PDF-1.4 not really a pdf'));
        } finally {
            unlink($stub);
        }

        $this->assertSame('scanned_pdf', $converted->extractionMeta['ocr']['reason']);
        $this->assertSame(PdfTextLayerProbe::UNREADABLE.':pdftotext_empty', $converted->extractionMeta['text_layer_probe']);

        // No binary at all: still OCR, and the probe says why.
        config(['kb.pdf.pdftotext_bin' => '/nonexistent/pdftotext']);
        $converted = $this->app->make(PdfConverter::class)->convert($this->pdf('%PDF-1.4 not really a pdf'));
        $this->assertSame('scanned_pdf', $converted->extractionMeta['ocr']['reason']);
        $this->assertSame(PdfTextLayerProbe::UNREADABLE.':pdftotext_failed', $converted->extractionMeta['text_layer_probe']);
    }

    public function test_on_a_pdf_with_a_text_layer_keeps_the_text_layer_path(): void
    {
        config(['kb.ocr.enabled' => true]);
        $converted = $this->app->make(PdfConverter::class)->convert($this->pdf(PdfFixtureBuilder::buildThreePageSample()));

        $this->assertSame(PdfTextLayerProbe::PRESENT, $converted->extractionMeta['text_layer_probe']);
        $this->assertSame('smalot', $converted->extractionMeta['extraction_strategy']);
        $this->assertArrayNotHasKey('ocr', $converted->extractionMeta);
        $this->assertStringContainsString('Lorem ipsum about A.', $converted->markdown);
        $this->assertStringNotContainsString('Fake OCR output', $converted->markdown);
    }

    public function test_on_ocr_force_in_the_ingest_metadata_bypasses_the_probe(): void
    {
        config(['kb.ocr.enabled' => true]);
        $converted = $this->app->make(PdfConverter::class)->convert(
            $this->pdf(PdfFixtureBuilder::buildThreePageSample(), ['ocr' => ['force' => true]]),
        );

        $this->assertSame('skipped', $converted->extractionMeta['text_layer_probe']);
        $this->assertSame('forced', $converted->extractionMeta['ocr']['reason']);
        $this->assertStringContainsString('Fake OCR output of scan.pdf.', $converted->markdown);
    }

    public function test_on_an_unavailable_driver_fails_loudly_not_empty(): void
    {
        config(['kb.ocr.enabled' => true, 'kb.ocr.driver' => 'tesseract', 'kb.ocr.tesseract.binary' => '/definitely/not/here/tesseract']);

        $this->expectException(\App\Services\Kb\Ocr\OcrDriverUnavailableException::class);
        $this->app->make(OcrConverter::class)->convert($this->image());
    }

    public function test_configured_fake_pages_drive_the_output_and_empty_pages_are_skipped(): void
    {
        config(['kb.ocr.enabled' => true, 'kb.ocr.fake.pages' => [
            ['markdown' => 'First page text.', 'confidence' => 0.95],
            ['markdown' => '', 'confidence' => 0.1],
            ['markdown' => 'Third page text.', 'confidence' => null, 'figures' => 2],
        ]]);
        $converted = $this->app->make(OcrConverter::class)->convert($this->image('docs/three.png'));

        $this->assertStringContainsString("## Page 1\n\nFirst page text.", $converted->markdown);
        $this->assertStringNotContainsString('## Page 2', $converted->markdown);
        $this->assertStringContainsString("## Page 3\n\nThird page text.", $converted->markdown);
        $this->assertStringContainsString('![Figure 3.1](images/fig-3-1.png)', $converted->markdown);
        $this->assertStringContainsString('![Figure 3.2](images/fig-3-2.png)', $converted->markdown);
        $this->assertSame(3, $converted->extractionMeta['page_count']);
        $this->assertSame(0.525, $converted->extractionMeta['ocr']['mean_confidence']);
        $this->assertSame(0.1, $converted->extractionMeta['ocr']['min_confidence']);
        Storage::disk('kb')->assertExists("docs/three.png.ocr/{$this->pngRun()}/images/fig-3-2.png");
    }

    /**
     * Two different byte versions at the same source path never share a
     * figure directory: the run key is content-addressed, so version B
     * cannot overwrite version A's pixels (Copilot #476 round 3).
     */
    public function test_two_versions_at_the_same_path_keep_separate_figure_runs(): void
    {
        config(['kb.ocr.enabled' => true]);
        $converter = $this->app->make(OcrConverter::class);

        $first = $converter->convert($this->image('docs/same.png'));
        $secondBytes = (string) base64_decode(FakeOcrDriver::PNG_1X1, true).'v2';
        $second = $converter->convert($this->imageWithBytes('docs/same.png', $secondBytes));

        $runA = $first->extractionMeta['ocr']['run'];
        $runB = $second->extractionMeta['ocr']['run'];
        $this->assertNotSame($runA, $runB);
        Storage::disk('kb')->assertExists("docs/same.png.ocr/{$runA}/images/fig-1-1.png");
        Storage::disk('kb')->assertExists("docs/same.png.ocr/{$runB}/images/fig-1-1.png");
        // Same bytes again → same run directory (idempotent, no third copy).
        $again = $converter->convert($this->image('docs/same.png'));
        $this->assertSame($runA, $again->extractionMeta['ocr']['run']);
    }
}
