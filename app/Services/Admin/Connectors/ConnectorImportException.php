<?php

declare(strict_types=1);

namespace App\Services\Admin\Connectors;

use RuntimeException;

/**
 * Thrown when an uploaded connector-config file cannot be parsed into a valid,
 * importable prefill — a missing / wrong envelope, or a connector mismatch. The
 * controller maps it to **422** with the reason (R14 — an explicit rejection the
 * FE shows, never a silent empty prefill). Distinct from a NotFoundHttpException
 * (unknown connector → 404).
 */
final class ConnectorImportException extends RuntimeException {}
