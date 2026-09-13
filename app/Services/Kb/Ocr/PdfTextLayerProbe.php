<?php

declare(strict_types=1);

namespace App\Services\Kb\Ocr;

use Smalot\PdfParser\Page;
use Smalot\PdfParser\Parser;
use Smalot\PdfParser\XObject\Image;
use Throwable;

/**
 * "Does this PDF have a usable text layer?" — decided PER PAGE (ADR 0029 §1).
 *
 * Every page inside the probe window is classified from what smalot reads:
 * `text` (at least `min_text_chars` non-whitespace characters), `scanned`
 * (below the threshold AND carrying an image XObject — a page that is a
 * picture of text) or `blank` (below the threshold, no image: a separator or
 * an empty page, never a reason to OCR by itself). The verdict follows:
 *
 *   - `present`  — every page that has content is a text page (blank pages
 *                  are ignored): today's text-layer path, byte for byte;
 *   - `mixed`    — text pages AND scanned pages: a textual cover over scanned
 *                  body pages, or scans stapled to a typed memo. The whole
 *                  document is routed to OCR so no page is silently lost;
 *   - `empty`    — no text page at all: a scan.
 *
 * The window is every page up to `KB_OCR_MAX_PAGES` (`KB_OCR_PROBE_PAGES=0`,
 * the default); a positive `KB_OCR_PROBE_PAGES` bounds it for very large
 * text PDFs, and is then a documented trade-off: a scanned page beyond the
 * window is not seen. A page over the OCR cap could not be OCR'd anyway.
 */
final class PdfTextLayerProbe
{
    public const EMPTY = 'empty';

    public const MIXED = 'mixed';

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
     * @return array{verdict: string, pages_probed: int, chars: int, pages_total: int, pages_exact: bool, text_pages: int, scanned_pages: list<int>}
     */
    public function probe(string $bytes): array
    {
        $window = (int) config('kb.ocr.text_layer_probe.pages', 0);
        $maxPages = max(1, (int) config('kb.ocr.max_pages', 200));
        $pagesToProbe = $window > 0 ? min($window, $maxPages) : $maxPages;
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
                'text_pages' => 0,
                'scanned_pages' => [],
            ];
        }

        $chars = 0;
        $probed = 0;
        $textPages = 0;
        $scanned = [];
        foreach ($pages as $index => $page) {
            if ($probed >= $pagesToProbe) {
                break;
            }
            $probed++;
            $number = (int) $index + 1;
            try {
                $text = preg_replace('/\s+/u', '', (string) $page->getText()) ?? '';
            } catch (Throwable) {
                $text = '';
            }
            $pageChars = mb_strlen($text);
            $chars += $pageChars;
            if ($pageChars >= $minChars && $minChars > 0) {
                $textPages++;

                continue;
            }
            if ($minChars === 0 && $pageChars > 0) {
                $textPages++;

                continue;
            }
            if ($this->carriesImage($page)) {
                $scanned[] = $number;
            }
        }

        $verdict = match (true) {
            $textPages === 0 => self::EMPTY,
            $scanned !== [] => self::MIXED,
            default => self::PRESENT,
        };

        return [
            'verdict' => $verdict,
            'pages_probed' => $probed,
            'chars' => $chars,
            'pages_total' => count($pages),
            'pages_exact' => true,
            'text_pages' => $textPages,
            'scanned_pages' => $scanned,
        ];
    }

    public function isTextless(string $bytes): bool
    {
        return $this->probe($bytes)['verdict'] !== self::PRESENT;
    }

    /** An image XObject on the page: a textless page that is a picture, not a blank. */
    private function carriesImage(Page $page): bool
    {
        try {
            foreach ($page->getXObjects() as $xobject) {
                if ($xobject instanceof Image) {
                    return true;
                }
                $header = method_exists($xobject, 'getHeader') ? $xobject->getHeader() : null;
                if ($header !== null && method_exists($header, 'get') && (string) $header->get('Subtype') === 'Image') {
                    return true;
                }
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }
}
