<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Logs;

use App\Models\KbCanonicalAudit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Phase H1 — admin Log Viewer, canonical-audit tab.
 *
 * Duplicates the KbAuditResource shape used by the KB detail History tab,
 * but lives under `Admin\Logs` so adding a log-only field (e.g. cluster
 * node id) later doesn't ripple into the KB detail view.
 *
 * `kb_canonical_audit` rows are immutable (no updated_at) and survive
 * hard-deletes of their parent document by design (forensic trail,
 * CLAUDE.md §4).
 *
 * @property-read KbCanonicalAudit $resource
 */
class AuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var KbCanonicalAudit $audit */
        $audit = $this->resource;

        return [
            'id' => $audit->id,
            'project_key' => $audit->project_key,
            'doc_id' => $audit->doc_id,
            'slug' => $audit->slug,
            'event_type' => $audit->event_type,
            'actor' => $audit->actor,
            'before_json' => $audit->before_json,
            'after_json' => $audit->after_json,
            'metadata_json' => $audit->metadata_json,
            'created_at' => optional($audit->created_at)->toIso8601String(),
        ];
    }
}
