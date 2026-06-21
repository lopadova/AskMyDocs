<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\KbIngestBatchItem;
use App\Models\KnowledgeDocument;

/**
 * Links an ingested document back to the upload-batch item that produced it.
 *
 * The upload commit dispatches IngestDocumentJob with
 * `metadata['kb_upload_batch_item_id']`, which DocumentIngestor merges into
 * `knowledge_documents.metadata`. On `created` we stamp the item's
 * `knowledge_document_id` so the UI can deep-link to the new doc.
 *
 * Success STATUS is owned by the queue-event listener
 * {@see \App\Listeners\KbUploadBatchItemProgress} (JobProcessed) — that fires
 * even on an idempotent no-op re-ingest where no `created` event happens. This
 * observer only fills the doc id (best-effort), so it never competes with the
 * status lifecycle. No-op for every non-upload ingestion (the metadata key is
 * absent).
 */
final class KnowledgeDocumentUploadObserver
{
    public function created(KnowledgeDocument $document): void
    {
        $meta = $document->metadata;
        $itemId = is_array($meta) ? ($meta['kb_upload_batch_item_id'] ?? null) : null;
        if (! is_string($itemId) || $itemId === '') {
            return;
        }

        // R30 — scope by the document's tenant; the uuid is globally unique so
        // this is defence in depth, not a correctness requirement.
        $item = KbIngestBatchItem::query()
            ->where('tenant_id', $document->tenant_id)
            ->whereKey($itemId)
            ->first();

        if ($item === null || $item->knowledge_document_id !== null) {
            return;
        }

        $item->forceFill(['knowledge_document_id' => $document->id])->save();
    }
}
