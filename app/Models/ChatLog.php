<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatLog extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'session_id',
        'user_id',
        'question',
        'answer',
        'project_key',
        'ai_provider',
        'ai_model',
        'chunks_count',
        'sources',
        'prompt_tokens',
        'completion_tokens',
        'total_tokens',
        'latency_ms',
        'client_ip',
        'user_agent',
        'extra',
        'created_at',
    ];

    protected $casts = [
        'sources' => 'array',
        'extra' => 'array',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model', 'App\\Models\\User'));
    }
}
