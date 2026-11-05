<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A fine-tuning run of the expansion model (W7): dataset export or provider job, and the candidate model id the provider returned. Promotion is human-gated.
 *
 * Tenant-aware (R30/R31): read and written only through
 * {@see \App\Services\Kb\Retrieval\Expansion\Learning\ExpansionLearningService}
 * and {@see \App\Services\Kb\Retrieval\Expansion\Learning\ExpansionSignalRecorder}.
 */
class KbExpansionModel extends Model
{
    use BelongsToTenant;

    protected $table = 'kb_expansion_models';

    protected $fillable = ['tenant_id', 'project_key','backend','base_model','dataset_path','dataset_rows','provider_file_id','provider_job_id','model_id','status','failure_reason','gate','created_by','promoted_by','promoted_at','retired_by','retired_at'];

    protected $casts = ['gate' => 'array', 'dataset_rows' => 'integer', 'promoted_at' => 'datetime', 'retired_at' => 'datetime'];
}
