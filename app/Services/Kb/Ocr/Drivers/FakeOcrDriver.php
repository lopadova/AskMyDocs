<?php

declare(strict_types=1);

namespace App\Services\Kb\Ocr\Drivers;

use App\Services\Kb\Ocr\OcrDriver;
use App\Services\Kb\Ocr\OcrFigure;
use App\Services\Kb\Ocr\OcrMeteringMode;
use App\Services\Kb\Ocr\OcrPage;
use App\Services\Kb\Ocr\OcrRequest;
use App\Services\Kb\Ocr\OcrResult;

/**
 * Deterministic OCR driver for tests and the E2E harness (ADR 0029).
 *
 * Output is driven by `kb.ocr.fake.pages` when set (a list of
 * `{markdown, confidence, figures}` entries), otherwise one synthetic page
 * derived from the request so assertions can key on the filename. Never
 * resolvable in production (see OcrDriverRegistry).
 */
final class FakeOcrDriver implements OcrDriver
{
    /** 1×1 transparent PNG — a real, decodable image for figure assertions. */
    public const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    public function name(): string
    {
        return 'fake';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function isRemote(): bool
    {
        return false;
    }

    public function fingerprint(): string
    {
        return 'fake';
    }

    public function meteringMode(): OcrMeteringMode
    {
        return OcrMeteringMode::PerPage;
    }

    public function recognise(OcrRequest $request): OcrResult
    {
        $configured = config('kb.ocr.fake.pages');
        $pages = [];

        if (is_array($configured) && $configured !== []) {
            foreach (array_values($configured) as $i => $spec) {
                $spec = is_array($spec) ? $spec : ['markdown' => (string) $spec];
                $number = $i + 1;
                $figures = [];
                $count = max(0, (int) ($spec['figures'] ?? 0));
                for ($n = 1; $n <= $count; $n++) {
                    $figures[] = new OcrFigure($number, $n, (string) base64_decode(self::PNG_1X1, true));
                }
                $pages[] = new OcrPage(
                    number: $number,
                    markdown: (string) ($spec['markdown'] ?? ''),
                    confidence: array_key_exists('confidence', $spec) && $spec['confidence'] !== null
                        ? (float) $spec['confidence']
                        : null,
                    figures: $figures,
                );
            }
        } else {
            $pages[] = new OcrPage(
                number: 1,
                markdown: "Fake OCR output of {$request->filename}.",
                confidence: 0.9,
                figures: [new OcrFigure(1, 1, (string) base64_decode(self::PNG_1X1, true))],
            );
        }

        return new OcrResult(driver: $this->name(), pages: $pages, meta: ['engine' => 'fake']);
    }
}
