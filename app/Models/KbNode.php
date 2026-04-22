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
 * @property string $project_key Tenant scope — same convention as knowledge_documents.project_key
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
        'project_key',
        'source_doc_id',
        'payload_json',
    ];

    protected $casts = [
        'payload_json' => 'array',
    ];

    /**
     * Edges where THIS node is the `from` endpoint.
     *
     * Scoped by the ATTRIBUTE of this node — generates `WHERE from_node_uid=<uid>
     * AND project_key=<key>` in the relation query. Works for both lazy
     * (`$node->outgoingEdges`) and eager loading within a single project's
     * query scope. The composite FK at the DB level is the real integrity
     * guarantee; this scoping ensures Eloquent never returns a row from a
     * different tenant even if raw data bypassed the FK.
     */
    public function outgoingEdges(): HasMany
    {
        return $this->hasMany(KbEdge::class, 'from_node_uid', 'node_uid')
            ->where('kb_edges.project_key', $this->project_key);
    }

    /**
     * Edges where THIS node is the `to` endpoint (same tenant scope).
     */
    public function incomingEdges(): HasMany
    {
        return $this->hasMany(KbEdge::class, 'to_node_uid', 'node_uid')
            ->where('kb_edges.project_key', $this->project_key);
    }

    public function scopeForProject(Builder $query, string $projectKey): Builder
    {
        return $query->where('project_key', $projectKey);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('node_type', $type);
    }
}
