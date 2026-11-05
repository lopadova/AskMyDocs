<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Small, content-free operational trace, retained with its owning run. */
final class AgentDiagnosticSpan extends Model
{
    use BelongsToTenant;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return [
            'metadata_json' => 'array',
            'started_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'duration_ms' => 'integer',
        ];
    }
}
