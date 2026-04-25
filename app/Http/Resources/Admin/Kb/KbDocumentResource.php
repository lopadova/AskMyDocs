<?php

namespace App\Http\Resources\Admin\Kb;

use App\Models\KnowledgeDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * PR9 / Phase G2 — admin KB document detail payload.
 *
 * Mirrors the columns on `knowledge_documents` (canonical fields included)
 * plus cheap aggregate counts (chunks, audits) and top-20 recent audit
 * rows so the SPA can paint Preview / Meta / History tabs from a single
 * round-trip. The raw markdown body is NOT inlined here — the frontend
 * pulls `/raw` lazily when the Preview tab mounts to keep the list-side
 * query payload small.
 *
 * Deliberately omits any frontmatter-secret fields. The canonical YAML
 * stored in `frontmatter_json._derived` is already normalised by
 * CanonicalParser and contains no credentials by design; we still pass
 * it through verbatim only for canonical rows so the Meta tab can surface
 * `_derived` slug lists (supersedes / related / etc).
 *
 * @property-read KnowledgeDocument $resource
 */
class KbDocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var KnowledgeDocument $doc */
        $doc = $this->resource;

        // KbTag's columns are `slug` + `label` + `color` (no `name`),
        // so map exactly those — Copilot PR #33 caught the previous
        // `'name' => $t->name` mapping returning null on every tag.
        $tags = $doc->relationLoaded('tags')
            ? $doc->tags->map(static fn ($t) => [
                'id' => $t->id,
                'slug' => $t->slug,
                'label' => $t->label,
                'color' => $t->color,
            ])->values()->all()
            : [];

        $chunkCount = (int) ($this->additional['chunks_count'] ?? $doc->chunks()->count());
        $auditCount = (int) ($this->additional['audits_count'] ?? 0);
        $recentAudits = (array) ($this->additional['recent_audits'] ?? []);

        $metadata = is_array($doc->metadata) ? $doc->metadata : [];
        $metadataTags = isset($metadata['tags']) && is_array($metadata['tags'])
            ? array_values(array_filter($metadata['tags'], 'is_string'))
            : [];

        return [
            'id' => $doc->id,
            'project_key' => $doc->project_key,
            'source_type' => $doc->source_type,
            'title' => $doc->title,
            'source_path' => $doc->source_path,
            'mime_type' => $doc->mime_type,
            'language' => $doc->language,
            'access_scope' => $doc->access_scope,
            'status' => $doc->status,
            'document_hash' => $doc->document_hash,
            'version_hash' => $doc->version_hash,
            // --- canonical columns --------------------------------------------------
            'doc_id' => $doc->doc_id,
            'slug' => $doc->slug,
            'canonical_type' => $doc->canonical_type,
            'canonical_status' => $doc->canonical_status,
            'is_canonical' => (bool) $doc->is_canonical,
            'retrieval_priority' => $doc->retrieval_priority,
            'source_of_truth' => (bool) $doc->source_of_truth,
            'frontmatter' => $doc->is_canonical ? $doc->frontmatter_json : null,
            // --- timestamps + soft delete -------------------------------------------
            'source_updated_at' => optional($doc->source_updated_at)->toIso8601String(),
            'indexed_at' => optional($doc->indexed_at)->toIso8601String(),
            'created_at' => optional($doc->created_at)->toIso8601String(),
            'updated_at' => optional($doc->updated_at)->toIso8601String(),
            'deleted_at' => optional($doc->deleted_at)->toIso8601String(),
            // --- aggregates ---------------------------------------------------------
            'metadata_tags' => $metadataTags,
            'tags' => $tags,
            'chunks_count' => $chunkCount,
            'audits_count' => $auditCount,
            'recent_audits' => $recentAudits,
        ];
    }
}
