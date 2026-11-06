<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A tuning proposal derived from expansion events (W7): per-type list weights + curated few-shot examples, the benchmark gate result and the human decision.
 *
 * Tenant-aware (R30/R31): read and written only through
 * {@see \App\Services\Kb\Retrieval\Expansion\Learning\ExpansionLearningService}
 * and {@see \App\Services\Kb\Retrieval\Expansion\Learning\ExpansionSignalRecorder}.
 */
class KbExpansionTuning extends Model
{
    use BelongsToTenant;

    protected $table = 'kb_expansion_tunings';

    protected $fillable = ['tenant_id', 'project_key','status','weights','few_shot','evidence','gate','proposed_by','decided_by','decided_at'];

    protected $casts = ['weights' => 'array', 'few_shot' => 'array', 'evidence' => 'array', 'gate' => 'array', 'decided_at' => 'datetime'];
}
