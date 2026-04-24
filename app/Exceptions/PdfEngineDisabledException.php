<?php

declare(strict_types=1);

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * PR11 / Phase G4 — PDF export disabled.
 *
 * Thrown by {@see \App\Services\Admin\Pdf\PdfRenderer} implementations
 * when either:
 *   - the operator has not opted into a concrete engine
 *     (`ADMIN_PDF_ENGINE=disabled`, the default), or
 *   - the operator configured an engine but the matching Composer
 *     package is not installed (Dompdf / Browsershot).
 *
 * 501 Not Implemented is the honest status code here: the SERVER knows
 * the feature exists, but the operator hasn't wired it up. 501 lets the
 * SPA distinguish a config-level "enable me" from a runtime 500.
 */
class PdfEngineDisabledException extends HttpException
{
    public function __construct(string $message = 'PDF export disabled — set ADMIN_PDF_ENGINE and install the chosen driver', ?\Throwable $previous = null)
    {
        parent::__construct(501, $message, $previous);
    }
}
