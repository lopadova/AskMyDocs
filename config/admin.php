<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Admin — PDF export engine (Phase G4)
    |--------------------------------------------------------------------------
    |
    | Drives the selection inside {@see \App\Services\Admin\Pdf\PdfRendererFactory}.
    | Three values are supported:
    |
    |   - 'disabled'    — default. Every /export-pdf call returns 501.
    |   - 'dompdf'      — requires `dompdf/dompdf` (composer require).
    |   - 'browsershot' — requires `spatie/browsershot` + Node.js + Chromium.
    |
    | Neither driver is a hard dependency of this project; see the
    | composer.json "suggest" block for installation guidance. Leaving
    | the engine disabled is the correct default for CI and for operators
    | who do not expose the KB admin UI externally.
    |
    */
    'pdf_engine' => env('ADMIN_PDF_ENGINE', 'disabled'),
];
