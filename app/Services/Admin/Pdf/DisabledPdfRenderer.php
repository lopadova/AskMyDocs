<?php

declare(strict_types=1);

namespace App\Services\Admin\Pdf;

use App\Exceptions\PdfEngineDisabledException;
use App\Models\KnowledgeDocument;

/**
 * PR11 / Phase G4 — default "no engine" renderer.
 *
 * Acts as the safe default when ADMIN_PDF_ENGINE is unset or set to
 * 'disabled'. Calling {@see self::render()} throws a 501 HttpException
 * ({@see PdfEngineDisabledException}) so the controller can surface
 * the honest message "enable ADMIN_PDF_ENGINE and install the driver".
 */
final class DisabledPdfRenderer implements PdfRenderer
{
    public function render(KnowledgeDocument $doc, string $markdown): string
    {
        throw new PdfEngineDisabledException();
    }
}
