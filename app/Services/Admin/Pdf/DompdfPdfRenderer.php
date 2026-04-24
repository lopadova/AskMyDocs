<?php

declare(strict_types=1);

namespace App\Services\Admin\Pdf;

use App\Exceptions\PdfEngineDisabledException;
use App\Models\KnowledgeDocument;
use Illuminate\Support\Facades\View;

/**
 * PR11 / Phase G4 — Dompdf-backed renderer.
 *
 * Guarded with `class_exists('Dompdf\\Dompdf')` so the framework
 * boots even without the optional `dompdf/dompdf` package installed
 * (listed under composer.json "suggest"). When the class is missing
 * we fall through to {@see PdfEngineDisabledException} so the caller
 * sees the same 501 message as the default disabled engine — the
 * operator needs to run `composer require dompdf/dompdf` to enable it.
 *
 * Renders the same `print.kb-doc` Blade view as G2's printable()
 * handler, then hands the HTML to Dompdf. The print Blade carries its
 * own `@page` + monospace-body styling so the PDF layout matches the
 * print-to-PDF flow operators already know from the browser.
 */
final class DompdfPdfRenderer implements PdfRenderer
{
    public function render(KnowledgeDocument $doc, string $markdown): string
    {
        if (! class_exists('Dompdf\\Dompdf')) {
            throw new PdfEngineDisabledException(
                'PDF export disabled — the `dompdf/dompdf` package is not installed. Run `composer require dompdf/dompdf` to enable the Dompdf engine.',
            );
        }

        $html = View::make('print.kb-doc', [
            'document' => $doc,
            'body' => $markdown,
        ])->render();

        /** @var object $dompdf */
        $dompdf = new \Dompdf\Dompdf([
            'isRemoteEnabled' => false,
            'defaultFont' => 'Helvetica',
        ]);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $output = $dompdf->output();

        return is_string($output) ? $output : '';
    }
}
