<?php

declare(strict_types=1);

namespace App\Services\Admin\Pdf;

use App\Models\KnowledgeDocument;

/**
 * PR11 / Phase G4 — PDF renderer strategy.
 *
 * Concrete implementations are picked up at runtime from `config('admin.pdf_engine')`:
 *   - 'disabled' (default) → {@see DisabledPdfRenderer}
 *   - 'dompdf'             → {@see DompdfPdfRenderer}
 *   - 'browsershot'        → {@see BrowsershotPdfRenderer}
 *
 * Neither Dompdf nor Browsershot is a hard dependency — each concrete
 * class guards the call site with `class_exists()` and falls back to
 * throwing {@see \App\Exceptions\PdfEngineDisabledException} when the
 * driver is not installed. This keeps the default `composer install`
 * footprint small (see composer.json "suggest" block).
 */
interface PdfRenderer
{
    /**
     * Render the provided markdown body for the given document into PDF bytes.
     *
     * @throws \App\Exceptions\PdfEngineDisabledException when the chosen
     *         engine is not available (config set to 'disabled' or the
     *         matching Composer package is missing).
     */
    public function render(KnowledgeDocument $doc, string $markdown): string;
}
