<?php

declare(strict_types=1);

namespace App\Services\Admin;

/**
 * Outcome of a {@see KbZipExporter::export()} run.
 *
 * `$tmpPath` is null when nothing was exportable — the HTTP layer maps
 * that to 404 so an empty archive can never ship under 200 (R14). The
 * caller owns the temp file's lifecycle (download responses delete it
 * via deleteFileAfterSend).
 */
final class ZipExportResult
{
    /**
     * @param  list<array{id: int, path: ?string, reason: string}>  $skipped
     */
    public function __construct(
        public readonly ?string $tmpPath,
        public readonly int $includedCount,
        public readonly array $skipped,
    ) {}
}
