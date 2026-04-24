<?php

namespace App\Http\Resources\Admin\Kb;

use App\Models\KbCanonicalAudit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * PR9 / Phase G2 — single `kb_canonical_audit` row shape for the
 * History tab. Keeps the event + actor + json diff payload; the
 * detail view renders `before_json` / `after_json` as a tree so the
 * editorial trail is auditable without a separate drill-down.
 *
 * `kb_canonical_audit` has no `updated_at` by design — rows are
 * immutable (see CLAUDE.md §4), so we only expose `created_at`.
 *
 * @property-read KbCanonicalAudit $resource
 */
class KbAuditResource extends JsonResource
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
