<?php

declare(strict_types=1);

namespace App\Services\Kb\Ocr;

use RuntimeException;

/**
 * The configured driver cannot run on this host (binary missing, API key
 * absent, provider unconfigured). Surfaces as a failed ingest job / a
 * `failed` upload item with this message — never an empty document (R14).
 */
final class OcrDriverUnavailableException extends RuntimeException {}
