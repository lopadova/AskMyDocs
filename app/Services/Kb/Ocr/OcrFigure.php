<?php

declare(strict_types=1);

namespace App\Services\Kb\Ocr;

/**
 * A figure the engine cut out of a page. `bytes` is the encoded image
 * (PNG unless `extension` says otherwise); the converter writes it to the
 * kb disk and rewrites the Markdown reference to `images/fig-{page}-{n}.{ext}`.
 */
final readonly class OcrFigure
{
    public function __construct(
        public int $page,
        public int $index,
        public string $bytes,
        public string $extension = 'png',
        /** The placeholder the driver put in the page Markdown, if any. */
        public ?string $placeholder = null,
    ) {}

    public function fileName(): string
    {
        return sprintf('fig-%d-%d.%s', $this->page, $this->index, $this->extension);
    }
}
