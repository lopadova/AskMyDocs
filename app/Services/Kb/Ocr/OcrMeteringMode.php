<?php

declare(strict_types=1);

namespace App\Services\Kb\Ocr;

enum OcrMeteringMode: string
{
    /** Priced locally: pages × `kb.ocr.rate_per_page`, recorded by OcrCallMeter. */
    case PerPage = 'per_page';

    /** Priced by the laravel/ai lifecycle hook (tokens); OcrCallMeter skips it. */
    case Sdk = 'sdk';
}
