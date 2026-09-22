<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Kb\Ocr;

use App\Services\Kb\Ocr\OcrFigure;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `OcrFigure::fileName()` interpolates `extension` straight into a storage
 * key (`OcrFigureStore::store()`: `$base.'/'.$figure->fileName()`). The
 * built-in drivers already allow-list what they construct with, but the
 * value object is the shared contract a future/custom driver — or a
 * corrupted-but-parseable recorded OCR run — also goes through, so the
 * enforcement has to live here (PR #492 Copilot review, `OcrFigure.php:26`).
 */
final class OcrFigureTest extends TestCase
{
    public function test_a_path_traversal_extension_never_reaches_the_filename(): void
    {
        $figure = new OcrFigure(1, 1, 'bytes', '../result.json');

        $this->assertSame('png', $figure->extension);
        $this->assertSame('fig-1-1.png', $figure->fileName());
        $this->assertStringNotContainsString('..', $figure->fileName());
        $this->assertStringNotContainsString('/', $figure->fileName());
    }

    public function test_an_unsupported_extension_falls_back_to_png(): void
    {
        $figure = new OcrFigure(1, 1, 'bytes', 'php');

        $this->assertSame('png', $figure->extension);
    }

    public function test_extension_case_is_normalised(): void
    {
        $figure = new OcrFigure(1, 1, 'bytes', 'JPG');

        $this->assertSame('jpg', $figure->extension);
        $this->assertSame('fig-1-1.jpg', $figure->fileName());
    }

    /** @return list<array{string}> */
    public static function supportedExtensions(): array
    {
        return [['png'], ['jpg'], ['jpeg'], ['webp'], ['tiff']];
    }

    #[DataProvider('supportedExtensions')]
    public function test_every_driver_supported_extension_passes_through_unchanged(string $extension): void
    {
        $figure = new OcrFigure(1, 1, 'bytes', $extension);

        $this->assertSame($extension, $figure->extension);
    }
}
