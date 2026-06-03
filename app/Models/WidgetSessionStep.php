<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * WidgetSessionStep — uno step append-only del loop ReAct (vedi migration).
 *
 * R31: BelongsToTenant + tenant_id in $fillable. I payload JSON sono già
 * mascherati (WidgetPiiMasker) prima del salvataggio (M5).
 */
class WidgetSessionStep extends Model
{
    use BelongsToTenant;

    public const KIND_SNAPSHOT = 'snapshot';
    public const KIND_TOOL_CALL = 'tool_call';
    public const KIND_TOOL_RESULT = 'tool_result';
    public const KIND_USER_MESSAGE = 'user_message';
    public const KIND_BOT_MESSAGE = 'bot_message';

    protected $fillable = [
        'tenant_id',
        'widget_session_id',
        'step_index',
        'kind',
        'tool',
        'args_json',
        'diagnostic_json',
        'snapshot_in_json',
        'snapshot_out_json',
        'tokens_in',
        'tokens_out',
        'latency_ms',
        'idempotency_key',
    ];

    protected $casts = [
        'args_json' => 'array',
        'diagnostic_json' => 'array',
        'step_index' => 'integer',
        'tokens_in' => 'integer',
        'tokens_out' => 'integer',
        'latency_ms' => 'integer',
    ];

    /** @return BelongsTo<WidgetSession, WidgetSessionStep> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(WidgetSession::class, 'widget_session_id');
    }
}
