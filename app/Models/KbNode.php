<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A node in the canonical knowledge graph.
 *
 * Created by {@see \App\Jobs\CanonicalIndexerJob} as a side effect of
 * ingesting a canonical markdown document. Multi-tenant scoped via
 * `project_code`. See ADR 0002.
 *
 * @property string $node_uid
 * @property string $node_type   One of the 9 {@see \App\Support\Canonical\CanonicalType} node labels
 * @property string $label       Human-readable title
 * @property string $project_code
 * @property string|null $source_doc_id  Stable doc_id of the canonical doc that "owns" this node
 * @property array|null $payload_json    Free-form metadata (e.g. ['dangling' => true] when target slug not yet canonicalized)
 */
class KbNode extends Model
{
    protected $table = 'kb_nodes';

    protected $fillable = [
        'node_uid',
        'node_type',
        'label',
        'project_code',
        'source_doc_id',
        'payload_json',
    ];

    protected $casts = [
        'payload_json' => 'array',
    ];

    public function outgoingEdges(): HasMany
    {
        return $this->hasMany(KbEdge::class, 'from_node_uid', 'node_uid');
    }

    public function incomingEdges(): HasMany
    {
        return $this->hasMany(KbEdge::class, 'to_node_uid', 'node_uid');
    }

    public function scopeForProject(Builder $query, string $projectKey): Builder
    {
        return $query->where('project_code', $projectKey);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('node_type', $type);
    }
}
