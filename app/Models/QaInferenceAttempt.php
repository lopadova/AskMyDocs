<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

final class QaInferenceAttempt extends Model
{
    use BelongsToTenant;

    public $timestamps = false;
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = ['id'];
    protected $casts = ['reserved_nanos' => 'integer', 'cost_nanos' => 'integer', 'caller_classes' => 'array', 'identity_json' => 'array', 'duration_ms' => 'integer',
        'started_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];
}
