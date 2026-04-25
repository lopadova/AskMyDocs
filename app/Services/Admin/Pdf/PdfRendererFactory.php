<?php

declare(strict_types=1);

namespace App\Services\Admin\Pdf;

/**
 * PR11 / Phase G4 — resolves the configured {@see PdfRenderer}.
 *
 * Bound into the container through `AppServiceProvider::register()` so
 * controllers can type-hint `PdfRenderer` and let Laravel inject the
 * right concrete class. Keeping the resolution in a tiny factory (vs
 * an inline closure) makes it trivial to unit-test the selection logic
 * without touching the container.
 *
 * Default is the safe `disabled` engine — nothing renders until an
 * operator sets ADMIN_PDF_ENGINE=dompdf or ADMIN_PDF_ENGINE=browsershot
 * AND installs the matching Composer package.
 */
final class PdfRendererFactory
{
    public static function resolve(?string $engine = null): PdfRenderer
    {
        $engine ??= (string) config('admin.pdf_engine', 'disabled');

        return match ($engine) {
            'browsershot' => new BrowsershotPdfRenderer(),
            'dompdf' => new DompdfPdfRenderer(),
            default => new DisabledPdfRenderer(),
        };
    }
}
