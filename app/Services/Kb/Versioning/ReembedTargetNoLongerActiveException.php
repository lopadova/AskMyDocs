<?php

declare(strict_types=1);

namespace App\Services\Kb\Versioning;

use RuntimeException;

/**
 * PR #479 Copilot round-50 — `ReembedDocumentJob` reads its row as `active`,
 * then spends unlocked time (a disk read, chunking, embedding) before ever
 * writing. A concurrent soft or hard delete in that window is otherwise
 * non-durable: `DocumentIngestor::persistDocumentAndChunks()`'s own
 * `findExistingVersion()` + `restoreIfTrashed()` (needed so an ORDINARY
 * identical re-ingest un-deletes a document whose source still exists, R2)
 * would silently resurrect a soft-deleted row, or `updateOrCreate()` would
 * mint a fresh one for a row that was hard-deleted.
 *
 * Thrown from inside the SAME transaction that would otherwise persist the
 * write, right after a `lockForUpdate()` re-check finds the document no
 * longer active — so a delete racing the read below serializes on the row
 * lock instead of losing to it either way (whichever transaction reaches the
 * row first wins; the second sees the first's outcome). The caller (only
 * {@see \App\Jobs\ReembedDocumentJob}) treats this as an expected, logged
 * skip, never a job failure/retry: the delete is the more recent, deliberate
 * action and stays durable.
 */
final class ReembedTargetNoLongerActiveException extends RuntimeException
{
    public function __construct(
        public readonly int $documentId,
    ) {
        parent::__construct(sprintf(
            'Document #%d is no longer active (deleted or archived since the re-embed job read it); the re-embed is skipped rather than resurrecting or recreating it.',
            $documentId,
        ));
    }
}
