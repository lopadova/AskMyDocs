<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

final class EmailTriageEvaluation extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];
    protected $attributes = ['status' => 'waiting', 'attempts' => 0, 'index_status' => 'waiting', 'index_generation' => 0];
    protected $casts = ['result' => 'array', 'coverage' => 'array', 'tags' => 'array', 'usage' => 'array', 'indexed_at' => 'datetime', 'index_queued_at' => 'datetime', 'queued_at' => 'datetime', 'retry_at' => 'datetime'];
}
