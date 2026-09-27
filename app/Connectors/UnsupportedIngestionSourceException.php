<?php

declare(strict_types=1);

namespace App\Connectors;

use RuntimeException;

/**
 * Raised before a connector source reaches the asynchronous ingestion queue.
 *
 * The exception deliberately carries no source bytes or unredacted path: the
 * caller may surface it in a sync diagnostic without disclosing mail content.
 */
final class UnsupportedIngestionSourceException extends RuntimeException {}
