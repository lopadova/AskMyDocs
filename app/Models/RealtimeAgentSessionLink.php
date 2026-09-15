<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class RealtimeAgentSessionLink extends Model
{
    use BelongsToTenant;

    protected $primaryKey = 'session_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'session_id',
        'tenant_id',
        'user_id',
        'conversation_id',
        'filters',
        'live_sources',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'live_sources' => 'array',
            'expires_at' => 'immutable_datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
