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
 * @property string $project_code
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
        'project_code',
        'source_doc_id',
        'weight',
        'provenance',
        'payload_json',
    ];

    protected $casts = [
        'weight' => 'float',
        'payload_json' => 'array',
    ];

    public function fromNode(): BelongsTo
    {
        return $this->belongsTo(KbNode::class, 'from_node_uid', 'node_uid');
    }

    public function toNode(): BelongsTo
    {
        return $this->belongsTo(KbNode::class, 'to_node_uid', 'node_uid');
    }

    public function scopeForProject(Builder $query, string $projectKey): Builder
    {
        return $query->where('project_code', $projectKey);
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
