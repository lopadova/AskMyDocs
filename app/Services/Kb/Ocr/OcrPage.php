<?php

declare(strict_types=1);

namespace App\Services\Kb\Ocr;

/**
 * One recognised page. `confidence` is 0..1 (null when the engine cannot
 * say); `markdown` is the page body WITHOUT the `## Page N` heading — the
 * converter adds it so every driver produces the PdfPageChunker shape.
 */
final readonly class OcrPage
{
    /**
     * @param  list<OcrFigure>  $figures
     */
    public function __construct(
        public int $number,
        public string $markdown,
        public ?float $confidence = null,
        public array $figures = [],
    ) {}
}
