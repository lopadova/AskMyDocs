<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Kb\Ocr;

use App\Services\Kb\Ocr\OcrFigureStore;
use Tests\TestCase;

/**
 * The assets key is the SAME key the ingest and the deleter resolve: prefix
 * + source through KbPath::normalize(), so a prefix with backslashes or
 * repeated separators never writes figures under a key cleanup will not find.
 */
final class OcrFigureStoreKeyTest extends TestCase
{
    public function test_assets_dir_normalises_the_prefix_with_the_source(): void
    {
        $store = app(OcrFigureStore::class);

        $this->assertSame('pre/fix/a/b.png.ocr', $store->assetsDirFor('a/b.png', 'pre\\fix//'));
        $this->assertSame('pre/fix/a/b.png.ocr', $store->assetsDirFor('a/b.png', '/pre/fix/'));
        $this->assertSame('a/b.png.ocr', $store->assetsDirFor('a//b.png', ''));
    }
}
