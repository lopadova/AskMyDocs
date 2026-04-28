<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Immutable editorial audit trail. One row per canonical event.
 *
 * Rows are never updated (no updated_at). No FK to `knowledge_documents` so
 * rows survive hard deletion of the documents they describe — this is
 * intentional: the audit trail is the forensic record of what *used to* exist.
 *
 * Event types:
 *   - promoted               : a canonical draft was promoted to accepted status
 *   - updated                : an accepted doc's content or status changed
 *   - deprecated             : status transitioned to deprecated / archived
 *   - superseded             : a supersedes chain was established
 *   - rejected_injection_used: a rejected-approach doc was injected into a chat prompt
 *   - graph_rebuild          : kb:rebuild-graph was run for this project
 *
 * @property string $project_key
 * @property string|null $doc_id
 * @property string|null $slug
 * @property string $event_type
 * @property string $actor
 * @property array|null $before_json
 * @property array|null $after_json
 * @property array|null $metadata_json
 */
class KbCanonicalAudit extends Model
{
    use BelongsToTenant;

    protected $table = 'kb_canonical_audit';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'project_key',
        'doc_id',
        'slug',
        'event_type',
        'actor',
        'before_json',
        'after_json',
        'metadata_json',
        'created_at',
    ];

    protected $casts = [
        'before_json' => 'array',
        'after_json' => 'array',
        'metadata_json' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $audit) {
            if (empty($audit->created_at)) {
                $audit->created_at = now();
            }
        });
    }

    public function scopeForProject(Builder $query, string $projectKey): Builder
    {
        return $query->where('project_key', $projectKey);
    }

    public function scopeOfEvent(Builder $query, string $eventType): Builder
    {
        return $query->where('event_type', $eventType);
    }
}
