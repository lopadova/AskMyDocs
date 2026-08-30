<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AgentPlannerShadowReport extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'agent_run_id', 'iteration', 'tenant_id', 'project_key', 'mode', 'status',
        'capability_hash', 'capability_count', 'capability_bytes', 'candidate_tools_json',
        'route_json', 'classic_plan_json', 'capability_plan_json', 'comparison_json',
        'router_latency_ms', 'planner_latency_ms', 'prompt_tokens', 'completion_tokens',
        'fallback_used', 'error_code',
    ];

    protected function casts(): array
    {
        return [
            'iteration' => 'integer',
            'capability_count' => 'integer',
            'capability_bytes' => 'integer',
            'candidate_tools_json' => 'array',
            'route_json' => 'array',
            'classic_plan_json' => 'array',
            'capability_plan_json' => 'array',
            'comparison_json' => 'array',
            'router_latency_ms' => 'integer',
            'planner_latency_ms' => 'integer',
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'fallback_used' => 'boolean',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'agent_run_id');
    }
}
