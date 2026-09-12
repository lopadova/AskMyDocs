<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Kb\Ocr;

use App\Services\Kb\Ocr\OcrFigure;
use App\Services\Kb\Ocr\OcrPage;
use App\Services\Kb\Ocr\OcrRequest;
use App\Services\Kb\Ocr\OcrResult;
use PHPUnit\Framework\TestCase;

final class OcrResultTest extends TestCase
{
    public function test_confidence_aggregates_ignore_pages_without_a_value(): void
    {
        $result = new OcrResult('fake', [
            new OcrPage(1, 'a', 0.9),
            new OcrPage(2, 'b', null),
            new OcrPage(3, 'c', 0.5, [new OcrFigure(3, 1, 'png'), new OcrFigure(3, 2, 'png')]),
        ]);

        $this->assertSame(3, $result->pageCount());
        $this->assertSame(0.7, $result->meanConfidence());
        $this->assertSame(0.5, $result->minConfidence());
        $this->assertSame(2, $result->figureCount());
    }

    public function test_confidence_is_null_when_no_page_reports_one(): void
    {
        $result = new OcrResult('docling', [new OcrPage(1, 'a'), new OcrPage(2, 'b')]);

        $this->assertNull($result->meanConfidence());
        $this->assertNull($result->minConfidence());
        $this->assertSame(0, $result->figureCount());
    }

    public function test_figure_file_name_follows_the_page_index_shape(): void
    {
        $this->assertSame('fig-3-2.png', (new OcrFigure(3, 2, 'x'))->fileName());
        $this->assertSame('fig-1-1.jpg', (new OcrFigure(1, 1, 'x', 'jpg'))->fileName());
    }

    public function test_request_detects_pdf_from_mime_with_parameters(): void
    {
        $this->assertTrue((new OcrRequest('%PDF', 'application/pdf; charset=binary', 'a.pdf'))->isPdf());
        $this->assertFalse((new OcrRequest('x', 'image/png', 'a.png'))->isPdf());
    }
}
