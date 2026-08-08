<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AgentRunEvent extends Model
{
    protected $fillable = [
        'agent_run_id', 'sequence', 'type', 'phase', 'locale', 'message_key',
        'message_params', 'message', 'payload_json',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'message_params' => 'array',
            'payload_json' => 'array',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'agent_run_id');
    }
}
