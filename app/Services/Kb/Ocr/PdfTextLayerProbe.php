<?php

declare(strict_types=1);

namespace App\Services\Kb\Ocr;

use Smalot\PdfParser\Parser;
use Throwable;

/**
 * Cheap "does this PDF have a text layer?" probe (ADR 0029 §1).
 *
 * Reads the first `kb.ocr.text_layer_probe.pages` pages with smalot and
 * counts non-whitespace characters. Below `min_text_chars` the PDF is
 * treated as scanned. Deliberately conservative: a PDF with even a thin
 * text layer keeps today's text-layer path byte for byte.
 */
final class PdfTextLayerProbe
{
    public const EMPTY = 'empty';

    public const PRESENT = 'present';

    public const UNREADABLE = 'unreadable';

    /**
     * `pages_total` is the page count of the whole document so callers that
     * need it — the page cap, the estimate — do not parse the PDF a second
     * time.
     *
     * When smalot cannot parse the file, `pages_total` is the `/Type /Page`
     * object count (a floor, `pages_exact = false`) so the page cap and the
     * estimate read ONE number and cannot disagree.
     *
     * @return array{verdict: string, pages_probed: int, chars: int, pages_total: int, pages_exact: bool}
     */
    public function probe(string $bytes): array
    {
        $pagesToProbe = max(1, (int) config('kb.ocr.text_layer_probe.pages', 3));
        $minChars = max(0, (int) config('kb.ocr.text_layer_probe.min_text_chars', 20));

        try {
            $pdf = (new Parser())->parseContent($bytes);
            $pages = $pdf->getPages();
        } catch (Throwable) {
            return [
                'verdict' => self::UNREADABLE,
                'pages_probed' => 0,
                'chars' => 0,
                'pages_total' => max(1, preg_match_all('#/Type\s*/Page(?![s])#', $bytes)),
                'pages_exact' => false,
            ];
        }

        $chars = 0;
        $probed = 0;
        foreach ($pages as $page) {
            if ($probed >= $pagesToProbe) {
                break;
            }
            $probed++;
            try {
                $text = preg_replace('/\s+/u', '', (string) $page->getText()) ?? '';
            } catch (Throwable) {
                $text = '';
            }
            $chars += mb_strlen($text);
        }

        return [
            'verdict' => $chars >= $minChars ? self::PRESENT : self::EMPTY,
            'pages_probed' => $probed,
            'chars' => $chars,
            'pages_total' => count($pages),
            'pages_exact' => true,
        ];
    }

    public function isTextless(string $bytes): bool
    {
        return $this->probe($bytes)['verdict'] !== self::PRESENT;
    }
}
