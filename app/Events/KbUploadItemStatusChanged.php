<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\KbIngestBatchItem;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Emitted every time a {@see KbIngestBatchItem} changes status, from the one
 * mutation point ({@see \App\Services\Kb\Upload\KbUploadStagingService::transitionItem()}).
 *
 * Today this is a NO-OP seam: there is no listener and it does NOT implement
 * `ShouldBroadcast`, so the source of truth for the upload modal stays
 * DB-backed polling (`GET /api/admin/kb/uploads/{batch}/status`).
 *
 * Phase 2 (Reverb): implement `ShouldBroadcast` here and add a private channel
 * `tenant.{tenant_id}.kb-upload.{batch_id}` — zero refactor elsewhere, because
 * every transition already emits this event.
 */
final class KbUploadItemStatusChanged
{
    use Dispatchable;

    public function __construct(public readonly KbIngestBatchItem $item)
    {
    }
}
