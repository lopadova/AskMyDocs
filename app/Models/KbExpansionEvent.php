<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * One typed expansion that ran (W7): the PII-masked query, its variants and what each variant type contributed to the final ranking — the learning signal. Never holds raw user text.
 *
 * Tenant-aware (R30/R31): read and written only through
 * {@see \App\Services\Kb\Retrieval\Expansion\Learning\ExpansionLearningService}
 * and {@see \App\Services\Kb\Retrieval\Expansion\Learning\ExpansionSignalRecorder}.
 */
class KbExpansionEvent extends Model
{
    use BelongsToTenant;

    protected $table = 'kb_expansion_events';

    protected $fillable = ['tenant_id', 'project_key','query_hash','masked_query','source','expansion','list_stats','top_chunk_ids','refused','feedback','feedback_by'];

    protected $casts = ['expansion' => 'array', 'list_stats' => 'array', 'top_chunk_ids' => 'array', 'refused' => 'boolean', 'feedback' => 'integer'];
}
