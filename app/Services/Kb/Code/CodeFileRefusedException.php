<?php

declare(strict_types=1);

namespace App\Services\Kb\Code;

use RuntimeException;

/**
 * A code file reached the ingest saga but the admission policy refuses it
 * (deny-listed path, over the size cap, generated, binary). Deterministic —
 * a retry cannot change the verdict — so {@see \App\Jobs\IngestDocumentJob}
 * fails the job at once instead of burning its retries. The message is the
 * stable reason label, never file content.
 */
final class CodeFileRefusedException extends RuntimeException {}
