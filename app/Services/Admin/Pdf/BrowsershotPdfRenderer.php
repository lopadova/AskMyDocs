<?php

declare(strict_types=1);

namespace App\Services\Admin\Pdf;

use App\Exceptions\PdfEngineDisabledException;
use App\Models\KnowledgeDocument;
use Illuminate\Support\Facades\View;

/**
 * PR11 / Phase G4 — Browsershot-backed renderer.
 *
 * Guarded with `class_exists('Spatie\\Browsershot\\Browsershot')` so
 * the framework boots even without the optional `spatie/browsershot`
 * package installed (listed under composer.json "suggest"). When the
 * class is missing we fall through to {@see PdfEngineDisabledException}
 * so the operator gets an actionable 501.
 *
 * Browsershot spawns headless Chromium + a Node.js runtime; it is the
 * high-fidelity engine of the two (handles complex CSS, webfonts, SVG)
 * but introduces native dependencies Dompdf does not. See README.
 */
final class BrowsershotPdfRenderer implements PdfRenderer
{
    public function render(KnowledgeDocument $doc, string $markdown): string
    {
        if (! class_exists('Spatie\\Browsershot\\Browsershot')) {
            throw new PdfEngineDisabledException(
                'PDF export disabled — the `spatie/browsershot` package is not installed. Run `composer require spatie/browsershot` (and ensure Node.js + Chromium are available) to enable the Browsershot engine.',
            );
        }

        $html = View::make('print.kb-doc', [
            'document' => $doc,
            'body' => $markdown,
        ])->render();

        /** @var object $browsershot */
        $browsershot = \Spatie\Browsershot\Browsershot::html($html)
            ->format('A4')
            ->margins(18, 16, 18, 16)
            ->showBackground();

        $bytes = $browsershot->pdf();

        return is_string($bytes) ? $bytes : '';
    }
}
