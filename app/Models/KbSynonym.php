<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * v8.7/W1 — a per-(tenant, project) synonym group.
 *
 * `term` is the lowercased anchor; `synonyms` is a list of lowercased
 * equivalents. The group is treated as a bidirectional equivalence set
 * by {@see App\Services\Kb\Retrieval\SynonymExpander}.
 *
 * @property string $tenant_id
 * @property string $project_key
 * @property string $term
 * @property list<string> $synonyms
 * @property bool $enabled
 */
class KbSynonym extends Model
{
    use BelongsToTenant;

    protected $table = 'kb_synonyms';

    protected $fillable = [
        'tenant_id',
        'project_key',
        'term',
        'synonyms',
        'enabled',
    ];

    protected $casts = [
        'synonyms' => 'array',
        'enabled' => 'boolean',
    ];
}
