<?php

declare(strict_types=1);

namespace Tests\Feature\Kb;

use App\Services\Kb\Contracts\ConverterInterface;
use App\Services\Kb\Converters\OcrConverter;
use App\Services\Kb\Converters\PdfConverter;
use App\Services\Kb\Pipeline\PipelineRegistry;
use App\Support\Kb\SourceType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * R23 — `supports()` non-overlap mutex for the CONVERTER registry, the
 * counterpart of PipelineRegistryChunkerMutexTest. v8.36 / ADR 0029 added
 * `OcrConverter` in front of `PdfConverter`; the design promise is that
 * `OcrConverter` claims the four image MIMEs only, and only when
 * `kb.ocr.enabled` is on, while `PdfConverter` stays the sole PDF match in
 * both flag states (the scanned-PDF fallback lives INSIDE PdfConverter, not
 * in a second predicate). This test pins that in both states (R43).
 */
final class PipelineRegistryConverterMutexTest extends TestCase
{
    /** @return array<string, array{bool}> */
    public static function flagStates(): array
    {
        return ['ocr off' => [false], 'ocr on' => [true]];
    }

    #[Test]
    #[DataProvider('flagStates')]
    public function no_two_converters_claim_the_same_mime(bool $ocrEnabled): void
    {
        config(['kb.ocr.enabled' => $ocrEnabled]);
        /** @var PipelineRegistry $registry */
        $registry = $this->app->make(PipelineRegistry::class);

        $mimeUniverse = array_values(array_unique(array_merge(
            SourceType::supportedMimes(true),
            ['text/x-markdown', 'text/plain', 'application/octet-stream'],
        )));

        $overlaps = [];
        foreach ($mimeUniverse as $mime) {
            $claimers = [];
            foreach ($registry->allConverters() as $converter) {
                $this->assertInstanceOf(ConverterInterface::class, $converter);
                if ($converter->supports($mime)) {
                    $claimers[] = $converter::class;
                }
            }
            if (count($claimers) > 1) {
                $overlaps[$mime] = $claimers;
            }
        }

        $this->assertSame([], $overlaps, 'Converter supports() predicates overlap: '.json_encode($overlaps, JSON_PRETTY_PRINT));
    }

    #[Test]
    #[DataProvider('flagStates')]
    public function pdf_is_always_the_pdf_converter_and_images_follow_the_flag(bool $ocrEnabled): void
    {
        config(['kb.ocr.enabled' => $ocrEnabled]);
        /** @var PipelineRegistry $registry */
        $registry = $this->app->make(PipelineRegistry::class);

        $this->assertInstanceOf(PdfConverter::class, $registry->resolveConverter('application/pdf'));

        foreach (SourceType::imageMimes() as $mime) {
            $claimers = array_values(array_filter(
                $registry->allConverters(),
                static fn (ConverterInterface $c): bool => $c->supports($mime),
            ));
            if ($ocrEnabled) {
                $this->assertCount(1, $claimers, "{$mime} must have exactly one converter when OCR is on");
                $this->assertInstanceOf(OcrConverter::class, $claimers[0]);

                continue;
            }
            $this->assertSame([], $claimers, "{$mime} must have no converter when OCR is off");
        }
    }
}
