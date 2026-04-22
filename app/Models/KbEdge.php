<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A typed, weighted edge between two canonical nodes.
 *
 * Created by {@see \App\Jobs\CanonicalIndexerJob} from `[[wikilink]]` tokens
 * in canonical markdown bodies (provenance = 'wikilink') or from frontmatter
 * `related` / `supersedes` arrays (provenance = 'frontmatter_*'). See ADR 0002.
 *
 * @property string $edge_uid
 * @property string $from_node_uid
 * @property string $to_node_uid
 * @property string $edge_type    One of the 10 {@see \App\Support\Canonical\EdgeType} values
 * @property string $project_key  Tenant scope — same convention as knowledge_documents.project_key
 * @property string|null $source_doc_id
 * @property float $weight
 * @property string $provenance   wikilink | frontmatter_related | frontmatter_supersedes | inferred
 * @property array|null $payload_json
 */
class KbEdge extends Model
{
    protected $table = 'kb_edges';

    protected $fillable = [
        'edge_uid',
        'from_node_uid',
        'to_node_uid',
        'edge_type',
        'project_key',
        'source_doc_id',
        'weight',
        'provenance',
        'payload_json',
    ];

    protected $casts = [
        'weight' => 'float',
        'payload_json' => 'array',
    ];

    /**
     * From-endpoint. Scoped by THIS edge's `project_key` so the lookup
     * picks the correct row when the same `node_uid` slug exists in
     * multiple projects (legitimate under tenant-scoped uniqueness).
     *
     * The DB composite FK (project_key, from_node_uid) → (project_key,
     * node_uid) is the source-of-truth guarantee; this scoping mirrors
     * it at the Eloquent layer.
     */
    public function fromNode(): BelongsTo
    {
        return $this->belongsTo(KbNode::class, 'from_node_uid', 'node_uid')
            ->where('kb_nodes.project_key', $this->project_key);
    }

    public function toNode(): BelongsTo
    {
        return $this->belongsTo(KbNode::class, 'to_node_uid', 'node_uid')
            ->where('kb_nodes.project_key', $this->project_key);
    }

    public function scopeForProject(Builder $query, string $projectKey): Builder
    {
        return $query->where('project_key', $projectKey);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('edge_type', $type);
    }

    public function scopeOfTypes(Builder $query, array $types): Builder
    {
        return $query->whereIn('edge_type', $types);
    }
}
