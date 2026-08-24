<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AgentToolExecution extends Model
{
    protected $fillable = [
        'agent_run_id', 'logical_index', 'tool_name', 'tool_kind', 'api_route_id',
        'status', 'depends_on_json', 'arguments_json', 'result_meta_json',
        'error_code', 'physical_request_count', 'latency_ms', 'started_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'logical_index' => 'integer',
            'api_route_id' => 'integer',
            'depends_on_json' => 'array',
            'arguments_json' => 'array',
            'result_meta_json' => 'array',
            'physical_request_count' => 'integer',
            'latency_ms' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'agent_run_id');
    }
}
