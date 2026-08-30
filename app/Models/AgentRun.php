<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class AgentRun extends Model
{
    use BelongsToTenant;

    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_WAITING_CLIENT_TOOL = 'waiting_client_tool';
    public const STATUS_AWAITING_CONFIRMATION = 'awaiting_confirmation';
    public const STATUS_AWAITING_MCP_CONFIRMATION = 'awaiting_mcp_confirmation';
    public const STATUS_AWAITING_MCP_INPUT = 'awaiting_mcp_input';
    public const STATUS_WAITING_MCP_TASK = 'waiting_mcp_task';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'run_id', 'tenant_id', 'project_key', 'user_id', 'conversation_id',
        'widget_identity_id', 'widget_session_id', 'channel', 'actor_type',
        'actor_id', 'locale', 'timezone', 'status', 'input_json', 'plan_json',
        'budget_json', 'counters_json', 'result_json', 'error_code',
        'last_sequence', 'started_at', 'completed_at', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'input_json' => 'array',
            'plan_json' => 'array',
            'budget_json' => 'array',
            'counters_json' => 'array',
            'result_json' => 'array',
            'last_sequence' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'run_id';
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function conversation(): BelongsTo { return $this->belongsTo(Conversation::class); }
    public function widgetIdentity(): BelongsTo { return $this->belongsTo(WidgetIdentity::class); }
    public function widgetSession(): BelongsTo { return $this->belongsTo(WidgetSession::class); }
    public function events(): HasMany { return $this->hasMany(AgentRunEvent::class); }
    public function toolExecutions(): HasMany { return $this->hasMany(AgentToolExecution::class); }
    public function plannerShadowReports(): HasMany { return $this->hasMany(AgentPlannerShadowReport::class); }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_COMPLETED,
            self::STATUS_PARTIAL,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
        ], true);
    }
}
